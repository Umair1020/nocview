<?php
header('Content-Type: application/json');
require('routeros_api.class.php');
require('config.php');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !in_array($data['action'], ['enable', 'disable'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$API = new RouterosAPI();
if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
    $API->write('/queue/simple/set', false);
    $API->write("=.id=" . $data['id'], false);
    $API->write("=disabled=" . ($data['action'] === 'disable' ? 'yes' : 'no'));
    $API->read();
    $API->disconnect();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not connect to MikroTik']);
}
