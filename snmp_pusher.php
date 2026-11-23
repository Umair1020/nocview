<?php
// File: snmp_pusher.php  (runs on the poller: office XAMPP PC now, a DC server later)
// Polls every Sangfor site (ping + read-only SNMP) and sends the results to the website.
// Start it every minute (Windows Task Scheduler / Linux cron). Each run sends 4 updates,
// one every 15 seconds, then exits.
//
// Needs on this machine:
//   snmp_config.local.php  - communities (as before)
//   push_config.local.php  - ['mode' => 'push', 'token' => '...', 'url' => 'https://.../snmp_push.php']

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/router_poll.php';
global $clientConfig;

// config.php sets max_execution_time to 30 s for web pages; this CLI run needs ~60 s.
set_time_limit(0);
ini_set('max_execution_time', '0');

$cfg = nocviewPushConfig();
if (nocviewPushMode() !== 'push' || empty($cfg['token']) || empty($cfg['url'])) {
    fwrite(STDERR, date('c') . " push_config.local.php missing or not in 'push' mode\n");
    exit(1);
}

$lock = fopen(sys_get_temp_dir() . '/nocview_snmp_pusher.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // previous run still busy
}

$sites = [];
foreach ($clientConfig as $client) {
    foreach (($client['pingLocations'] ?? []) as $ip => $name) {
        $sites[$ip] = $client['pingInterfaces'][$ip] ?? null;
    }
}

function pushSend($url, $token, array $results) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['sent_at' => date('c'), 'results' => $results]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-NocView-Token: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $body === false ? $err : $body];
}

$runs = (int)($cfg['runs_per_start'] ?? 4);
$every = (int)($cfg['interval_sec'] ?? 15);
$start = microtime(true);

for ($i = 0; $i < $runs; $i++) {
    $tick = microtime(true);
    $results = [];
    foreach ($sites as $ip => $iface) {
        $r = pollPingLocation($ip, $iface);
        $results[$ip] = [
            'status' => $r['status'], 'reason' => $r['reason'], 'ping' => $r['ping'],
            'rx_mbps' => $r['rx_mbps'], 'tx_mbps' => $r['tx_mbps'],
            'bw_reason' => $r['bw_reason'], 'snmp_reachable' => !empty($r['snmp_reachable']),
        ];
    }
    [$code, $reply] = pushSend($cfg['url'], $cfg['token'], $results);
    $summary = [];
    foreach ($results as $ip => $r) {
        $summary[] = $ip . '=' . ($r['rx_mbps'] !== null ? round($r['rx_mbps'], 1) . '/' . round($r['tx_mbps'], 1) : $r['bw_reason']);
    }
    echo date('c') . " HTTP $code " . substr((string)$reply, 0, 120) . ' | ' . implode(' ', $summary) . "\n";

    if ($i < $runs - 1) {
        $sleep = $every - (microtime(true) - $tick);
        if (microtime(true) - $start + max(0, $sleep) > 55) {
            break;   // the next scheduled start takes over
        }
        if ($sleep > 0) {
            usleep((int)($sleep * 1000000));
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);
