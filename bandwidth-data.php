<?php
require('routeros_api.class.php');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$API = new RouterosAPI();
$interface = $_GET['interface'] ?? 'ether1-WAN';

$data = [
    'rx' => 0,
    'tx' => 0
];

if ($API->connect('103.246.108.50', 'spotcomm', 'admin@1234')) {
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=' . $interface, false);
    $API->write('=once=');
    $traffic = $API->read();

    if (isset($traffic[0]['rx-bits-per-second'])) {
        $data['rx'] = round($traffic[0]['rx-bits-per-second'] / 1024 / 1024, 2);
    }
    if (isset($traffic[0]['tx-bits-per-second'])) {
        $data['tx'] = round($traffic[0]['tx-bits-per-second'] / 1024 / 1024, 2);
    }

    $API->disconnect();
}

// Store to database
try {
//   $pdo = new PDO('mysql:host=localhost;dbname=test', 'root', '');
$pdo = new PDO('mysql:host=localhost;dbname=test', 'test123', 'Compaq123_');
$stmt = $pdo->prepare("INSERT INTO mikrotik_stats (interface, rx_mbps, tx_mbps) VALUES (?, ?, ?)");
$stmt->execute([$interface, $data['rx'], $data['tx']]);

} catch (PDOException $e) {
    // optional: log or ignore
}

header('Content-Type: application/json');
echo json_encode($data);
