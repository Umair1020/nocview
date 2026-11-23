<?php
require('routeros_api.class.php');
require_once(__DIR__ . '/db_connect.php');
require_once(__DIR__ . '/config.php');

header('Content-Type: application/json');

$client = getCurrentClient();
$MT_USER = $client['MT_USER'];
$MT_PASS = $client['MT_PASS'];
$router_ip = $client['locations'][0]['ip'];

$API = new RouterosAPI();
$API->debug = false;

try {
    if ($API->connect($router_ip, $MT_USER, $MT_PASS)) {
        $logs = $API->comm("/log/print", []);
        $API->disconnect();

        if (empty($logs)) {
            echo json_encode(["status" => "empty", "message" => "No logs found"]);
        } else {
            $logs = array_slice($logs, -100);
            echo json_encode(["status" => "success", "logs" => $logs]);
        }
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Failed to connect to Mikrotik ($router_ip) with user $MT_USER"
        ]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
