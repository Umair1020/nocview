<?php
require('routeros_api.class.php');

$API = new RouterosAPI();
$API->debug = false;


// ---------------- CONFIG ----------------
$host = $_GET['ip'] ?? '115.42.66.56';
$user = 'spotcomm';
$pass = 'admin@1234';

if ($API->connect($host, $user, $pass)) {
    $hosts = [
         '103.246.108.50' => 'Ziauddin Hospital Korangi Creek',
    '103.246.109.74' => 'ZCR Boat Basin (Clifton)',
    '103.246.109.82' => 'Ziauddin Education Board (ZEB)',
    '103.246.109.98' => 'Nursing - North',
    '103.246.109.106' => 'ZCRS / MT / FM (North)',
    '103.246.109.114' => 'College of Intermediate',
    '103.246.109.122' => 'KDLB - Kemari Hospital',
    '103.246.109.130' => 'College of SLT',
    '103.246.109.138' => 'MD - Home',
    '103.246.109.146' => 'SLT Clinic',
    '115.42.66.56' => 'Spotcomm Office',
    '103.246.109.154' => 'Ziauddin Memorial Hospital',
    '103.246.109.2' => 'Ziauddin North Nazimabad',
    '103.246.109.90' => 'Zu Fest North University',
    ];

    $results = [];
    $total = 0;
    $upCount = 0;

    foreach ($hosts as $ip => $location) {
        $API->write('/ping', false);
        $API->write('=address=' . $ip, false);
        $API->write('=count=4', false);
        $API->write('=interval=200ms');
        $res = $API->read();

        $latencies = [];
        foreach ($res as $r) {
            if (isset($r['time'])) {
                $latencies[] = floatval(str_replace("ms","",$r['time']));
            }
        }

        if (count($latencies) > 0) {
            $avg = round(array_sum($latencies) / count($latencies), 2);
            $results[$ip] = [
                'location' => $location,
                'status' => 'up',
                'latency' => $avg . " ms"
            ];
            $total += $avg;
            $upCount++;
        } else {
            $results[$ip] = [
                'location' => $location,
                'status' => 'down',
                'latency' => null
            ];
        }
    }

    $overall = $upCount > 0 ? round($total / $upCount, 2) . " ms" : null;

    echo json_encode([
        'overall_avg' => $overall,
        'locations' => $results,
        'online_locations' => $upCount,
        'total_locations' => count($hosts)
    ]);

    $API->disconnect();
} else {
    echo json_encode(['error' => 'Unable to connect to MikroTik API']);
}
