<?php
require('routeros_api.class.php');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$locationMap = [
    '103.246.108.50' => 'Ziauddin Hospital Korangi',
    '103.246.109.82' => 'Ziauddin Education Board',
    '103.246.109.74' => 'Ziauddin Hospital Clifton',
    '115.42.66.56'   => 'Spotcomm Office',
];

$API = new RouterosAPI();
$data = [];

foreach ($locationMap as $ip => $location) {
    $bandwidth = 0;

    if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
        $API->write('/interface/print');
        $interfaces = $API->read();

        foreach ($interfaces as $interface) {
            if (isset($interface['tx-byte']) && isset($interface['rx-byte'])) {
                $bandwidth += $interface['tx-byte'] + $interface['rx-byte'];
            }
        }

        $API->disconnect();
    }

    $data[$location] = round($bandwidth / (1024 * 1024), 2); // MB
}

header('Content-Type: application/json');
echo json_encode($data);
