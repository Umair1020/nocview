<?php
session_start();
require_once('routeros_api.class.php');
require_once 'db_connect.php';
require_once 'config.php'; 

$db = Database::getInstance();
$mysqli = $db->getConnection();

// Production: log errors, never print them to the browser.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Get current client from session or configuration
$currentClient = isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown';
$isKnoativ = ($currentClient === 'Knoativ');

// Location to IP mapping - SEPARATE FOR EACH CLIENT
$locationMap = [];

if ($isKnoativ) {
    // Knoativ locations only
    $locationMap = [
        '115.42.66.56'    => 'Spotcomm Office',
        // Add other Knoativ locations here
    ];
} else {
    // Ziauddin locations only
    $locationMap = [
        '103.246.108.50'  => 'Ziauddin Hospital Korangi Creek',
        '103.246.109.74'  => 'ZCR Boat Basin (Clifton)',
        '103.246.109.82'  => 'Ziauddin Education Board (ZEB)',
        '103.246.109.98'  => 'Nursing - North',
        '103.246.109.106' => 'ZCRS / MT / FM (North)',
        '103.246.109.114' => 'College of Intermediate',
        '103.246.109.122' => 'KDLB - Kemari Hospital',
        '103.246.109.130' => 'College of SLT',
        '103.246.109.138' => 'MD - Home',
        '103.246.109.146' => 'SLT Clinic',
        '103.246.109.154' => 'Ziauddin Memorial Hospital',
        '103.246.109.2'   => 'Ziauddin North Nazimabad',
        '103.246.109.90'  => 'Zu Fest North University',
    ];
}

// Thresholds - ALSO SEPARATE FOR EACH CLIENT
function getThresholdForLocation($location) {
    global $isKnoativ;
    
    if ($isKnoativ) {
        // Knoativ thresholds
        $thresholds = [
            'Spotcomm Office' => 1000,
            // Add other Knoativ thresholds
        ];
    } else {
        // Ziauddin thresholds
        $thresholds = [
            'Ziauddin Hospital Korangi Creek' => 500,
            'ZCR Boat Basin (Clifton)' => 600,
            'Ziauddin Education Board (ZEB)' => 400,
            'Nursing - North' => 300,
        ];
    }
    return $thresholds[$location] ?? 200;
}

// Store alert. Incident-based dedup: at most one OPEN ('new') alert per
// location + message; it closes automatically on recovery (or manually), so
// nothing piles up. Plain location names are stored - the same convention as
// the rest of the app - so the Overview dashboard counts these alerts too.
function storeAlert($location, $message, $type = 'critical') {
    global $mysqli;

    $fullLocation = $location;
    $query = "SELECT id FROM alerts WHERE location = ? AND message LIKE ? AND status = 'new' LIMIT 1";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return false;
    }
    $search_msg = substr($message, 0, 50) . '%';
    $stmt->bind_param("ss", $fullLocation, $search_msg);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    $type = in_array(strtolower($type), ['warning', 'critical']) ? strtolower($type) : 'critical';
    $status = 'new';
    $created_at = date('Y-m-d H:i:s');

    $stmt = $mysqli->prepare("INSERT INTO alerts (location, message, type, status, created_at) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return false;
    }
    $stmt->bind_param("sssss", $fullLocation, $message, $type, $status, $created_at);
    $result = $stmt->execute();
    if (!$result) {
        error_log("Insert failed: " . $mysqli->error);
    }
    $stmt->close();
    return $result;
}

// Fetch alerts - FILTERED BY CLIENT
// Plain location names (current convention) plus legacy client-prefixed rows,
// so history stays visible after the prefix was dropped.
function getAlertsFromDatabase($mysqli) {
    global $isKnoativ, $locationMap;
    
    $alerts = [];
    $clientPrefix = $isKnoativ ? 'Knoativ - ' : 'Ziauddin - ';
    $names = array_values(array_unique($locationMap ?? []));
    
    $where = [];
    $types = '';
    $params = [];
    if ($names) {
        $where[] = 'location IN (' . str_repeat('?,', count($names) - 1) . '?)';
        $types .= str_repeat('s', count($names));
        foreach ($names as $name) {
            $params[] = $name;
        }
    }
    $where[] = 'location LIKE ?';
    $types .= 's';
    $params[] = $clientPrefix . '%';
    
    $query = "SELECT * FROM alerts WHERE " . implode(' OR ', $where) . " ORDER BY created_at DESC LIMIT 100";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return $alerts;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Remove legacy client prefix for display
            if (strpos($row['location'], $clientPrefix) === 0) {
                $row['location'] = substr($row['location'], strlen($clientPrefix));
            }
            $alerts[] = $row;
        }
    }
    $stmt->close();
    return $alerts;
}

// Fetch alert counts for the last 24 hours - FILTERED BY CLIENT
function getAlertCountsLast24Hours($mysqli) {
    global $isKnoativ, $locationMap;
    
    $alertsPerHour = array_fill(0, 12, 0);
    $clientPrefix = $isKnoativ ? 'Knoativ - ' : 'Ziauddin - ';
    $now = time();
    $startTime = $now - (24 * 60 * 60);
    $names = array_values(array_unique($locationMap ?? []));
    
    $where = [];
    $types = 'i';
    $params = [$startTime];
    if ($names) {
        $where[] = 'location IN (' . str_repeat('?,', count($names) - 1) . '?)';
        $types .= str_repeat('s', count($names));
        foreach ($names as $name) {
            $params[] = $name;
        }
    }
    $where[] = 'location LIKE ?';
    $types .= 's';
    $params[] = $clientPrefix . '%';
    
    $query = "SELECT created_at FROM alerts WHERE created_at >= FROM_UNIXTIME(?) AND (" . implode(' OR ', $where) . ")";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return $alertsPerHour;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $timestamp = strtotime($row['created_at']);
        $diffHours = floor(($now - $timestamp) / 7200);
        $index = 11 - $diffHours;
        if ($index >= 0 && $index < 12) {
            $alertsPerHour[$index]++;
        }
    }
    $stmt->close();
    return $alertsPerHour;
}

// Handle AJAX request
if (isset($_GET['ajax'])) {
    $locationStatus = [];
    $bandwidthUsage = [];
    $alertedLocations = [];
    
    // Only check locations for current client
    $clientLocations = $locationMap; // This now contains only client-specific locations
    
    // Query cache for client-specific locations
    $ipList = array_keys($clientLocations);
    if (!empty($ipList)) {
        $placeholders = implode(',', array_fill(0, count($ipList), '?'));
        $query = "SELECT ip, location, bandwidth_usage, status, last_updated FROM network_status_cache WHERE ip IN ($placeholders)";
        $stmt = $mysqli->prepare($query);
        
        if ($stmt) {
            $types = str_repeat('s', count($ipList));
            $stmt->bind_param($types, ...$ipList);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $location = $row['location'];
                $ip = $row['ip'];
                $isStale = strtotime($row['last_updated']) < time() - 300;
                $locationStatus[$location] = $isStale ? false : (bool) $row['status'];
                $bandwidthUsage[$location] = $isStale ? 0 : $row['bandwidth_usage'];
                
                if (!$isStale) {
                    $threshold = getThresholdForLocation($location);
                    if ($bandwidthUsage[$location] >= $threshold * 0.95) {
                        $alertedLocations[] = [
                            'ip' => $ip,
                            'location' => $location,
                            'bandwidth_usage' => $bandwidthUsage[$location],
                            'threshold' => $threshold
                        ];
                    }
                }
            }
            $stmt->close();
        }
    }
    
    // Fallback for locations not in cache
    foreach ($clientLocations as $ip => $location) {
        if (!isset($locationStatus[$location])) {
            $locationStatus[$location] = false;
            $bandwidthUsage[$location] = 0;
        }
    }
    
    $data = [
        'locations' => $alertedLocations,
        'alerts' => getAlertsFromDatabase($mysqli),
        'alert_counts' => getAlertCountsLast24Hours($mysqli),
        'client' => $isKnoativ ? 'Knoativ' : 'Ziauddin' // For debugging
    ];
    
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Fetch data for initial page load (same logic as AJAX)
$locationStatus = [];
$bandwidthUsage = [];
$alertedLocations = [];

$clientLocations = $locationMap;

if (!empty($clientLocations)) {
    $ipList = array_keys($clientLocations);
    $placeholders = implode(',', array_fill(0, count($ipList), '?'));
    $query = "SELECT ip, location, bandwidth_usage, status, last_updated FROM network_status_cache WHERE ip IN ($placeholders)";
    $stmt = $mysqli->prepare($query);
    
    if ($stmt) {
        $types = str_repeat('s', count($ipList));
        $stmt->bind_param($types, ...$ipList);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $location = $row['location'];
            $ip = $row['ip'];
            $isStale = strtotime($row['last_updated']) < time() - 300;
            $locationStatus[$location] = $isStale ? false : (bool) $row['status'];
            $bandwidthUsage[$location] = $isStale ? 0 : $row['bandwidth_usage'];
            
            if (!$isStale) {
                $threshold = getThresholdForLocation($location);
                if ($bandwidthUsage[$location] >= $threshold * 0.95) {
                    $alertedLocations[] = [
                        'ip' => $ip,
                        'location' => $location,
                        'bandwidth_usage' => $bandwidthUsage[$location],
                        'threshold' => $threshold
                    ];
                }
            }
        }
        $stmt->close();
    }
}

foreach ($clientLocations as $ip => $location) {
    if (!isset($locationStatus[$location])) {
        $locationStatus[$location] = false;
        $bandwidthUsage[$location] = 0;
    }
}

$alerts = getAlertsFromDatabase($mysqli);
$alert_counts = getAlertCountsLast24Hours($mysqli);

$data = [
    'locations' => $alertedLocations,
    'alerts' => $alerts,
    'alert_counts' => $alert_counts,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Alerts Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/local/bootstrap.min.css" rel="stylesheet" onerror="this.href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
    <script src="/local/echarts.min.js" onerror="this.src='https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js'"></script>
    <style>
        body {
            background-color: #1a1a1a;
            color: #f8f9fa;
        }
        .alert-critical {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
        }
        .table-dark {
            background-color: #2c2c2c;
        }
        .status-badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            border-radius: 0.25rem;
        }
        .badge-new {
            background-color: #0d6efd;
        }
        .badge-acknowledged {
            background-color: #6c757d;
        }
        .badge-resolved {
            background-color: #198754;
        }
        :root {
            --dark-bg: #121212;
            --dark-card-bg: #1e1e1e;
            --dark-text: #f8f9fa;
            --dark-text-muted: #adb5bd;
            --dark-border: #333;
            --dark-logo: url('/noclogo1.png');
            --dark-spotcomm-logo: url('/logo1.png');
            --dark-primary: #3b82f6;
            --dark-secondary: #6366f1;
            --dark-success: #10b981;
            --dark-warning: #f59e0b;
            --dark-danger: #ef4444;
            --dark-highlight: #60a5fa;
            --dark-tooltip-bg: #2c2c2c;
            --light-bg: #f8f9fa;
            --light-card-bg: #ffffff;
            --light-text: #212529;
            --light-logo: url('/nclogo.png');
            --light-spotcomm-logo: url('/logo.png');
            --light-text-muted: #6c757d;
            --light-border: #dee2e6;
            --light-primary: #0d6efd;
            --light-secondary: #6c757d;
            --light-success: #198754;
            --light-warning: #ffc107;
            --light-danger: #dc3545;
            --light-highlight: #1a56db;
            --light-tooltip-bg: #ffffff;
            --bg: var(--dark-bg);
            --card-bg: var(--dark-card-bg);
            --text: var(--dark-text);
            --text-muted: var(--dark-text-muted);
            --border: var(--dark-border);
            --primary: var(--dark-primary);
            --secondary: var(--dark-secondary);
            --logo: var(--dark-logo);
            --spotcomm-logo: url('/logo1.png');
            --success: var(--dark-success);
            --warning: var(--dark-warning);
            --danger: var(--dark-danger);
            --highlight: var(--dark-highlight);
            --tooltip-bg: var(--dark-tooltip-bg);
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }
        body.light-mode {
            --bg: var(--light-bg);
            --card-bg: var(--light-card-bg);
            --text: var(--light-text);
            --text-muted: var(--light-text-muted);
            --border: var(--light-border);
            --primary: var(--light-primary);
            --secondary: var(--light-secondary);
            --logo: var(--light-logo);
            --spotcomm-logo: url('/logo.png');
            --success: var(--light-success);
            --warning: var(--light-warning);
            --danger: var(--light-danger);
            --highlight: var(--light-highlight);
            --tooltip-bg: var(--light-tooltip-bg);
        }
        .spotcomm-logo-container {
            background-color: var(--card-bg);
            transition: background-color 0.3s;
            width: 70%;
            justify-content: center;
            display: flex;
            height: 80px;
            background-image: var(--spotcomm-logo);
            background-size: contain;
            background-repeat: no-repeat;
            margin: 370px 2px 0px 40px;
            background-position: center;
        }
        .alert-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
            position: relative;
            background: conic-gradient(var(--danger) 0%, var(--warning) 50%, transparent 50%, transparent 100%);
            transition: background 0.3s ease-in-out;
        }
        .dark-mode .alert-circle {
            background: conic-gradient(#e53e3e 0%, #dd6b20 50%, transparent 50%, transparent 100%);
        }
        .light-mode .alert-circle {
            background: conic-gradient(#e53e3e 0%, #dd6b20 50%, #f8f9fa 50%, #f8f9fa 100%);
        }
        .alert-circle-inner {
            width: 80%;
            height: 80%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: absolute;
        }
        .dark-mode .alert-circle-inner {
            background-color: #2a2a2a;
            color: white;
        }
        .light-mode .alert-circle-inner {
            background-color: #f8f9fa;
            color: #212529;
        }
        .dashboard-header {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: all 0.3s;
        }
        .stat-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: transform 0.3s, background-color 0.3s, border-color 0.3s;
            height: auto;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        .stat-card .card-title {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .stat-card.live .value { color: var(--primary); }
        .stat-card.down .value { color: var(--danger); }
        .stat-card.warning .value { color: var(--warning); }
        .stat-card.info .value { color: var(--highlight); }
        .stat-card.success .value { color: var(--success); }
        .main-dashboard-box {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            transition: background-color 0.3s;
        }
        .inset-box {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
        }
        .location-dot {
            position: relative;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            width: 20px;
            height: 20px;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }
        .status-bar {
            height: 4px;
            border-radius: 2px;
        }
        .status-bar-live { background-color: var(--success); }
        .status-bar-down { background-color: var(--danger); }
        .status-bar-stale { background-color: var(--text-muted); }
        .text-netdata-light { color: var(--text); }
        .text-netdata-dark-gray { color: var(--text-muted); }
        .bg-netdata-dark { background-color: var(--card-bg); }
        .bg-netdata-main-box { background-color: var(--card-bg); }
        .bg-netdata-inner-box { background-color: var(--card-bg); }
        .bg-netdata-tooltip { background-color: var(--tooltip-bg); }
        .border-netdata-dark { border-color: var(--border) !important; }
        .text-netdata-blue { color: var(--primary); }
        .text-netdata-purple { color: var(--secondary); }
        .text-netdata-orange { color: var(--warning); }
        .text-netdata-red { color: var(--danger); }
        .text-netdata-green { color: var(--success); }
        .location-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid var(--border);
            transition: all 0.3s;
            text-decoration: none !important;
            color: inherit;
            display: block;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .location-card:hover {
            background: rgba(var(--primary-rgb), 0.05);
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .location-card.up { border-left-color: var(--success); }
        .location-card.down { border-left-color: var(--danger); }
        .location-card h3 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }
        .location-card .public-ip {
            font-size: 12px;
            background: rgba(var(--primary-rgb), 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            color: var(--primary);
        }
        .location-card .status-badge {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .location-card .status-badge.up {
            background: rgba(var(--success-rgb), 0.1);
            color: var(--success);
        }
        .location-card .status-badge.down {
            background: rgba(var(--danger-rgb), 0.1);
            color: var(--danger);
        }
        .alert-item {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            transition: all 0.2s;
            font-size: 14px;
            background-color: rgba(255, 255, 255, 0.05);
        }
        .light-mode .alert-item {
            background-color: rgba(0, 0, 0, 0.03);
        }
        .alert-item:hover { transform: translateX(3px); }
        .alert-item.warning {
            background: rgba(var(--warning-rgb), 0.1);
            border-left: 3px solid var(--warning);
        }
        .alert-item.danger {
            background: rgba(var(--danger-rgb), 0.1);
            border-left: 3px solid var(--danger);
        }
        .alert-item i {
            margin-right: 10px;
            font-size: 18px;
        }
        .group-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        .group-card h4 {
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            color: var(--highlight);
            font-size: 16px;
            font-weight: 600;
        }
        .group-card h4 i { margin-right: 10px; }
        .gauge-container { height: 120px; width: 100%; }
        .ping-value { font-size: 24px; font-weight: 700; }
        .ping-value.good { color: var(--success); }
        .ping-value.medium { color: var(--warning); }
        .ping-value.bad { color: var(--danger); }
        .ping-box {
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: var(--card-bg);
            border: 1px solid var(--border);
        }
        .ping-box h2 {
            margin-top: 0;
            text-align: center;
            color: var(--text);
        }
        .ping-status {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            padding: 10px 0;
        }
        .ping-label {
            font-weight: bold;
            color: var(--text-muted);
        }
        .ping-value { color: var(--success); }
        .ping-down { color: var(--danger); }
        .btn-custom {
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        .logo {
            width: 100px;
            height: 100px;
            margin: auto;
            display: flex;
            justify-content: center;
        }
        .refresh-btn { 
            background: var(--primary); 
            color: white; 
        }
        .refresh-btn:hover { 
            background: var(--highlight); 
            color: white; 
        }
        .logout-btn { 
            background: var(--danger); 
            color: white; 
        }
        .logout-btn:hover { 
            background: var(--danger); 
            filter: brightness(0.9);
            color: white; 
        }
        .view-all-btn {
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary);
            border: 1px solid var(--primary);
            text-decoration: none;
        }
        .view-all-btn:hover {
            background: rgba(var(--primary-rgb), 0.2);
            color: var(--primary);
        }
        .uptime-bar {
            height: 8px;
            border-radius: 4px;
            background: var(--border);
            overflow: hidden;
            margin-top: 10px;
        }
        .uptime-progress { 
            height: 100%; 
            background: var(--success); 
        }
        .node-stats {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
        }
        .node-stats .up { color: var(--success); }
        .node-stats .down { color: var(--danger); }
        .chart-container { 
            position: relative; 
            height: 250px; 
            width: 100%; 
            background: var(--card-bg);
            border-radius: 8px;
            padding: 10px;
            border: 1px solid var(--border);
        }
        .last-updated {
            font-size: 12px;
            color: var(--text-muted);
            text-align: right;
            margin-top: 10px;
        }
        .nav-tabs { 
            border-bottom: 1px solid var(--border); 
        }
        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: 1px solid transparent;
            border-bottom: none;
            padding: 10px 15px;
            font-weight: 500;
        }
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: var(--primary);
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: transparent;
            border-color: var(--border) var(--border) var(--bg);
            border-bottom: none;
        }
        .tab-content { padding: 20px 0; }
        .metric-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid var(--border);
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        #bandwidthLegend {
            max-height: 100px;
            overflow-y: auto;
            padding: 5px;
            background-color: rgba(var(--card-bg-rgb), 0.7);
            border-radius: 6px;
        }
        #bandwidthLegend div {
            padding: 3px 8px;
            border-radius: 4px;
            background-color: rgba(255, 255, 255, 0.05);
        }
        .light-mode #bandwidthLegend div {
            background-color: rgba(0, 0, 0, 0.05);
        }
        .metric-card h5 {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 10px;
            font-weight: 600;
        }
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text);
        }
        .metric-change {
            font-size: 12px;
            display: flex;
            align-items: center;
        }
        .metric-change.up { color: var(--success); }
        .metric-change.down { color: var(--danger); }
        .metric-change i { 
            margin-right: 5px; 
        }
        .node-health { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            margin-top: 10px; 
        }
        .health-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .health-indicator.good { background: var(--success); }
        .health-indicator.warning { background: var(--warning); }
        .health-indicator.critical { background: var(--danger); }
        .health-label { 
            font-size: 12px; 
            color: var(--text-muted); 
        }
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: -250px;
                top: 0;
                bottom: 0;
                z-index: 1000;
                transition: left 0.3s;
            }
            .sidebar.show { left: 0; }
            .main-content { 
                margin-left: 0; 
                width: 100%; 
                padding: 15px; 
            }
            .sidebar-toggle {
                display: block !important;
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 1050;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
        }
.main-content { margin-left:300px; padding:18px; transition:margin .2s; }
        .sidebar {
            background-color: var(--card-bg);
            border-right: 1px solid var(--border);
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            transition: left 0.3s ease-in-out;
            z-index: 1000;
        }
        .sidebar .nav-link {
            color: var(--text);
            padding: 10px 15px;
            border-radius: 6px;
            margin: 5px 10px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .sidebar .nav-link:hover {
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary);
            transform: translateX(5px);
        }
        .sidebar .nav-link.active {
            background-color: var(--primary);
            color: white;
        }
        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1050;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        .grid-item {
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border);
            padding: 15px;
            transition: all 0.3s;
        }
        .grid-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .grid-item-title {
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }
        .grid-item-actions { display: flex; gap: 5px; }
        .grid-item-action {
            background: rgba(255,255,255,0.1);
            border: none;
            color: var(--text);
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .light-mode .grid-item-action {
            background: rgba(0,0,0,0.05);
        }
        .grid-item-action:hover { 
            background: rgba(255,255,255,0.2); 
        }
        .light-mode .grid-item-action:hover {
            background: rgba(0,0,0,0.1);
        }
        .grid-item-chart { 
            height: 150px; 
            width: 100%; 
        }
        .logo-container {
            width: 100%;
            height: 80px;
            background-image: var(--logo);
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            transition: background-image 0.3s;
        }
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background-color: var(--primary);
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: none;
            transition: all 0.3s;
            z-index: 1000;
        }
        .theme-toggle:hover {
            transform: scale(1.1);
        }
        .theme-toggle i {
            font-size: 1.2rem;
        }
        :root {
            --primary-rgb: 59, 130, 246;
            --success-rgb: 16, 185, 129;
            --warning-rgb: 245, 158, 11;
            --danger-rgb: 239, 68, 68;
            --card-bg-rgb: 30, 30, 30;
        }
        .light-mode {
            --primary-rgb: 13, 110, 253;
            --success-rgb: 25, 135, 84;
            --warning-rgb: 255, 193, 7;
            --danger-rgb: 220, 53, 69;
            --card-bg-rgb: 255, 255, 255;
        }
    </style>
</head>
<body class="dark-mode">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>
    
    <div class="d-flex">
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3">
                <div class="logo-container"></div>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Overview</a></li>
                <li>
    <a href="locations.php" class="nav-link  <?= $current_page == 'locations.php' ? 'active' : '' ?>">
        <i class="fas fa-network-wired"></i>
        <?php 
        if (isset($_SESSION['username']) && $_SESSION['username'] === 'Knoativ') {
            echo 'All Devices';
        } else {
            echo 'All Locations';
        }
        ?>
    </a>
</li>
                <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
                <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
                <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations Analytics</a></li>
                <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location Information</a></li>
                 <?php if (isset($_SESSION['username']) && $_SESSION['username'] === 'Knoativ'): ?>
                <li>
                    <a href="queues.php" class="nav-link <?= $current_page == 'queues.php' ? 'active' : '' ?>">
                        <i class="fas fa-list"></i> Queues
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container mt-auto"></div>
        </div>
        <div class="main-content flex-grow-1">
            <div class="container py-4">
                <div class="card border-dark mb-4">
                   <div class="card-header bg-dark text-white">
    <h5 class="mb-0">
        Bandwidth Alerts (Mbps) 
        <small class="text-muted">- <?php echo $isKnoativ ? 'Knoativ' : 'Ziauddin'; ?></small>
    </h5>
</div>
                </div>
                <div class="card border-dark mb-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Alerts Timeline</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-3" style="height: 200px;">
                            <div id="alertsChart" style="width: 100%; height: 100%;"></div>
                        </div>
                    </div>
                </div>
                <div class="card border-dark mb-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Alerts Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0" id="alertsTable">
                                <thead>
                                    <tr>
                                        <th width="5%">ID</th>
                                        <th width="15%">Location</th>
                                        <th width="10%">Type</th>
                                        <th width="40%">Message</th>
                                        <th width="15%">Status</th>
                                        <th width="15%">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['alerts'] as $alert): ?>
                                        <tr class="alert-critical">
                                            <td><?php echo htmlspecialchars($alert['id']); ?></td>
                                            <td><?php echo htmlspecialchars($alert['location']); ?></td>
                                            <td><span class="badge bg-danger"><?php echo ucfirst($alert['type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($alert['message']); ?></td>
                                            <td><span class="status-badge badge-<?php echo strtolower($alert['status']); ?>">
                                                <?php echo ucfirst($alert['status']); ?>
                                            </span></td>
                                            <td><?php echo htmlspecialchars($alert['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;
            const savedTheme = localStorage.getItem('theme') || 
                             (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (savedTheme === 'light') {
                body.classList.add('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            } else {
                body.classList.add('dark-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            }
            themeToggle.addEventListener('click', function() {
                body.classList.toggle('dark-mode');
                body.classList.toggle('light-mode');
                if (body.classList.contains('dark-mode')) {
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    localStorage.setItem('theme', 'dark');
                } else {
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                    localStorage.setItem('theme', 'light');
                }
            });

            const bandwidthChart = echarts.init(document.getElementById('bandwidthChart'));
            const alertsChart = echarts.init(document.getElementById('alertsChart'));

            function updateCharts(data) {
                const bandwidthOption = {
                    title: { text: '' },
                    tooltip: { trigger: 'axis' },
                    xAxis: {
                        type: 'category',
                        data: ['Current'],
                        axisLabel: { show: false }
                    },
                    yAxis: { type: 'value', name: 'Bandwidth (Mbps)' },
                    series: data.locations.map(loc => ({
                        name: loc.location,
                        type: 'bar',
                        data: [loc.bandwidth_usage],
                        barWidth: '10%',
                        itemStyle: { color: '#dc3545' },
                        markPoint: {
                            data: [{
                                coord: [0, loc.bandwidth_usage],
                                symbol: 'pin',
                                symbolSize: 50,
                                itemStyle: { color: '#dc3545' },
                                label: { show: true, formatter: '{b}: {c} Mbps' }
                            }]
                        },
                        markLine: {
                            data: [{
                                yAxis: loc.threshold * 0.95,
                                lineStyle: { color: '#dc3545' },
                                label: { formatter: 'Threshold: {c} Mbps' }
                            }]
                        }
                    })),
                    legend: { bottom: 0 }
                };
                bandwidthChart.setOption(bandwidthOption);

                const alertsOption = {
                    title: { text: '' },
                    tooltip: { trigger: 'axis' },
                    xAxis: {
                        type: 'category',
                        data: (() => {
                            let times = [];
                            for (let i = 11; i >= 0; i--) {
                                times.push(new Date(Date.now() - (i * 7200 * 1000)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
                            }
                            return times;
                        })()
                    },
                    yAxis: { type: 'value', name: 'Alert Count' },
                    series: [{
                        name: 'Alerts',
                        type: 'line',
                        stack: 'Total',
                        areaStyle: { color: '#07bc0c' },
                        data: data.alert_counts,
                        label: { show: true, position: 'top' }
                    }]
                };
                alertsChart.setOption(alertsOption);

                const tbody = document.querySelector('#alertsTable tbody');
                tbody.innerHTML = data.alerts.map(alert => `
                    <tr class="alert-critical">
                        <td>${alert.id}</td>
                        <td>${alert.location}</td>
                        <td><span class="badge bg-danger">${alert.type.charAt(0).toUpperCase() + alert.type.slice(1)}</span></td>
                        <td>${alert.message}</td>
                        <td><span class="status-badge badge-${alert.status.toLowerCase()}">${alert.status.charAt(0).toUpperCase() + alert.status.slice(1)}</span></td>
                        <td>${alert.created_at}</td>
                    </tr>
                `).join('');
            }

            updateCharts(<?php echo json_encode($data); ?>);

            setInterval(() => {
                fetch('alert.php?ajax=1')
                    .then(response => response.json())
                    .then(data => {
                        updateCharts(data);
                    })
                    .catch(error => console.error('Error fetching data:', error));
            }, 15000);

            document.getElementById('sidebarToggle').addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        });
    </script>
</body>
</html>