<?php
require('routeros_api.class.php');
require('config.php');

$data = json_decode(file_get_contents('php://input'), true);
$API = new RouterosAPI();

if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
    $API->write('/queue/simple/add', false);
    $API->write("=name={$data['name']}", false);
    $API->write("=target={$data['target']}", false);
    $API->write("=max-limit={$data['limit']}");
    $API->read();
    $API->disconnect();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not connect']);
}
