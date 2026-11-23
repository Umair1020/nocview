<?php
// File: parallel_routers.php
// Runs one job per router at the same time, each in its own PHP child process
// (router_worker.php). The job is passed on stdin, so passwords never show in the process list.
//
// Child output goes to temp files (not pipes): stream_select() does not work on
// proc_open pipes on Windows, so the pipe version never collected any result there.
// Files + proc_get_status() work the same on Windows (XAMPP) and Linux.
//
// Return value:
//   null                 -> child processes are not available here; caller polls in-process
//   [key => array]       -> job finished, result attached
//   [key => false]       -> job did not finish before the timeout
//   key missing          -> child could not be started or returned bad output; caller polls in-process

function routerWorkerPhpBinary() {
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $exe = $isWin ? 'php.exe' : 'php';
    $candidates = [];

    $env = getenv('PHP_CLI_BINARY');
    if ($env) {
        $candidates[] = $env;
    }
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = PHP_BINARY;   // under Apache this is httpd.exe; filtered out below
    }
    // Apache module: find php(.exe) next to the loaded php.ini or in PHP_BINDIR
    $ini = php_ini_loaded_file();
    if ($ini) {
        $candidates[] = dirname($ini) . DIRECTORY_SEPARATOR . $exe;
    }
    if (defined('PHP_BINDIR') && PHP_BINDIR) {
        $candidates[] = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . $exe;
    }
    if ($isWin) {
        $candidates[] = 'C:\\xampp\\php\\php.exe';
    }

    foreach ($candidates as $bin) {
        // only a real CLI binary: php, php8.3, php.exe (not httpd, php-fpm, lsphp, php-cgi)
        if (preg_match('#[/\\\\]php(\d+(\.\d+)?)?(\.exe)?$#i', $bin) && is_file($bin)
            && ($isWin || is_executable($bin))) {
            return $bin;
        }
    }
    return null;
}

function runRouterJobsParallel(array $jobs, $timeoutSec = 10.0) {
    if (empty($jobs)) {
        return [];
    }
    if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('proc_open', $disabled, true)) {
        return null;
    }
    $bin = routerWorkerPhpBinary();
    if ($bin === null) {
        return null;
    }
    $script = __DIR__ . '/router_worker.php';
    $tmpDir = sys_get_temp_dir();

    $running = [];
    foreach ($jobs as $key => $job) {
        $outFile = tempnam($tmpDir, 'nvw');
        $errFile = tempnam($tmpDir, 'nve');
        if ($outFile === false || $errFile === false) {
            continue;
        }
        $pipes = [];
        $proc = @proc_open([$bin, $script], [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ], $pipes, __DIR__, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($outFile);
            @unlink($errFile);
            continue;
        }
        fwrite($pipes[0], json_encode($job));
        fclose($pipes[0]);
        $running[$key] = ['proc' => $proc, 'out' => $outFile, 'err' => $errFile];
    }

    $results = [];
    $deadline = microtime(true) + $timeoutSec;

    while (!empty($running)) {
        foreach ($running as $key => $p) {
            $st = proc_get_status($p['proc']);
            if ($st['running']) {
                continue;
            }
            proc_close($p['proc']);
            $decoded = json_decode((string)@file_get_contents($p['out']), true);
            if (is_array($decoded)) {
                $results[$key] = $decoded;
            } else {
                $err = (string)@file_get_contents($p['err']);
                if ($err !== '') {
                    error_log('router_worker ' . $key . ': ' . substr($err, 0, 500));
                }
            }
            @unlink($p['out']);
            @unlink($p['err']);
            unset($running[$key]);
        }
        if (empty($running)) {
            break;
        }
        if (microtime(true) >= $deadline) {
            // Out of time: kill what is still running. These jobs count as timed out.
            foreach ($running as $key => $p) {
                @proc_terminate($p['proc'], 9);
                @proc_close($p['proc']);
                @unlink($p['out']);
                @unlink($p['err']);
                $results[$key] = false;
            }
            break;
        }
        usleep(50000);
    }

    return $results;
}
