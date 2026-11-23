<?php
// File: update_cache.php
// Live status of every MikroTik location (and Knoativ GWN access points).
//
// Real-time by design:
//   * every request queries the routers - there is no file cache and no stored snapshot
//   * a value is either measured or reported as missing (0 / null + "reason"); nothing is simulated
//   * all router reachability checks run in parallel, so one slow/down router does not delay the others
//
// Used by: index.php, locations.php, heatmap.php, clients.php (poll every few seconds from the browser).

error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Clear any output that may have been produced before our JSON
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

ini_set('max_execution_time', 90);
ini_set('default_socket_timeout', 5);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Require a logged-in session: this endpoint returns router data
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Session expired, please log in again', 'success' => false]);
    exit;
}

require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';   // also releases the session lock

require_once __DIR__ . '/live_snapshot.php';
set_time_limit(120);   // config.php sets 30 s; the sequential fallback can need more

// ================== REQUEST ==================
try {
    $username = $_SESSION['username'] ?? 'ziauddin';
    $isKnoativ = ($username === 'Knoativ');
    $currentClient = getClientForUsername($username);

    $liveData = buildLiveSnapshot($currentClient, $isKnoativ);

    $alertsData = fetchActiveAlerts($currentClient['locationMap']);
    $liveData['alerts'] = $alertsData['alerts'];
    $liveData['stats']['active_alerts'] = $alertsData['active'];
    $liveData['stats']['critical_alerts'] = $alertsData['critical'];
    $liveData['stats']['warning_alerts'] = $alertsData['warning'];

    $liveData['timestamp'] = time();
    $liveData['checked_at'] = date('c');
    $liveData['success'] = true;
    $liveData['hosting_mode'] = 'live';

    ob_end_clean();
    echo json_encode($liveData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (Throwable $e) {
    error_log('update_cache error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load dashboard data', 'success' => false, 'timestamp' => time()]);
}
