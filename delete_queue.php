<?php
require('routeros_api.class.php');
require('config.php');

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Queue ID missing']);
    exit;
}

$API = new RouterosAPI();
if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
    $API->write('/queue/simple/remove', false);
    $API->write('=.id=' . $data['id']);
    $API->read();
    $API->disconnect();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to connect to MikroTik']);
}
