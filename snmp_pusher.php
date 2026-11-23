<?php
// File: snmp_pusher.php  (runs on the poller: office XAMPP PC, or an always-on VM)
// Polls every Sangfor site (ICMP + read-only SNMP) and sends the results to the website.
//
// Two ways to run it:
//
//   1) Service / daemon mode - run once, it stays up and polls forever:
//        php snmp_pusher.php --loop --interval=60 --log=/var/log/nocview/pusher.log
//      A tick every 60 s keeps bandwidth_logs on the website filling 24/7 with the
//      same cadence as the MikroTik cron collector. Start it from systemd
//      (deploy/nocview-collector.service) or Task Scheduler, and it comes back
//      by itself after a reboot, a crash or a network drop.
//
//   2) Legacy scheduler mode - one run every minute (Windows Task Scheduler):
//        php snmp_pusher.php
//      Sends "runs_per_start" updates, "interval_sec" apart, then exits.
//
// Needs on this machine:
//   snmp_config.local.php  - communities (as before)
//   push_config.local.php  - ['mode' => 'push', 'token' => '...', 'url' => 'https://.../snmp_push.php']
//
// Options: --loop | --once --interval=SEC --ticks=N --state-dir=DIR
//          --heartbeat=FILE --log=FILE --dry-run --help

require_once __DIR__ . '/collector_common.php';

if (!collectorIsCli()) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/router_poll.php';
global $clientConfig;

// config.php sets max_execution_time to 30 s for web pages; this CLI run needs longer.
@set_time_limit(0);
@ini_set('max_execution_time', '0');
ini_set('default_socket_timeout', 10);
date_default_timezone_set('Asia/Karachi');

$COLLECTOR_NAME = 'snmp_pusher';
$optionsWithValue = ['interval', 'ticks', 'state-dir', 'heartbeat', 'log', 'url', 'runs'];
$args = collectorParseArgs($argv ?? [], $optionsWithValue);

if (isset($args['help'])) {
    echo <<<TXT
snmp_pusher.php - push Sangfor ping + bandwidth to NOCView

Usage:
  php snmp_pusher.php --loop [options]   keep running (service mode)
  php snmp_pusher.php [options]          one scheduled run, then exit

Options:
  --loop              poll forever, sleeping --interval between ticks
  --once              force single-run mode
  --interval=SEC      seconds between ticks (loop default 60, once default from config)
  --ticks=N           stop after N ticks (0 = unlimited)
  --runs=N            once-mode only: updates per start (default from config)
  --state-dir=DIR     SNMP counter state (default <state>/snmp_state)
  --heartbeat=FILE    heartbeat JSON (default <state>/snmp_pusher_heartbeat.json)
  --log=FILE          append to FILE (rotated at 5 MB); stdout is used otherwise
  --url=URL           override the push URL from push_config.local.php
  --dry-run           poll and print, do not send anything
  --help              this text

TXT;
    exit(0);
}

$loop = isset($args['loop']) && !isset($args['once']);
$dryRun = isset($args['dry-run']);
$logPath = is_string($args['log'] ?? null) && $args['log'] !== '' ? $args['log'] : null;

$cfg = nocviewPushConfig(true);
$url = is_string($args['url'] ?? null) && $args['url'] !== '' ? $args['url'] : (string)($cfg['url'] ?? '');
$token = (string)($cfg['token'] ?? '');

if (!$dryRun) {
    if (nocviewPushMode() !== 'push') {
        collectorLog("push_config.local.php missing or not in 'push' mode - nothing to send", $logPath);
        exit(1);
    }
    if ($token === '' || $url === '' || !preg_match('#^https?://#i', $url)) {
        collectorLog("push_config.local.php needs both a token and an http(s) url", $logPath);
        exit(1);
    }
}

$stateDir = is_string($args['state-dir'] ?? null) && $args['state-dir'] !== ''
    ? $args['state-dir']
    : collectorStatePath($COLLECTOR_NAME, 'snmp_state');
if (!is_dir($stateDir) && !@mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
    collectorLog("cannot create state dir $stateDir", $logPath);
    exit(1);
}

$heartbeatPath = collectorHeartbeatPath($COLLECTOR_NAME, $args['heartbeat'] ?? null);

if ($loop) {
    $interval = collectorIntOption($args, 'interval', (int)($cfg['loop_interval_sec'] ?? 60), 5, 3600);
    $ticks = collectorIntOption($args, 'ticks', 0, 0);
    $runs = 1;
} else {
    $interval = collectorIntOption($args, 'interval', (int)($cfg['interval_sec'] ?? 15), 1, 3600);
    $runs = collectorIntOption($args, 'runs', (int)($cfg['runs_per_start'] ?? 4), 1, 60);
    $ticks = isset($args['ticks']) ? collectorIntOption($args, 'ticks', $runs, 1) : $runs;
}

$lock = collectorAcquireLock($COLLECTOR_NAME);
if (!$lock) {
    collectorLog('another snmp_pusher run is already active - skipping this start', $logPath);
    exit(0);
}

// ---------------------------------------------------------------- sites
$sites = [];
foreach ($clientConfig as $client) {
    if (!is_array($client)) {
        continue;
    }
    foreach (($client['pingLocations'] ?? []) as $ip => $name) {
        if (!isset($sites[$ip])) {
            $sites[$ip] = ['name' => (string)$name, 'iface' => $client['pingInterfaces'][$ip] ?? null];
        }
    }
}
if ($sites === []) {
    collectorLog('no Sangfor sites configured in config.php pingLocations', $logPath);
    collectorReleaseLock($lock);
    exit(1);
}

// ---------------------------------------------------------------- transport
function pushSend($url, $token, array $results, array $poller = []) {
    $payload = json_encode([
        'sent_at' => date('c'),
        'poller' => $poller,
        'results' => $results,
    ]);
    $attempts = 2;
    $last = [0, 'not sent'];

    // php-curl may be missing on a minimal VPS: fall back to the HTTP stream wrapper.
    if (!function_exists('curl_init')) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-NocView-Token: " . $token,
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\b#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        return [$code, $body === false ? 'request failed (no curl, stream wrapper error)' : $body];
    }

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-NocView-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        // XAMPP's PHP often has no CA bundle configured; use the one XAMPP ships with.
        foreach (['C:\\xampp\\apache\\bin\\curl-ca-bundle.crt', 'C:\\xampp\\php\\extras\\ssl\\cacert.pem'] as $ca) {
            if (!ini_get('curl.cainfo') && is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
                break;
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $last = [$code, $body === false ? $err : (string)$body];
        if ($code >= 200 && $code < 300) {
            break;
        }
        if ($attempt < $attempts) {
            sleep(2);
        }
    }
    return $last;
}

// ---------------------------------------------------------------- one pass
function pushRunPass(array $sites, $stateDir) {
    $results = [];
    $summary = [];
    foreach ($sites as $ip => $site) {
        $iface = $site['iface'];
        try {
            $r = pollPingLocation($ip, $iface, ['state_dir' => $stateDir]);
        } catch (Throwable $e) {
            $r = [
                'status' => 'unknown', 'reason' => 'poll_error', 'ping' => null,
                'rx_mbps' => null, 'tx_mbps' => null, 'bw_reason' => 'poll_error',
                'snmp_reachable' => false,
            ];
        }
        $results[$ip] = [
            'status' => $r['status'],
            'reason' => $r['reason'],
            'ping' => $r['ping'],
            'rx_mbps' => $r['rx_mbps'],
            'tx_mbps' => $r['tx_mbps'],
            'bw_reason' => $r['bw_reason'],
            'interface' => $iface,
            'snmp_reachable' => !empty($r['snmp_reachable']),
        ];
        $summary[$ip] = [
            'name' => $site['name'],
            'interface' => $iface,
            'status' => $r['status'],
            'ping' => $r['ping'],
            'rx_mbps' => $r['rx_mbps'],
            'tx_mbps' => $r['tx_mbps'],
            'bw_reason' => $r['bw_reason'],
            'at' => time(),
        ];
    }
    return [$results, $summary];
}

function pushSummarize(array $results) {
    $parts = [];
    foreach ($results as $ip => $r) {
        $parts[] = $ip . '=' . ($r['rx_mbps'] !== null
            ? round((float)$r['rx_mbps'], 1) . '/' . round((float)$r['tx_mbps'], 1)
            : (string)($r['bw_reason'] ?? $r['status']));
    }
    return implode(' ', $parts);
}

// ---------------------------------------------------------------- run
$heartbeat = collectorBaseHeartbeat($COLLECTOR_NAME, $loop ? 'loop' : 'once', [
    'interval_sec' => $interval,
    'push_url' => $url,
    'dry_run' => $dryRun,
    'ticks_planned' => $ticks,
    'running' => true,
]);
collectorWriteHeartbeat($heartbeatPath, $heartbeat);
collectorLog(sprintf(
    'started mode=%s interval=%ss ticks=%s sites=%d log=%s',
    $loop ? 'loop' : 'once',
    $interval,
    $ticks,
    count($sites),
    $logPath ?: 'stdout'
), $logPath);

$running = true;
if (function_exists('pcntl_signal')) {
    $stop = function () use (&$running) {
        $running = false;
    };
    @pcntl_signal(SIGINT, $stop);
    @pcntl_signal(SIGTERM, $stop);
}

$tick = 0;
$tickStart = microtime(true);
while ($running) {
    $tick++;
    [$results, $summary] = pushRunPass($sites, $stateDir);
    $heartbeat['sites'] = $summary;
    $heartbeat['ticks'] = $tick;
    $heartbeat['last_tick_at'] = date('c');
    $heartbeat['last_tick_epoch'] = time();

    if ($dryRun) {
        $heartbeat['last_success_at'] = date('c');
        $heartbeat['last_success_epoch'] = time();
        $heartbeat['consecutive_failures'] = 0;
        $heartbeat['last_error'] = null;
        collectorLog('dry-run ' . pushSummarize($results), $logPath);
    } else {
        $startedAt = microtime(true);
        [$code, $reply] = pushSend($url, $token, $results, [
            'host' => function_exists('gethostname') ? (gethostname() ?: '') : '',
            'mode' => $loop ? 'loop' : 'once',
            'interval_sec' => $interval,
            'ticks' => $tick,
            'consecutive_failures' => (int)$heartbeat['consecutive_failures'],
            'php' => PHP_VERSION,
        ]);
        $ok = $code >= 200 && $code < 300;
        $heartbeat['last_http_code'] = $code;
        if ($ok) {
            $heartbeat['last_success_at'] = date('c');
            $heartbeat['last_success_epoch'] = time();
            $heartbeat['consecutive_failures'] = 0;
            $heartbeat['last_error'] = null;
            collectorLog(sprintf(
                'HTTP %d in %.1fs %s | %s',
                $code,
                microtime(true) - $startedAt,
                substr(trim(preg_replace('/\s+/', ' ', $reply)), 0, 200),
                pushSummarize($results)
            ), $logPath);
        } else {
            $heartbeat['consecutive_failures'] = (int)$heartbeat['consecutive_failures'] + 1;
            $heartbeat['last_error'] = 'HTTP ' . $code . ' ' . substr(trim(preg_replace('/\s+/', ' ', $reply)), 0, 300);
            collectorLog(sprintf(
                'HTTP %d FAILED (attempt %d): %s | %s',
                $code,
                $heartbeat['consecutive_failures'],
                substr(trim(preg_replace('/\s+/', ' ', $reply)), 0, 200),
                pushSummarize($results)
            ), $logPath);
            if ($code === 401 || $code === 403) {
                collectorLog('hint: token or mode mismatch between push_config.local.php here and on the website', $logPath);
            }
        }
    }
    collectorWriteHeartbeat($heartbeatPath, $heartbeat);

    if ($ticks > 0 && $tick >= $ticks) {
        break;
    }

    // Keep the cadence without drifting: sleep relative to this tick's start.
    $nextTick = $loop
        ? max($tickStart + $interval * $tick, microtime(true) + 1.0)
        : microtime(true) + $interval;
    if (!$loop) {
        // Legacy mode must never overrun the scheduler's next start (~55 s budget).
        $budgetLeft = 55 - (microtime(true) - $heartbeat['started_epoch']);
        if ($interval > $budgetLeft) {
            break;
        }
    }
    collectorSleepUntil($nextTick);
}

$heartbeat['stopped_at'] = date('c');
$heartbeat['running'] = false;
collectorWriteHeartbeat($heartbeatPath, $heartbeat);
collectorLog(sprintf('stopped after %d tick(s)', $tick), $logPath);
collectorReleaseLock($lock);
