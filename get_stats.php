<?php 
require('routeros_api.class.php');
header('Content-Type: application/json');

$ip = $_GET['ip'] ?? '';
if (!$ip) {
    echo json_encode(['error' => 'IP not provided']);
    exit;
}

$API = new RouterosAPI();
$data = [];

if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=ether3-WAN-Sec', false);
    $API->write('=once=');
    $interface = $API->read();

    $API->disconnect();

    $data['interface'] = $interface[0] ?? [];
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Connection failed']);
}
?>