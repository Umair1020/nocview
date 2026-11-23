<?php
// File: collector_common.php
// Small helpers shared by the always-on collectors (snmp_pusher.php,
// collect_bandwidth.php): CLI option parsing, logging with rotation, heartbeat
// files, single-instance locks and drift-free sleep.
//
// A "heartbeat" is a JSON file the collector rewrites after every pass. The
// status page (collector_status.php) reads it, so the operator can see that
// collection is alive without opening a terminal.

const COLLECTOR_LOG_MAX_BYTES = 5242880;   // 5 MB, then the file is rotated once

function collectorIsCli() {
    if (PHP_SAPI === 'cli') {
        return true;
    }
    // Escape hatch for hosts whose cron runs a non-CLI PHP binary. It only
    // enables long-running collectors; it never bypasses authentication.
    return getenv('NOCVIEW_ALLOW_NON_CLI') === '1';
}

/**
 * Parse "--name=value", "--name value", "--flag" and "-h" arguments.
 * Returns ['name' => string|true, ...] plus '_rest' for bare arguments.
 */
function collectorParseArgs(array $argv, array $valueOptions = []) {
    $valueOptions = array_flip($valueOptions);
    $out = ['_rest' => []];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--') {
            continue;
        }
        if (strpos($arg, '--') === 0) {
            $body = substr($arg, 2);
            if (strpos($body, '=') !== false) {
                [$name, $value] = explode('=', $body, 2);
                $out[$name] = $value;
                continue;
            }
            if (isset($valueOptions[$body])) {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && strpos((string)$next, '--') !== 0) {
                    $out[$body] = (string)$next;
                    $i++;
                    continue;
                }
            }
            $out[$body] = true;
            continue;
        }
        if ($arg === '-h') {
            $out['help'] = true;
            continue;
        }
        $out['_rest'][] = $arg;
    }
    return $out;
}

/** Integer option with bounds. */
function collectorIntOption(array $args, $name, $default, $min = 0, $max = PHP_INT_MAX) {
    if (!isset($args[$name]) || $args[$name] === true) {
        return $default;
    }
    if (!is_numeric($args[$name])) {
        return $default;
    }
    return max($min, min($max, (int)$args[$name]));
}

/**
 * Print a log line (Karachi time) and, when $logPath is set, append it there.
 * The log file is rotated to "<file>.1" once it passes COLLECTOR_LOG_MAX_BYTES.
 */
function collectorLog($message, $logPath = null) {
    $line = date('c') . ' ' . rtrim((string)$message) . PHP_EOL;
    if (defined('STDOUT')) {
        fwrite(STDOUT, $line);
    } else {
        echo $line;
    }
    if (!$logPath) {
        return;
    }
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    clearstatcache(true, $logPath);
    if (is_file($logPath) && filesize($logPath) > COLLECTOR_LOG_MAX_BYTES) {
        @rename($logPath, $logPath . '.1');
    }
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    @chmod($logPath, 0640);
}

/** Where the collectors keep their runtime state (never inside git-tracked paths). */
function collectorStatePath($name, $defaultFile) {
    $dir = getenv('NOCVIEW_STATE_DIR') ?: (__DIR__ . '/tmp');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $defaultFile;
}

/** Heartbeat path, overridable with NOCVIEW_HEARTBEAT_<NAME> or --heartbeat. */
function collectorHeartbeatPath($name, $override = null) {
    if (is_string($override) && $override !== '') {
        return $override;
    }
    $env = getenv('NOCVIEW_HEARTBEAT_' . strtoupper($name));
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return collectorStatePath($name, $name . '_heartbeat.json');
}

/** Atomically replace the heartbeat file (0600: it may contain internal IPs). */
function collectorWriteHeartbeat($path, array $state) {
    $state['updated_at'] = date('c');
    $state['updated_epoch'] = time();
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    } else {
        @chmod($path, 0640);
    }
    return $ok;
}

/** Read a heartbeat file, or null. */
function collectorReadHeartbeat($path) {
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Base heartbeat document every collector starts from. */
function collectorBaseHeartbeat($name, $mode, array $extra = []) {
    return array_merge([
        'name' => $name,
        'mode' => $mode,
        'pid' => getmypid(),
        'host' => function_exists('gethostname') ? (gethostname() ?: '') : '',
        'php' => PHP_VERSION,
        'started_at' => date('c'),
        'started_epoch' => time(),
        'last_tick_at' => null,
        'last_tick_epoch' => null,
        'last_success_at' => null,
        'last_success_epoch' => null,
        'ticks' => 0,
        'consecutive_failures' => 0,
        'last_error' => null,
        'sites' => [],
    ], $extra);
}

/**
 * Take a non-blocking exclusive lock. Returns the handle to keep for the life of
 * the process (and to release with collectorReleaseLock), or null when another
 * run is already active.
 */
function collectorAcquireLock($name) {
    $path = getenv('NOCVIEW_LOCK_DIR')
        ? rtrim(getenv('NOCVIEW_LOCK_DIR'), '/\\') . DIRECTORY_SEPARATOR . $name . '.lock'
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nocview_' . $name . '.lock';
    $fh = @fopen($path, 'c');
    if (!$fh) {
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

function collectorReleaseLock($fh) {
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Sleep until $targetEpoch (float epoch seconds). Sleeps in short slices so a
 * Ctrl+C / SIGTERM is handled promptly on every platform.
 */
function collectorSleepUntil($targetEpoch) {
    while (($remaining = $targetEpoch - microtime(true)) > 0) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        usleep((int)min(250000, $remaining * 1000000));
    }
}

/** Human readable age of an epoch second value. */
function collectorAge($epoch, $now = null) {
    if ($epoch === null || !is_numeric($epoch)) {
        return null;
    }
    $now = $now === null ? time() : (int)$now;
    return max(0, $now - (int)$epoch);
}
