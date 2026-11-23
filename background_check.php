<?php
// background_check.php - Background script to check bandwidth and status
require_once('routeros_api.class.php');
require_once('db_connect.php');

// Enable error reporting for debugging
ini_set('display_errors', 0); // Disable display for background script
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Include dependencies
// Initialize database
$db = Database::getInstance();
$mysqli = $db->getConnection();

// Location to IP mapping
$locationMap = [
    '103.246.108.50' => 'Ziauddin Hospital Korangi Creek',
    '103.246.109.74' => 'ZCR Boat Basin (Clifton)',
    '103.246.109.82' => 'Ziauddin Education Board (ZEB)',
    '103.246.109.98' => 'Nursing - North',
    '103.246.109.106' => 'ZCRS / MT / FM (North)',
    '103.246.109.114' => 'College of Intermediate',
    '103.246.109.122' => 'KDLB - Kemari Hospital',
    '103.246.109.130' => 'College of SLT',
    '103.246.109.138' => 'MD - Home',
    '103.246.109.146' => 'SLT Clinic',
    '115.42.66.56' => 'Spotcomm Office',
    '103.246.109.154' => 'Ziauddin Memorial Hospital',
];

// Thresholds
function getThresholdForLocation($location) {
    $thresholds = [
        'Ziauddin Hospital Korangi Creek' => 500,
        'ZCR Boat Basin (Clifton)' => 600,
        'Ziauddin Education Board (ZEB)' => 400,
        'Nursing - North' => 300,
        'Spotcomm Office' => 1000
    ];
    return $thresholds[$location] ?? 200;
}

// Store alert with duplicate prevention
function storeAlert($location, $message, $type = 'critical') {
    global $mysqli;
    $query = "SELECT id FROM alerts WHERE location = ? AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return false;
    }
    $search_msg = substr($message, 0, 50) . '%';
    $stmt->bind_param("ss", $location, $search_msg);
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
    $stmt->bind_param("sssss", $location, $message, $type, $status, $created_at);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Update cache
function updateCache($ip, $location, $bandwidth_usage, $status) {
    global $mysqli;
    $query = "INSERT INTO network_status_cache (ip, location, bandwidth_usage, status, last_updated) 
              VALUES (?, ?, ?, ?, NOW()) 
              ON DUPLICATE KEY UPDATE bandwidth_usage = ?, status = ?, last_updated = NOW()";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return;
    }
    $stmt->bind_param("ssdiid", $ip, $location, $bandwidth_usage, $status, $bandwidth_usage, $status);
    $stmt->execute();
    $stmt->close();
}

// Ping device using fsockopen
function pingDevice($ip) {
    $port = 80;
    $timeout = 1;
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

// Bandwidth check
function checkBandwidthUsage($ip, $location) {
    global $mysqli;

    try {
        $API = new RouterosAPI();
        $API->timeout = 3; // Reduced timeout for faster execution
        if (!$API->connect($ip, 'spotcomm', 'admin@1234')) {
            $message = "CRITICAL: Failed to connect to $location ($ip)";
            storeAlert($location, $message, 'critical');
            updateCache($ip, $location, 0, false);
            return 0;
        }

        $API->write('/interface/monitor-traffic', false);
        $API->write('=interface=ether1', false);
        $API->write('=once=', true);
        $traffic = $API->read();

        $bandwidth_usage = 0;
        if (!empty($traffic) && isset($traffic[0]['rx-bits-per-second']) && isset($traffic[0]['tx-bits-per-second'])) {
            $rx_bits = (int)$traffic[0]['rx-bits-per-second'];
            $tx_bits = (int)$traffic[0]['tx-bits-per-second'];
            $bandwidth_usage = round(($rx_bits + $tx_bits) / 1000000, 2);
            $threshold = getThresholdForLocation($location);
            if ($bandwidth_usage >= $threshold * 0.95) {
                $message = "CRITICAL: Bandwidth usage at $location ($bandwidth_usage Mbps) exceeds 95% of threshold ($threshold Mbps)";
                storeAlert($location, $message, 'critical');
            }
        }

        $API->disconnect();
        updateCache($ip, $location, $bandwidth_usage, true);
        return $bandwidth_usage;
    } catch (Exception $e) {
        $message = "CRITICAL: Connection failed to $location ($ip): " . $e->getMessage();
        storeAlert($location, $message, 'critical');
        updateCache($ip, $location, 0, false);
        return 0;
    }
}

// Check location status
function checkLocationStatus($ip, $location) {
    global $mysqli;

    try {
        if (!pingDevice($ip)) {
            $message = "CRITICAL: $location is not responding to ping";
            storeAlert($location, $message, 'critical');
            updateCache($ip, $location, 0, false);
            return false;
        }

        $API = new RouterosAPI();
        $API->timeout = 3;
        if (!$API->connect($ip, 'spotcomm', 'admin@1234')) {
            $message = "CRITICAL: $location API connection failed";
            storeAlert($location, $message, 'critical');
            updateCache($ip, $location, 0, false);
            return false;
        }

        $API->write('/system/resource/getall');
        $response = $API->read();
        $API->disconnect();

        if (empty($response)) {
            $message = "CRITICAL: $location is not responding properly";
            storeAlert($location, $message, 'critical');
            updateCache($ip, $location, 0, false);
            return false;
        }

        $cpu_load = $response[0]['cpu-load'] ?? 0;
        if ($cpu_load > 90) {
            $message = "CRITICAL: High CPU load at $location ($cpu_load%)";
            storeAlert($location, $message, 'critical');
        }

        updateCache($ip, $location, 0, true);
        return true;
    } catch (Exception $e) {
        $message = "CRITICAL: $location connection error: " . $e->getMessage();
        storeAlert($location, $message, 'critical');
        updateCache($ip, $location, 0, false);
        return false;
    }
}

// Process all locations
foreach ($locationMap as $ip => $location) {
    checkLocationStatus($ip, $location);
    checkBandwidthUsage($ip, $location);
}

// Close database connection
$db->closeConnection();
?>