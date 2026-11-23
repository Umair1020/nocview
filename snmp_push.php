<?php
// File: snmp_push.php
// Receive endpoint for the 24/7 poller (snmp_pusher.php). This is the piece that
// keeps Sangfor bandwidth history filling while nobody has the dashboard open.
//
// POST (application/json) with header X-NocView-Token: <token>
//   { "sent_at": "...", "results": { "<ip>": { "status": "up", "rx_mbps": 12.3, ... } } }
//
// Response: {"ok":true,"stored":4,"logged":2,"rejected":[],"at":"<server time>"}
//
// Requires push_config.local.php in 'receive' mode (see push_config.example.php).
// Without a token this endpoint answers 403, so it can never be used as an open
// "write fake traffic into bandwidth_logs" API.

// Keep any notice/warning from included files out of the JSON body.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_socket_timeout', 5);

require_once __DIR__ . '/push_lib.php';
require_once __DIR__ . '/push_ingest_lib.php';
require_once __DIR__ . '/config.php';       // $clientConfig (Sangfor sites + interfaces)

// A database hiccup must answer 503 (the poller retries next tick) instead of
// killing the request with an HTML fatal error.
define('NOCVIEW_DB_SOFT_FAIL', true);
$dbReady = true;
try {
    require_once __DIR__ . '/db_connect.php';   // logBandwidthSampleIfDue()
} catch (Throwable $e) {
    $dbReady = false;
    error_log('snmp_push database unavailable: ' . $e->getMessage());
}

date_default_timezone_set('Asia/Karachi');
// config.php turns display_errors on for page development; never in this endpoint.
ini_set('display_errors', '0');

function pushRespond($status, array $payload) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- gatekeeping
if (nocviewPushMode() !== 'receive') {
    pushRespond(403, ['ok' => false, 'error' => 'push mode not enabled on this server']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pushRespond(405, ['ok' => false, 'error' => 'method not allowed']);
}

$givenToken = (string)($_SERVER['HTTP_X_NOCVIEW_TOKEN'] ?? '');
if ($givenToken === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($auth, 'Bearer ') === 0) {
        $givenToken = trim(substr($auth, 7));
    }
}
if (!nocviewPushTokenMatches($givenToken)) {
    pushRespond(401, ['ok' => false, 'error' => 'invalid token']);
}

// ---------------------------------------------------------------- payload
$rawBody = file_get_contents('php://input');
$decoded = pushDecodeBody($rawBody);
if (!$decoded['ok']) {
    pushRespond(400, ['ok' => false, 'error' => $decoded['error']]);
}

$targets = pushAllowedTargets($clientConfig);
if ($targets === []) {
    pushRespond(400, ['ok' => false, 'error' => 'no configured targets']);
}

if (!$dbReady) {
    pushRespond(503, ['ok' => false, 'error' => 'database unavailable']);
}

$now = time();

try {
    $result = pushIngestResults(
        $decoded['results'],
        $targets,
        $now,
        function ($ip) {
            return nocviewPushGetAny($ip);
        },
        function (array $entries) use ($now) {
            return nocviewPushStore($entries, $now);
        },
        function ($name, $iface, array $entry) {
            // Same dedupe used by the cron collector and the live page: one row
            // per location/interface per 45 s, and only for a real measurement.
            return logBandwidthSampleIfDue(
                $name,
                $iface,
                $entry['rx_mbps'],
                $entry['tx_mbps'],
                $entry['status'],
                NOCVIEW_PUSH_HISTORY_GAP
            );
        }
    );
} catch (Throwable $e) {
    error_log('snmp_push ingest error: ' . $e->getMessage());
    pushRespond(500, ['ok' => false, 'error' => 'ingest failed']);
}

// Keep the poller's own health report (host, mode, interval, failures) so the
// status page can show liveness even though the poller runs on another machine.
if (!empty($decoded['poller'])) {
    nocviewPushMetaWrite($decoded['poller'], $now);
}

// Alert lifecycle: sites whose push says "up" are healthy right now — resolve
// their open alerts so the dashboard reflects ongoing incidents only.
$resolvedAlerts = 0;
if ($dbReady && function_exists('resolveAlertsForLocations')) {
    $upNames = [];
    foreach ($decoded['results'] as $ip => $entry) {
        $status = strtolower(trim((string)(is_array($entry) ? ($entry['status'] ?? '') : '')));
        if ($status === 'up' && isset($targets[$ip]['name'])) {
            $upNames[] = $targets[$ip]['name'];
        }
    }
    if ($upNames !== []) {
        try {
            $resolvedAlerts = resolveAlertsForLocations(
                $upNames,
                function_exists('nocviewConnectivityAlertPatterns') ? nocviewConnectivityAlertPatterns() : null
            );
        } catch (Throwable $e) {
            error_log('snmp_push alert resolve error: ' . $e->getMessage());
        }
    }
}

pushRespond(200, [
    'ok' => true,
    'stored' => $result['stored'],
    'logged' => $result['logged'],
    'rejected' => $result['skipped'],
    'resolved' => $resolvedAlerts,
    'at' => date('c'),
]);
