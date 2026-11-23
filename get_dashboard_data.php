<?php
$conn = new mysqli("localhost", "root", "", "mikrotik_monitor");

$location = $_GET['location'] ?? '';
$range = $_GET['range'] ?? 'live';

if ($range == 'live') {
    $sql = "SELECT bandwidth_usage, log_time FROM bandwidth_logs 
            WHERE location_name=? ORDER BY log_time DESC LIMIT 30";
} elseif ($range == '24h') {
    $sql = "SELECT bandwidth_usage, log_time FROM bandwidth_logs 
            WHERE location_name=? AND log_time >= NOW() - INTERVAL 1 DAY ORDER BY log_time ASC";
} elseif ($range == '7d') {
    $sql = "SELECT bandwidth_usage, log_time FROM bandwidth_logs 
            WHERE location_name=? AND log_time >= NOW() - INTERVAL 7 DAY ORDER BY log_time ASC";
} elseif ($range == '30d') {
    $sql = "SELECT bandwidth_usage, log_time FROM bandwidth_logs 
            WHERE location_name=? AND log_time >= NOW() - INTERVAL 30 DAY ORDER BY log_time ASC";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $location);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'time' => $row['log_time'],
        'bandwidth' => (float)$row['bandwidth_usage']
    ];
}

echo json_encode($data);
?>
