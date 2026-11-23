<?php
ob_start(); // Buffer output
header('Content-Type: application/json');
require('routeros_api.class.php');
require('config.php');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['id']) || !isset($data['limit']) || !isset($data['name'])) {
    http_response_code(400);
    ob_end_clean(); // Clear output
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$API = new RouterosAPI();

if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
    $API->write('/queue/simple/set', false);
    $API->write("=.id=" . $data['id'], false);
    $API->write("=name=" . $data['name'], false);
    $API->write("=max-limit=" . $data['limit']);
    $API->read();
    $API->disconnect();

    ob_end_clean(); // Clean any output
    echo json_encode(['success' => true]);
    exit;
} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Could not connect to MikroTik']);
    exit;
}

?>
     <div class="header">
        <h1>Network Locations Overview</h1>
        <div class="d-flex justify-content-end ms-auto">
          <a href="logout.php" class="logout-btn">Logout</a>
        <button class="refresh-btn  mx-4" onclick="window.location.reload()">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
</div>
