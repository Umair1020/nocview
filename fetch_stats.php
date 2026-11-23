<?php
require('routeros_api.class.php');
header('Content-Type: application/json');

$ip = $_GET['ip'] ?? '';
$interface = $_GET['interface'] ?? '';

if (!$ip || !$interface) {
    die(json_encode(['error' => 'Missing parameters']));
}

$API = new RouterosAPI();
$data = ['timestamp' => time()]; // Add current timestamp

try {
    if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
        // Get traffic stats
        $API->write('/interface/monitor-traffic', false);
        $API->write("=interface=$interface", false);
        $API->write('=once=');
        $traffic = $API->read()[0];
        $data['rx'] = $traffic['rx-bits-per-second'] ?? 0;
        $data['tx'] = $traffic['tx-bits-per-second'] ?? 0;

        // Get system resources
        $API->write('/system/resource/print');
        $res = $API->read()[0];
        $data['cpu'] = $res['cpu-load'] ?? 0;
        $data['ram'] = round((1 - ($res['free-memory'] / $res['total-memory'])) * 100, 1);
        $data['uptime'] = $res['uptime'] ?? 'N/A';
        $data['connections'] = $res['cpu-count'] ?? 0;

        // Get health data
        $API->write('/system/health/print');
        $health = $API->read()[0];
        $data['temperature'] = $health['temperature'] ?? 'N/A';
        $data['cpu_temp'] = $health['cpu-temperature'] ?? 'N/A';
        $data['voltage'] = $health['voltage'] ?? 'N/A';

        $API->disconnect();
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'API connection failed']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>