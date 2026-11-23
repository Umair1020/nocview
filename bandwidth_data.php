<?php
require_once('routeros_api.class.php');

$ipMap = [
   '103.246.108.50' => 'Ziauddin Hospital Korangi Creek',
    '103.246.109.74' => 'ZCR Boat Basin (Clifton)',
    '103.246.109.82' => 'Ziauddin Education Board (ZEB)',
    '103.246.109.98' => 'Nursing - North',
    '103.246.109.106' => 'ZCRS / MT / FM (North)',
    '103.246.109.114' => 'College of Intermediate',
'103.246.109.122'  => 'KDLB-Kemari Hospital',
    '103.246.109.130' => 'College of SLT',
    '103.246.109.138' => 'MD - Home',
    '103.246.109.146' => 'SLT Clinic',
    '103.246.109.154' => 'Ziauddin Memorial Hospital',
    '115.42.66.56'    => 'Spotcomm Office',
];

$cacheFile = 'dashboard_cache.json';
$interval = 5; // seconds
$maxPoints = 12; // 1 min = 12 points @5s
$timestamps = [];

if (!file_exists($cacheFile)) {
    file_put_contents($cacheFile, json_encode([
        'timestamps' => [],
        'locations' => []
    ]));
}

$data = json_decode(file_get_contents($cacheFile), true);
$now = date("H:i:s");

$data['timestamps'][] = $now;
if (count($data['timestamps']) > $maxPoints) array_shift($data['timestamps']);

foreach ($ipMap as $ip => $location) {
    $API = new RouterosAPI();
    $bandwidth = 0;

    if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
        $interfaces = $API->comm('/interface/print');
        foreach ($interfaces as $iface) {
            if (isset($iface['rx-bits-per-second']) && isset($iface['tx-bits-per-second'])) {
                $bandwidth += ($iface['rx-bits-per-second'] + $iface['tx-bits-per-second']) / 1024; // in Kbps
            }
        }
        $API->disconnect();
    }

    if (!isset($data['locations'][$location])) {
        $data['locations'][$location] = [];
    }
    $data['locations'][$location][] = round($bandwidth, 2);
    if (count($data['locations'][$location]) > $maxPoints) array_shift($data['locations'][$location]);
}

file_put_contents($cacheFile, json_encode($data));
header('Content-Type: application/json');
echo json_encode($data);
