<?php
include 'db.php';

$range = $_GET['range'] ?? 'live';
$data = ['time'=>[], 'rx'=>[], 'tx'=>[]];

if ($range === 'live') {
    require('routeros_api.class.php');
    $API = new RouterosAPI();
    if ($API->connect('IP', 'user', 'pass')) {
        $traffic = $API->comm("/interface/print", ["?name" => "ether1"]);
        $rx = $traffic[0]['rx-byte'];
        $tx = $traffic[0]['tx-byte'];
        $API->disconnect();
        $data['time'][] = date("H:i:s");
        $data['rx'][] = $rx;
        $data['tx'][] = $tx;
    }
} else {
    $intervals = [
        '24h' => '1 DAY',
        '7d'  => '7 DAY',
        '30d' => '30 DAY'
    ];
    $stmt = $pdo->prepare("SELECT rx_rate, tx_rate, recorded_at 
                           FROM bandwidth_data 
                           WHERE recorded_at >= NOW() - INTERVAL {$intervals[$range]}
                           ORDER BY recorded_at ASC");
    $stmt->execute();
    while($row = $stmt->fetch()) {
        $data['time'][] = date("Y-m-d H:i", strtotime($row['recorded_at']));
        $data['rx'][] = $row['rx_rate'];
        $data['tx'][] = $row['tx_rate'];
    }
}

echo json_encode($data);
