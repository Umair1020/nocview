<?php
// File: background_check.php
// Background script: checks every MikroTik location with the RouterOS API (the
// same source of truth as the live dashboard) and records alerts for real
// failures. Intended to run from the system cron every 1-5 minutes.
//
// Sangfor firewalls are deliberately NOT checked here: they do not speak the
// RouterOS API, so this check could never pass and filled the alerts table
// with hundreds of false "Failed to connect" criticals. Sangfor sites are
// monitored by ICMP + read-only SNMP through the push collector instead
// (see docs/24x7-collector.md).
//
// Alerts are incident-based: one open alert per location per condition, and it
// is resolved automatically once the condition clears. 'acknowledged' rows are
// left for humans; nothing is ever deleted.

require_once('routeros_api.class.php');
require_once('db_connect.php');
require_once('config.php');

// Background script: log errors, never print them.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Initialize database
$db = Database::getInstance();
$mysqli = $db->getConnection();

// MikroTik targets (IP => name) and their per-client RouterOS credentials,
// derived from config.php so a credential change only has to happen in one place.
$locationMap = [];
$routerCreds = [];
foreach ($clientConfig as $client) {
    if (!is_array($client)) {
        continue;
    }
    $user = (string)($client['MT_USER'] ?? '');
    $pass = (string)($client['MT_PASS'] ?? '');
    foreach (($client['locationMap'] ?? []) as $ip => $name) {
        if (isset($locationMap[$ip])) {
            continue; // first configured client wins on duplicates
        }
        $locationMap[$ip] = (string)$name;
        $routerCreds[$ip] = [$user, $pass];
    }
}

// Thresholds (bandwidth, in Mbps) - same defaults as alert.php
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

// Store an alert. Incident-based dedup: at most one OPEN ('new') alert per
// location + message. Once resolved (automatically on recovery, or manually),
// the next failure opens a fresh alert. This replaces the old 5-minute time
// window, which let hundreds of duplicate "still down" alerts pile up.
function storeAlert($location, $message, $type = 'critical') {
    global $mysqli;
    $query = "SELECT id FROM alerts WHERE location = ? AND message LIKE ? AND status = 'new' LIMIT 1";
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
    if (!$result) {
        error_log("Insert failed: " . $mysqli->error);
    }
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
    $stmt->bind_param("ssdidi", $ip, $location, $bandwidth_usage, $status, $bandwidth_usage, $status);
    $stmt->execute();
    $stmt->close();
}

// Close the location's open alerts for the conditions this script can prove
// have cleared (connectivity, high CPU, bandwidth over threshold).
function resolveClearedAlerts($location, $cpuLoad = null, $bandwidthUsage = null) {
    if (function_exists('resolveAlertsForLocations')) {
        $patterns = function_exists('nocviewConnectivityAlertPatterns') ? nocviewConnectivityAlertPatterns() : null;
        resolveAlertsForLocations([$location], $patterns);
        if ($cpuLoad !== null && $cpuLoad <= 90) {
            resolveAlertsForLocations([$location], 'High CPU load at');
        }
        if ($bandwidthUsage !== null) {
            $threshold = getThresholdForLocation($location);
            if ($bandwidthUsage < $threshold * 0.95) {
                resolveAlertsForLocations([$location], 'Bandwidth usage at');
            }
        }
    }
}

// RouterOS credentials for an IP (first configured client wins).
function routerCredentials($ip) {
    global $routerCreds;
    return $routerCreds[$ip] ?? ['', ''];
}

// Bandwidth check
function checkBandwidthUsage($ip, $location) {
    global $mysqli;

    try {
        [$user, $pass] = routerCredentials($ip);
        $API = new RouterosAPI();
        $API->timeout = 3; // Reduced timeout for faster execution
        if ($user === '' || !$API->connect($ip, $user, $pass)) {
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
        $thresholdCleared = null;
        if (!empty($traffic) && isset($traffic[0]['rx-bits-per-second']) && isset($traffic[0]['tx-bits-per-second'])) {
            $rx_bits = (int)$traffic[0]['rx-bits-per-second'];
            $tx_bits = (int)$traffic[0]['tx-bits-per-second'];
            $bandwidth_usage = round(($rx_bits + $tx_bits) / 1000000, 2);
            $threshold = getThresholdForLocation($location);
            if ($bandwidth_usage >= $threshold * 0.95) {
                $message = "CRITICAL: Bandwidth usage at $location ($bandwidth_usage Mbps) exceeds 95% of threshold ($threshold Mbps)";
                storeAlert($location, $message, 'critical');
                $thresholdCleared = false;
            } else {
                $thresholdCleared = true;
            }
        }

        $API->disconnect();
        updateCache($ip, $location, $bandwidth_usage, true);
        resolveClearedAlerts($location, null, $thresholdCleared === true ? $bandwidth_usage : null);
        return $bandwidth_usage;
    } catch (Exception $e) {
        $message = "CRITICAL: Connection failed to $location ($ip): " . $e->getMessage();
        storeAlert($location, $message, 'critical');
        updateCache($ip, $location, 0, false);
        return 0;
    }
}

// Check location status. The RouterOS API login is the liveness signal -
// the same check the live dashboard uses - so a router without the web/www
// service enabled is not falsely reported as down.
function checkLocationStatus($ip, $location) {
    global $mysqli;

    try {
        [$user, $pass] = routerCredentials($ip);
        $API = new RouterosAPI();
        $API->timeout = 3;
        if ($user === '' || !$API->connect($ip, $user, $pass)) {
            $message = "CRITICAL: Failed to connect to $location ($ip)";
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

        $cpu_load = (int)($response[0]['cpu-load'] ?? 0);
        if ($cpu_load > 90) {
            $message = "CRITICAL: High CPU load at $location ($cpu_load%)";
            storeAlert($location, $message, 'critical');
        }

        updateCache($ip, $location, 0, true);
        resolveClearedAlerts($location, $cpu_load, null);
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
