<?php
// File: collect_bandwidth.php
// Writes bandwidth_logs samples for every monitored target. This is what fills
// the 24h / 7d / 30d / 6m / 1y charts while nobody has the dashboard open.
//
// Two ways to run it:
//
//   1) Cron, once a minute (shared hosting; Hostinger cron jobs):
//        * * * * * /usr/bin/php /home/USER/public_html/collect_bandwidth.php --once
//
//   2) Service / daemon mode - one process, polls forever (VPS or office server):
//        php collect_bandwidth.php --loop --interval=60 --log=/var/log/nocview/collect.log
//
// On shared hosting the Sangfor sites are fed by the poller's push (snmp_push.php
// writes their history), so they are skipped here; MikroTik sites are polled
// directly. On a VPS that can reach UDP 161 this collector does both.
//
// Options: --loop | --once --interval=SEC --ticks=N --heartbeat=FILE --log=FILE --help

require_once __DIR__ . '/collector_common.php';

if (!collectorIsCli()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script can only be run from the command line (cron).";
    exit;
}

set_time_limit(0);
@ini_set('max_execution_time', '0');
date_default_timezone_set('Asia/Karachi');

$COLLECTOR_NAME = 'collect_bandwidth';
$args = collectorParseArgs($argv ?? [], ['interval', 'ticks', 'heartbeat', 'log']);

// Answered before any database work, so --help never depends on MySQL.
if (isset($args['help'])) {
    echo <<<TXT
collect_bandwidth.php - store one bandwidth sample per monitored target

Usage:
  php collect_bandwidth.php --loop [options]   keep running (service mode)
  php collect_bandwidth.php [options]          one pass, then exit (cron mode)

Options:
  --loop              poll forever, sleeping --interval between passes
  --once              force single-pass mode
  --interval=SEC      seconds between passes (loop default 60)
  --ticks=N           stop after N passes (0 = unlimited)
  --heartbeat=FILE    heartbeat JSON (default <state>/collect_bandwidth_heartbeat.json)
  --log=FILE          append to FILE (rotated at 5 MB); stdout is used otherwise
  --help              this text

TXT;
    exit(0);
}

// config.php + MySQL are only needed for an actual collection pass.
require_once __DIR__ . '/bandwidth_lib.php';
global $clientConfig;

// bandwidth_lib.php -> config.php sets a 30 s web limit; a daemon must not inherit it.
@set_time_limit(0);
@ini_set('max_execution_time', '0');

ini_set('default_socket_timeout', 5);

$loop = isset($args['loop']) && !isset($args['once']);
$logPath = is_string($args['log'] ?? null) && $args['log'] !== '' ? $args['log'] : null;
$interval = collectorIntOption($args, 'interval', 60, 5, 3600);
$ticks = isset($args['ticks']) ? collectorIntOption($args, 'ticks', 0, 0) : ($loop ? 0 : 1);
$heartbeatPath = collectorHeartbeatPath($COLLECTOR_NAME, $args['heartbeat'] ?? null);

$lock = collectorAcquireLock($COLLECTOR_NAME);
if (!$lock) {
    collectorLog('another collect_bandwidth run is still active, skipping', $logPath);
    exit(0);
}

$targets = bw_targets_for_group($clientConfig, 'all');
$receiveMode = nocviewPushMode() === 'receive';

$heartbeat = collectorBaseHeartbeat($COLLECTOR_NAME, $loop ? 'loop' : 'once', [
    'interval_sec' => $interval,
    'targets' => count($targets),
    'push_receive_mode' => $receiveMode,
    'running' => true,
]);
collectorWriteHeartbeat($heartbeatPath, $heartbeat);
collectorLog(sprintf(
    'started mode=%s interval=%ss ticks=%s targets=%d push_receive=%s',
    $loop ? 'loop' : 'once',
    $interval,
    $ticks,
    count($targets),
    $receiveMode ? 'yes' : 'no'
), $logPath);

$running = true;
if (function_exists('pcntl_signal')) {
    $stop = function () use (&$running) {
        $running = false;
    };
    @pcntl_signal(SIGINT, $stop);
    @pcntl_signal(SIGTERM, $stop);
}

$pass = 0;
$startedAt = microtime(true);
    while ($running) {
    $pass++;
    $passStart = microtime(true);
    $written = $up = $down = $unavailable = $skippedPush = 0;
    $sites = [];
    $upNames = [];

    foreach ($targets as $t) {
        $isSnmp = ($t['type'] ?? 'mikrotik') === 'snmp';
        if ($isSnmp && $receiveMode) {
            // Sangfor history is written by snmp_push.php when the poller posts.
            $skippedPush++;
            $sites[$t['ip'] . '|' . $t['iface']] = [
                'name' => $t['name'], 'type' => 'snmp', 'status' => 'pushed',
                'reason' => 'fed_by_push', 'at' => time(),
            ];
            continue;
        }

        $live = $isSnmp
            ? bw_poll_snmp_live($t['ip'], $t['iface'])
            : bw_poll_live($t['ip'], $t['iface'], $t['user'], $t['pass']);

        if ($live['status'] === 'up') {
            $up++;
            $upNames[] = $t['name'];
        } elseif ($isSnmp) {
            $unavailable++;
            $sites[$t['ip'] . '|' . $t['iface']] = [
                'name' => $t['name'], 'type' => 'snmp', 'status' => 'unavailable',
                'reason' => $live['reason'] ?? 'snmp_unavailable', 'at' => time(),
            ];
            continue; // Never record unavailable non-RouterOS traffic as zero or down.
        } else {
            $down++;
            collectorLog("{$t['name']} ({$t['ip']} {$t['iface']}): " . ($live['error'] ?? 'no answer'), $logPath);
        }

        // Every pass is a sample; the live page only writes if the collector has not just done so.
        $wrote = logBandwidthSampleIfDue($t['name'], $t['iface'], $live['rx_mbps'], $live['tx_mbps'], $live['status'], 45);
        if ($wrote) {
            $written++;
        }
        $sites[$t['ip'] . '|' . $t['iface']] = [
            'name' => $t['name'],
            'type' => $isSnmp ? 'snmp' : 'mikrotik',
            'status' => $live['status'],
            'rx_mbps' => $live['rx_mbps'],
            'tx_mbps' => $live['tx_mbps'],
            'stored' => $wrote,
            'reason' => $live['reason'] ?? null,
            'at' => time(),
        ];
    }

    // Alert lifecycle: locations proven healthy right now have their open
    // connectivity alerts resolved (bandwidth-threshold alerts clear on their
    // own condition). Keeps "Active Alerts" reflecting ongoing incidents.
    $resolvedAlerts = 0;
    if ($upNames !== [] && function_exists('resolveAlertsForLocations')) {
        $resolvedAlerts = resolveAlertsForLocations(
            $upNames,
            function_exists('nocviewConnectivityAlertPatterns') ? nocviewConnectivityAlertPatterns() : null
        );
    }

    $heartbeat['sites'] = $sites;
    $heartbeat['ticks'] = $pass;
    $heartbeat['last_tick_at'] = date('c');
    $heartbeat['last_tick_epoch'] = time();
    $heartbeat['last_success_at'] = date('c');
    $heartbeat['last_success_epoch'] = time();
    $heartbeat['consecutive_failures'] = 0;
    $heartbeat['last_error'] = null;
    $heartbeat['last_pass'] = [
        'written' => $written, 'up' => $up, 'down' => $down,
        'unavailable' => $unavailable, 'skipped_push' => $skippedPush,
        'resolved_alerts' => $resolvedAlerts,
        'took_sec' => round(microtime(true) - $passStart, 2),
    ];
    collectorWriteHeartbeat($heartbeatPath, $heartbeat);

    if ($resolvedAlerts > 0) {
        collectorLog(sprintf('auto-resolved %d alert(s) for healthy locations', $resolvedAlerts), $logPath);
    }

    collectorLog(sprintf(
        'pass=%d targets=%d written=%d up=%d down=%d unavailable=%d push_skipped=%d took=%.1fs',
        $pass,
        count($targets),
        $written,
        $up,
        $down,
        $unavailable,
        $skippedPush,
        microtime(true) - $passStart
    ), $logPath);

    if ($ticks > 0 && $pass >= $ticks) {
        break;
    }
    collectorSleepUntil($loop ? max($startedAt + $interval * $pass, microtime(true) + 1.0) : microtime(true) + $interval);
}

$heartbeat['running'] = false;
$heartbeat['stopped_at'] = date('c');
collectorWriteHeartbeat($heartbeatPath, $heartbeat);
collectorReleaseLock($lock);
