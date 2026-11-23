<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script can only be run from the command line (cron).";
    exit;
}

set_time_limit(0);
ini_set('default_socket_timeout', 5);

require_once __DIR__ . '/bandwidth_lib.php';

global $clientConfig;

$lockPath = sys_get_temp_dir() . '/nocview_collect_bandwidth.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('c') . " another collector run is still active, skipping\n";
    exit(0);
}

$started = microtime(true);
$targets = bw_targets_for_group($clientConfig, 'all');
$written = 0;
$up = 0;
$down = 0;
$unavailable = 0;

foreach ($targets as $t) {
    $isSnmp = ($t['type'] ?? 'mikrotik') === 'snmp';
    $live = $isSnmp
        ? bw_poll_snmp_live($t['ip'], $t['iface'])
        : bw_poll_live($t['ip'], $t['iface'], $t['user'], $t['pass']);
    if ($live['status'] === 'up') {
        $up++;
    } elseif ($isSnmp) {
        $unavailable++;
        continue; // Never record unavailable non-RouterOS traffic as zero or down.
    } else {
        $down++;
        echo date('c') . " {$t['name']} ({$t['ip']} {$t['iface']}): {$live['error']}\n";
    }
    // Every collector run is a sample; the live page only writes if the collector has not just done so.
    if (logBandwidthSampleIfDue($t['name'], $t['iface'], $live['rx_mbps'], $live['tx_mbps'], $live['status'], 45)) {
        $written++;
    }
}

flock($lock, LOCK_UN);
fclose($lock);

printf("%s collected=%d written=%d up=%d down=%d unavailable=%d took=%.1fs\n",
    date('c'), count($targets), $written, $up, $down, $unavailable, microtime(true) - $started);
