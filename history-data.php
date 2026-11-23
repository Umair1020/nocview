<?php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'root', '');
$hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;

// Determine group format (not a bind parameter)
if ($hours <= 1) {
    $groupFormat = '%H:%i'; // every 1 minute
} elseif ($hours <= 24) {
    $groupFormat = '%H:00'; // every hour
} else {
    $groupFormat = '%Y-%m-%d %H:00'; // across days
}

// Build query dynamically with group format injected directly
$query = "
    SELECT 
        DATE_FORMAT(created_at, '$groupFormat') AS time_label,
        ROUND(AVG(rx_mbps), 2) AS avg_rx,
        ROUND(AVG(tx_mbps), 2) AS avg_tx
    FROM mikrotik_stats
    WHERE created_at >= NOW() - INTERVAL :hours HOUR
    GROUP BY time_label
    ORDER BY time_label ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute(['hours' => $hours]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build response arrays
$labels = [];
$rx = [];
$tx = [];

foreach ($data as $row) {
    $labels[] = $row['time_label'];
    $rx[] = floatval($row['avg_rx']);
    $tx[] = floatval($row['avg_tx']);
}

// Return as JSON
echo json_encode([
    'labels' => $labels,
    'rx' => $rx,
    'tx' => $tx
]);
