<?php
// File: client_detail.php (Updated Version)
// ---------------------------------------------------------------
// 1. BASIC SETTINGS
// ---------------------------------------------------------------
ini_set('max_execution_time', 30);
ini_set('memory_limit', '256M');

if ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error_log.txt');
}

ob_start();
session_start();

// ---------------------------------------------------------------
// 2. AUTHENTICATION
// ---------------------------------------------------------------
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    session_write_close();
    header('Location: login.php');
    ob_end_flush();
    exit;
}
session_write_close();
      require_once('routeros_api.class.php');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------
// 3. UTILITY FUNCTIONS
// ---------------------------------------------------------------
function formatBytes($bytes, $precision = 2)
{
    if ($bytes === 0 || $bytes === null) return '0 B';
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatTime($minutes)
{
    if ($minutes === 0 || $minutes === null) return '0m';
    if ($minutes < 60) return $minutes . 'm';
    $hours = floor($minutes / 60);
    $mins  = $minutes % 60;
    return $hours . 'h ' . $mins . 'm';
}

// ---------------------------------------------------------------
// 4. INPUT & ACCESS CONTROL
// ---------------------------------------------------------------
$clientMac  = $_GET['mac'] ?? '';
$clientName = $_GET['name'] ?? 'Unknown Client';
$isKnoativ  = ($_SESSION['username'] ?? '') === 'Knoativ';

if (!$isKnoativ) {
    header('Location: clients.php');
    exit;
}

// Initialize variables
$clientData = [];
$clientHistory = [];
$bandwidthHistory = [];
$currentClient = getCurrentClient();
$networkId = $currentClient['GWN_NETWORK_ID'] ?? '38678';

// ---------------------------------------------------------------
// 5. FETCH REAL CLIENT DATA FROM GWN API
// ---------------------------------------------------------------
try {
    // Get access token
    $base_url = $currentClient['GWN_BASE_URL'] ?? "https://www.gwn.cloud";
    $appID = $currentClient['GWN_APP_ID'] ?? "667380";
    $secretKey = $currentClient['GWN_SECRET_KEY'] ?? "IkSX55UXZpx4eDEbDtbn64dUYx0ubNs4";
    $username = $currentClient['GWN_USERNAME'] ?? "ccwificloud";
    $password_plain = $currentClient['GWN_PASSWORD'] ?? "compaq123_";
    
    // Helper function to get token
    function get_token_with_password($base_url, $appID, $secretKey, $username, $password_plain) {
        $md5_pass = md5($password_plain);
        $final_pass = hash('sha256', $md5_pass);
        
        $url = $base_url . "/oauth/token";
        $data = [
            'grant_type' => 'password',
            'client_id' => $appID,
            'client_secret' => $secretKey,
            'username' => $username,
            'password' => $final_pass
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return json_decode($res, true);
    }
    
    // Helper function for API calls
    function gwn_oapi_post($base_url, $path, $body_arr, $access_token, $appID, $secretKey) {
        $timestamp = round(microtime(true) * 1000);
        $body = json_encode($body_arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body_hash = hash('sha256', $body);
        
        $signatureStr = "&access_token=$access_token&appID=$appID&secretKey=$secretKey&timestamp=$timestamp&$body_hash&";
        $signature = hash('sha256', $signatureStr);
        
        $url = $base_url . $path . "?access_token=$access_token&appID=$appID&timestamp=$timestamp&signature=$signature";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $res = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($res, true);
    }
    
    // Get access token
    $tokenRes = get_token_with_password($base_url, $appID, $secretKey, $username, $password_plain);
    
    if (!isset($tokenRes['access_token'])) {
        error_log("Failed to get access token: " . json_encode($tokenRes));
        throw new Exception("Failed to authenticate with GWN API");
    }
    
    $access_token = $tokenRes['access_token'];
    $actualMac = $clientMac;
    
    // Clean MAC address
    $actualMac = strtoupper(str_replace(':', '', $actualMac));
    
    error_log("Fetching details for MAC: $actualMac, Network ID: $networkId");
    
    // ---- 1. Get client detailed information ----
    $clientInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/info", [
        'networkId' => intval($networkId),
        'clientId' => $actualMac
    ], $access_token, $appID, $secretKey);
    
    error_log("Client Info API Response: " . json_encode($clientInfo));
    
    if (isset($clientInfo['data'])) {
        $data = $clientInfo['data'];
        $clientData = [
            'name'            => $data['name'] ?? $clientName,
            'status'          => 'online',
            'ipv4'            => $data['ipv4'] ?? 'N/A',
            'ipv6'            => $data['ipv6'] ?? '—',
            'apName'          => 'N/A', // Will get from AP list
            'apId'            => $data['apId'] ?? 'N/A',
            'ssid'            => $data['ssid'] ?? 'N/A',
            'rxBytes'         => $data['rxBytes'] ?? 0,
            'txBytes'         => $data['txBytes'] ?? 0,
            'totalBytes'      => $data['totalBytes'] ?? 0,
            'rxRate'          => $data['rxRate'] ?? 0,
            'txRate'          => $data['txRate'] ?? 0,
            'rssi'            => $data['rssi'] ?? 'N/A',
            'channel'         => $data['channel'] ?? 'N/A',
            'channelClass'    => $data['channelClass'] ?? 'N/A',
            'assoctime'       => $data['assoctime'] ?? 'N/A',
            'lastactiveTime'  => $data['lastactiveTime'] ?? date('Y-m-d H:i:s'),
            'block'           => $data['block'] ?? 1,
            'portalEnable'    => $data['portalEnable'] ?? 0,
            'clientId'        => $actualMac,
            'dhcpManufacture' => $data['dhcpManufacture'] ?? 'N/A',
            'dhcpOs'          => $data['dhcpOs'] ?? 'N/A',
            'vlanId'          => $data['vlanId'] ?? 'N/A'
        ];
        
        // Get AP name if apId is available
        if (!empty($clientData['apId'])) {
            $apList = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
                'networkId' => intval($networkId),
                'pageNum' => 1,
                'pageSize' => 100,
                'filter' => ['showType' => 'all']
            ], $access_token, $appID, $secretKey);
            
            if (isset($apList['data']['result'])) {
                foreach ($apList['data']['result'] as $ap) {
                    if (isset($ap['mac']) && strtoupper($ap['mac']) === strtoupper($clientData['apId'])) {
                        $clientData['apName'] = $ap['name'] ?? $ap['mac'];
                        break;
                    }
                }
            }
        }
        
        error_log("Processed Client Data: " . json_encode($clientData));
    } else {
        error_log("No client data found in API response");
    }
    
    // ---- 2. Get client connection history ----
    $histResponse = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/history/list", [
        'networkId' => intval($networkId),
        'search' => $actualMac,
        'pageNum' => 1,
        'pageSize' => 20,
        'order' => 'lastSeen',
        'type' => 'desc'
    ], $access_token, $appID, $secretKey);
    
    error_log("Client History API Response: " . json_encode($histResponse));
    
    if (isset($histResponse['data']['result'])) {
        $clientHistory = $histResponse['data']['result'];
        error_log("Found " . count($clientHistory) . " history records");
    }
    
    // ---- 3. Get client bandwidth history (last 24 hours) ----
    $bandwidthResponse = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/client/bandwidth", [
        'networkId' => intval($networkId),
        'clientId' => $actualMac,
        'type' => 1 // 1 = 24 hours
    ], $access_token, $appID, $secretKey);
    
    if (isset($bandwidthResponse['data'])) {
        $bandwidthHistory = $bandwidthResponse['data'];
    }
    
} catch (Throwable $e) {
    error_log('client_detail.php exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// Helper function to extract bytes from formatted string
function extractBytes($sizeString)
{
    $units = [
        'B' => 1,
        'KB' => 1024,
        'MB' => 1024 * 1024,
        'GB' => 1024 * 1024 * 1024,
        'TB' => 1024 * 1024 * 1024 * 1024
    ];

    $match = preg_match('/([\d.]+)\s*([KMGTP]?B)/i', $sizeString, $matches);
    if ($match) {
        $value = floatval($matches[1]);
        $unit = strtoupper($matches[2]);
        return $value * ($units[$unit] ?? 1);
    }
    return 0;
}

// ---------------------------------------------------------------
// 6. SAFE DATA ACCESS HELPERS
// ---------------------------------------------------------------
function getClientValue(array $data, string $key, $default = 'N/A')
{
    return array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null
        ? $data[$key]
        : $default;
}

function getClientStatus(array $data): string
{
    $status = getClientValue($data, 'status', 'online');
    return $status === 'online' ? 'status-online' : 'status-online';
}

function getClientStatusText(array $data): string
{
    $status = getClientValue($data, 'status', 'online');
    return ucfirst($status);
}

// Ensure we have at least basic data
if (empty($clientData)) {
    $clientData = [
        'name'            => $clientName,
        'status'          => 'online',
        'ipv4'            => 'N/A',
        'apName'          => 'N/A',
        'ssid'            => 'N/A',
        'rxBytes'         => 0,
        'txBytes'         => 0,
        'totalBytes'      => 0,
        'rxRate'          => 0,
        'txRate'          => 0,
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($clientName); ?> - Client Details</title>

    <!-- Bootstrap + FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
    /* Your existing CSS styles here */
    :root {
        --dark-bg: #121212;
        --dark-card-bg: #1e1e1e;
        --dark-text: #f8f9fa;
        --dark-: #adb5bd;
        --dark-border: #333;
        --dark-primary: #0062ff;
        --dark-secondary: #6366f1;
        --dark-success: #10b981;
        --dark-warning: #f59e0b;
        --dark-danger: #ef4444;
        --dark-highlight: #60a5fa;
        --primary-rgb: 0, 98, 255;
        --success-rgb: 16, 185, 129;
        --warning-rgb: 245, 158, 11;
        --danger-rgb: 239, 68, 68;
    }
 /* Same CSS as before */
    :root {
        --dark-bg: #121212;
        --dark-card-bg: #1e1e1e;
        --dark-text: #f8f9fa;
        --dark-: #adb5bd;
        --dark-border: #333;
        --dark-logo: url('/noclogo1.png');
        --dark-spotcomm-logo: url('/logo1.png');
        --dark-primary: #0062ff;
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
        --light-: #6c757d;
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
        --: var(--dark-);
        --border: var(--dark-border);
        --primary: var(--dark-primary);
        --secondary: var(--dark-secondary);
        --logo: var(--dark-logo);
        --spotcomm-logo: var(--dark-spotcomm-logo);
        --success: var(--dark-success);
        --warning: var(--dark-warning);
        --danger: var(--dark-danger);
        --highlight: var(--dark-highlight);
        --tooltip-bg: var(--dark-tooltip-bg);
        --primary-rgb: 0, 98, 255;
        --success-rgb: 16, 185, 129;
        --warning-rgb: 245, 158, 11;
        --danger-rgb: 239, 68, 68;
        --card-bg-rgb: 30, 30, 30;
    }

    body.light-mode {
        --bg: var(--light-bg);
        --card-bg: var(--light-card-bg);
        --text: var(--light-text);
        --: var(--light-);
        --border: var(--light-border);
        --primary: var(--light-primary);
        --secondary: var(--light-secondary);
        --logo: var(--light-logo);
        --spotcomm-logo: var(--light-spotcomm-logo);
        --success: var(--light-success);
        --warning: var(--light-warning);
        --danger: var(--light-danger);
        --highlight: var(--light-highlight);
        --tooltip-bg: var(--light-tooltip-bg);
        --primary-rgb: 13, 110, 253;
        --success-rgb: 25, 135, 84;
        --warning-rgb: 255, 193, 7;
        --danger-rgb: 220, 53, 69;
        --card-bg-rgb: 255, 255, 255;
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg);
        color: var(--text);
        min-height: 100vh;
        transition: background-color 0.3s, color 0.3s;
        margin: 0;
    }

    .sidebar {
        background-color: var(--card-bg);
        border-right: 1px solid var(--border);
        width: 250px;
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        overflow-y: hidden;
    }

    .sidebar .nav-link {
        color: var(--text);
        padding: 10px 15px;
        border-radius: 6px;
        margin: 5px 10px;
        transition: all 0.2s;
        font-weight: 500;
    }

    .sidebar .nav-link:hover {
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--primary);
    }

    .sidebar .nav-link.active {
        background-color: var(--primary);
        color: white;
    }

    a {
        color: var(--text) !important;
    }

    hr {
        border-color: var(--border);
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
        background-position: center;
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
        background-color: #ffffff;
        color: #000000;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: none;
        transition: all 0.3s;
        z-index: 1000;
    }

    body.light-mode .theme-toggle {
        background-color: #000000;
        color: #ffffff;
    }

    .theme-toggle:hover {
        transform: scale(1.1);
    }

    .theme-toggle i {
        font-size: 1.2rem;
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
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .main-content {
        margin-left: 250px;
        width: calc(100% - 250px);
        padding: 15px;
    }

    @media (max-width: 992px) {
        .sidebar {
            left: -250px;
            transition: left 0.3s;
        }

        .sidebar.show {
            left: 0;
        }

        .sidebar-toggle {
            display: block;
        }

        .main-content {
            margin-left: 0;
            width: 100%;
        }
    }

    .dashboard-header {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transition: all 0.3s;
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--dark-bg);
        color: var(--dark-text);
        min-height: 100vh;
        margin: 0;
        padding: 20px;
    }

    .client-header {
        background: var(--dark-card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--dark-border);
    }

    .stat-card {
        background: var(--dark-card-bg);
        border-radius: 10px;
        padding: 20px;
        border: 1px solid var(--dark-border);
        margin-bottom: 20px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .info-item {
        background: rgba(var(--primary-rgb), .05);
        padding: 15px;
        border-radius: 8px;
        border-left: 4px solid var(--dark-primary);
    }

    .info-label {
        font-size: 12px;
        color: var(--dark-);
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark-text);
    }

    .nav-tabs {
        border-bottom: 1px solid var(--dark-border);
    }

    .nav-tabs .nav-link {
        color: var(--dark-);
        border: none;
        padding: 12px 20px;
        font-weight: 500;
    }

    .nav-tabs .nav-link.active {
        color: var(--dark-primary);
        background: transparent;
        border-bottom: 2px solid var(--dark-primary);
    }

    .chart-container {
        background: var(--dark-card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--dark-border);
    }

    .gwn-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 14px;
    }

    .gwn-table th {
        background: var(--dark-card-bg);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        color: var(--dark-);
        border-bottom: 1px solid var(--dark-border);
    }

    .gwn-table td {
        padding: 12px;
        border-bottom: 1px solid var(--dark-border);
        font-size: 13px;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-online {
        background: rgba(var(--success-rgb), .1);
        color: var(--dark-success);
    }

    .btn-custom {
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all .2s;
        font-size: 14px;
        text-decoration: none;
    }

    .back-btn {
        background: rgba(var(--primary-rgb), .1);
        color: var(--dark-primary);
        border: 1px solid var(--dark-primary);
    }

    .back-btn:hover {
        background: rgba(var(--primary-rgb), .2);
        color: var(--dark-primary);
    }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

        <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>

        <div class="d-flex">
            <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
                <a href="index.php" class="d-flex align-items-center mb-3">
                    <div class="logo-container"></div>
                </a>
                <hr>
                <ul class="nav nav-pills flex-column mb-auto">
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i>
                            Overview</a></li>
                    <li><a href="locations.php" class="nav-link"><i class="fas fa-network-wired"></i> All Locations</a>
                    </li>
                    <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
                    <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
                    <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations
                            Analytics</a></li>
                    <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i>
                            Location
                            Information</a></li>
                    <?php if ($isKnoativ): ?>
                    <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                    <?php endif; ?>
                </ul>
                <div class="spotcomm-logo-container mt-auto"></div>
            </div>

            <div class="main-content">
                <div class="dashboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1><?php echo htmlspecialchars(getClientValue($clientData, 'name', $clientName)); ?></h1>
                            <p class="mb-1">MAC: <?php echo htmlspecialchars($clientMac); ?></p>
                            <p class="mb-1">Status:
                                <span class="status-badge status-online">
                                    <i class="fas fa-circle me-1"></i>
                                    <?php echo getClientStatusText($clientData); ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="clients.php" class="btn-custom back-btn">
                                <i class="fas fa-arrow-left"></i> Back to Clients
                            </a>
                        </div>
                    </div>
                </div>

                <!-- TABS -->
                <ul class="nav nav-tabs" id="clientTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="usage-tab" data-bs-toggle="tab" data-bs-target="#usage"
                            type="button">
                            <i class="fas fa-chart-bar me-2"></i>Usage
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="info-tab" data-bs-toggle="tab" data-bs-target="#info"
                            type="button">
                            <i class="fas fa-info-circle me-2"></i>Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history"
                            type="button">
                            <i class="fas fa-history me-2"></i>History
                        </button>
                    </li>
                </ul>

                <div class="tab-content mt-4" id="clientTabsContent">
                    <!-- ==== USAGE TAB ==== -->
                    <div class="tab-pane fade show active" id="usage" role="tabpanel">
                        <div class="row">
                            <!-- <div class="col-lg-8">
                                <div class="chart-container">
                                    <h4>Bandwidth Usage (Last 24 Hours)</h4>
                                    <canvas id="bandwidthChart" style="width:100%;height:300px;"></canvas>
                                </div>
                            </div> -->
                            <div class="col-lg-12">
                                <div class="stat-card">
                                    <h4>Current Statistics</h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">Total Download</div>
                                            <div class="info-value text-success">
                                                <?php echo formatBytes(getClientValue($clientData, 'rxBytes', 0)); ?>
                                            </div>
                                            <small class="">
                                                <?php echo getClientValue($clientData, 'rxRate', 0); ?> Mbps current
                                            </small>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Total Upload</div>
                                            <div class="info-value text-primary">
                                                <?php echo formatBytes(getClientValue($clientData, 'txBytes', 0)); ?>
                                            </div>
                                            <small class="">
                                                <?php echo getClientValue($clientData, 'txRate', 0); ?> Mbps current
                                            </small>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Total Usage</div>
                                            <div class="info-value text-warning">
                                                <?php echo formatBytes(getClientValue($clientData, 'totalBytes', 0)); ?>
                                            </div>
                                            <small class="">Lifetime total</small>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Connection Time</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'assoctime', 'N/A')); ?>
                                            </div>
                                            <small class="">
                                                Last active:
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'lastactiveTime', 'N/A')); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ==== INFORMATION TAB ==== -->
                    <div class="tab-pane fade" id="info" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="stat-card">
                                    <h4>Basic Information</h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">Client Name</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'name', $clientName)); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">IP Address</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'ipv4', 'N/A')); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">MAC Address</div>
                                            <div class="info-value">
                                                <code><?php echo htmlspecialchars($clientMac); ?></code>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Device Manufacturer</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'dhcpManufacture', 'N/A')); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Operating System</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'dhcpOs', 'N/A')); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="stat-card">
                                    <h4>Connection Information</h4>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">Connected AP</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'apName', 'N/A')); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">AP MAC</div>
                                            <div class="info-value">
                                                <code><?php echo htmlspecialchars(getClientValue($clientData, 'apId', 'N/A')); ?></code>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">SSID</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'ssid', 'N/A')); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Signal Strength (RSSI)</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'rssi', 'N/A')); ?>
                                                dBm</div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Channel</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars(getClientValue($clientData, 'channel', 'N/A')); ?>
                                                (<?php echo getClientValue($clientData, 'channelClass', 2) == 2 ? '2.4GHz' : '5GHz'; ?>)
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ==== HISTORY TAB ==== -->
                    <div class="tab-pane fade" id="history" role="tabpanel">
                        <div class="stat-card">
                            <h4>Connection History</h4>
                            <div class="table-responsive">
                                <table class="gwn-table">
                                    <thead>
                                        <tr>
                                            <th>First Seen</th>
                                            <th>Last Seen</th>
                                            <th>AP MAC</th>
                                            <th>SSID</th>
                                            <th>Download</th>
                                            <th>Upload</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($clientHistory) && is_array($clientHistory)): ?>
                                        <?php foreach ($clientHistory as $h): ?>
                                        <?php
                                        $firstSeen = $h['firstSeen'] ?? 'N/A';
                                        $lastSeen = $h['lastSeen'] ?? 'N/A';
                                        $apId = $h['apId'] ?? 'N/A';
                                        $ssid = $h['ssid'] ?? 'N/A';
                                        $rxBytes = $h['rxBytes'] ?? 0;
                                        $txBytes = $h['txBytes'] ?? 0;
                                        $totalBytes = $h['totalBytes'] ?? 0;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($firstSeen); ?></td>
                                            <td><?php echo htmlspecialchars($lastSeen); ?></td>
                                            <td><code><?php echo htmlspecialchars($apId); ?></code></td>
                                            <td><?php echo htmlspecialchars($ssid); ?></td>
                                            <td class="text-success"><?php echo formatBytes($rxBytes); ?></td>
                                            <td class="text-primary"><?php echo formatBytes($txBytes); ?></td>
                                            <td class="text-warning"><?php echo formatBytes($totalBytes); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center  py-4">
                                                <i class="fas fa-history fa-2x mb-3 d-block"></i>
                                                <p class="mb-0">No connection history available</p>
                                                <small class="">History data will be available from GWN
                                                    API</small>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== JS ==================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Initialize tabs
    const triggerTabList = document.querySelectorAll('#clientTabs button')
    triggerTabList.forEach(triggerEl => {
        const tabTrigger = new bootstrap.Tab(triggerEl)
        triggerEl.addEventListener('click', event => {
            event.preventDefault()
            tabTrigger.show()
        })
    })

    // Bandwidth Chart
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('bandwidthChart')?.getContext('2d');
        if (ctx) {
            // Sample data - Replace with actual API data
            const labels = ['12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00', '02:00',
                '04:00', '06:00', '08:00', '10:00'
            ];
            const downloadData = [12, 19, 8, 15, 22, 18, 25, 12, 6, 14, 21, 16];
            const uploadData = [5, 8, 3, 7, 10, 6, 12, 4, 2, 5, 9, 7];

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                            label: 'Download (Mbps)',
                            data: downloadData,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Upload (Mbps)',
                            data: uploadData,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Bandwidth Usage - Last 24 Hours'
                        },
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Bandwidth (Mbps)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Time'
                            }
                        }
                    }
                }
            });
        }
    });

    // Refresh data every 60 seconds
    setInterval(() => {
        location.reload();
    }, 60000);
    </script>
</body>

</html>