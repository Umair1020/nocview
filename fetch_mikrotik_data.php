<?php
require('routeros_api.class.php');

header('Content-Type: application/json');

$ip = $_GET['ip'] ?? null;
if (!$ip) {
    echo json_encode(['error' => 'No IP provided']);
    exit;
}

$interfaceMap = [
    // same map...
];
$locationMap = [
    // same map...
];

$name = $locationMap[$ip] ?? $ip;
$interface = $interfaceMap[$ip] ?? 'ether1';

$API = new RouterosAPI();

$response = [
    'ip' => $ip,
    'location' => $name,
    'status' => 'down',
    'bandwidth_usage' => 0,
    'cpu_usage' => 0,
    'uptime' => 0,
    'ping' => 0,
    'error' => null
];

if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
    try {
        // CPU and uptime
        $API->write('/system/resource/print');
        $resource = $API->read();
        $response['cpu_usage'] = $resource[0]['cpu-load'] ?? 0;

        $uptime_str = $resource[0]['uptime'] ?? '';
        $uptime_seconds = 0;
        if (preg_match('/(\d+)w/', $uptime_str, $m)) $uptime_seconds += $m[1] * 7 * 24 * 3600;
        if (preg_match('/(\d+)d/', $uptime_str, $m)) $uptime_seconds += $m[1] * 24 * 3600;
        if (preg_match('/(\d+)h/', $uptime_str, $m)) $uptime_seconds += $m[1] * 3600;
        if (preg_match('/(\d+)m/', $uptime_str, $m)) $uptime_seconds += $m[1] * 60;
        if (preg_match('/(\d+)s/', $uptime_str, $m)) $uptime_seconds += $m[1];

        $response['uptime'] = round(($uptime_seconds / (30 * 24 * 3600)) * 100, 2);

        // Traffic
        $API->write('/interface/monitor-traffic', false);
        $API->write("=interface={$interface}", false);
        $API->write('=once=', true);
        $traffic = $API->read();
        $rx = $traffic[0]['rx-bits-per-second'] ?? 0;
        $tx = $traffic[0]['tx-bits-per-second'] ?? 0;
        $response['bandwidth_usage'] = round(($rx + $tx) / 1000000, 2);

        // Ping
        $API->write('/ping', false);
        $API->write('=address=8.8.8.8', false);
        $API->write('=count=3');
        $ping = $API->read();
        $rtt = $ping[0]['avg-rtt'] ?? null;
        if ($rtt) {
            $response['ping'] = round((float) preg_replace('/[^0-9.]/', '', $rtt), 2);
        }

        $response['status'] = 'up';

    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    $API->disconnect();
} else {
    $response['error'] = 'RouterOS API connect failed';
}

echo json_encode($response);
