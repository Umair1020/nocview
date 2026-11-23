<?php
require('routeros_api.class.php');
require('config.php');

header('Content-Type: application/json');
session_start();

$target = $_GET['target'] ?? '';
$queueId = $_GET['queue_id'] ?? '';

if (!$target) {
    echo json_encode(['error' => 'Missing target']);
    exit;
}

$API = new RouterosAPI();

if (!$API->connect('115.42.67.210', 'api', 'compaq123_')) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

// If queue ID is provided, use it for precise targeting
if ($queueId) {
    $API->write('/queue/simple/print', false);
    $API->write('?.id=' . $queueId);
} else {
    $API->write('/queue/simple/print', false);
    $API->write('?target=' . $target);
}

$result = $API->read();
$API->disconnect();

if (empty($result)) {
    echo json_encode(['rx' => 0, 'tx' => 0, 'error' => 'No queue found']);
    exit;
}

// Use the first result (should be the exact queue we want)
$queueData = $result[0];

if (!$queueData) {
    echo json_encode(['rx' => 0, 'tx' => 0, 'error' => 'Queue data not found']);
    exit;
}

// METHOD 1: Use 'rate' field (recommended - same as Winbox)
if (isset($queueData['rate']) && $queueData['rate'] !== '0/0') {
    list($txRate, $rxRate) = explode("/", $queueData['rate']);
    
    // Convert bytes per second to Kbps
    $rxKbps = round(($rxRate * 8) / 1000, 2); // bytes/sec to Kbps
    $txKbps = round(($txRate * 8) / 1000, 2); // bytes/sec to Kbps
    
    echo json_encode([
        'rx' => $rxKbps, 
        'tx' => $txKbps,
        'method' => 'rate_field',
        'raw_rate' => $queueData['rate'],
        'queue_name' => $queueData['name'] ?? 'Unknown'
    ]);
    exit;
}

// METHOD 2: Use packet-rate for more accurate real-time data
if (isset($queueData['packet-rate']) && $queueData['packet-rate'] !== '0/0') {
    list($txPacketRate, $rxPacketRate) = explode("/", $queueData['packet-rate']);
    
    // Convert packet rate to bandwidth (estimate)
    // Average packet size ~ 1500 bytes = 12000 bits
    $rxKbps = round(($rxPacketRate * 1500 * 8) / 1000, 2);
    $txKbps = round(($txPacketRate * 1500 * 8) / 1000, 2);
    
    echo json_encode([
        'rx' => $rxKbps, 
        'tx' => $txKbps,
        'method' => 'packet_rate',
        'raw_packet_rate' => $queueData['packet-rate'],
        'queue_name' => $queueData['name'] ?? 'Unknown'
    ]);
    exit;
}

// METHOD 3: Fallback to bytes calculation
if (isset($queueData['bytes']) && $queueData['bytes'] !== '0/0') {
    list($txBytes, $rxBytes) = explode("/", $queueData['bytes']);

    $now = time();
    $key = md5($target . ($queueData['name'] ?? '')); // Include queue name in key

    $prev = $_SESSION['queue_traffic'][$key] ?? null;
    $_SESSION['queue_traffic'][$key] = [
        'time' => $now,
        'rx' => $rxBytes,
        'tx' => $txBytes
    ];

    if ($prev && $prev['time'] != $now) {
        $timeDiff = max(1, $now - $prev['time']);
        $rxDiff = max(0, $rxBytes - $prev['rx']);
        $txDiff = max(0, $txBytes - $prev['tx']);
        
        $rxKbps = round(($rxDiff * 8) / ($timeDiff * 1000), 2);
        $txKbps = round(($txDiff * 8) / ($timeDiff * 1000), 2);

        echo json_encode([
            'rx' => $rxKbps, 
            'tx' => $txKbps,
            'method' => 'bytes_calculation',
            'queue_name' => $queueData['name'] ?? 'Unknown'
        ]);
    } else {
        echo json_encode(['rx' => 0, 'tx' => 0, 'method' => 'first_call', 'queue_name' => $queueData['name'] ?? 'Unknown']);
    }
} else {
    echo json_encode([
        'rx' => 0, 
        'tx' => 0, 
        'error' => 'No traffic data available',
        'queue_name' => $queueData['name'] ?? 'Unknown',
        'bytes' => $queueData['bytes'] ?? 'N/A',
        'rate' => $queueData['rate'] ?? 'N/A'
    ]);
}
?>