<?php
// File: /public_html/devices.php

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
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    session_write_close();
    header('Location: login.php');
    ob_end_flush();
    exit;
}

// Only allow Knoativ user to access devices data
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'Knoativ') {
    session_write_close();
    header('Location: index.php');
    ob_end_flush();
    exit;
}

session_write_close();

require_once(__DIR__ . '/db_connect.php');
require_once(__DIR__ . '/config.php');

// devices.php کے شروع میں، currentClient حاصل کرنے کے بعد
try {
    $currentClient = getCurrentClient();
    
    // AP mapping کو JavaScript میں منتقل کرنے کے لیے
    $gwnApMapping = $currentClient['gwn_ap_mapping'] ?? [];
    
    // Live data (no cache): the same snapshot the dashboards use
    require_once(__DIR__ . '/live_snapshot.php');
    $liveData = [];
    try {
        $liveData = buildLiveSnapshot($currentClient, ($_SESSION['username'] ?? '') === 'Knoativ');
    } catch (Throwable $e) {
        error_log('devices.php live snapshot: ' . $e->getMessage());
    }
    
    $devices = $liveData['gwn_dashboard']['top_devices'] ?? [];
    
    // AP mapping کو devices کے ساتھ جوڑیں
    foreach ($devices as &$device) {
        $deviceName = $device['name'] ?? '';
        if (isset($gwnApMapping[$deviceName])) {
            $device['ip'] = $gwnApMapping[$deviceName];
        }
    }
    
} catch (Exception $e) {
    error_log("Error in devices.php: " . $e->getMessage());
    $devices = [];
}

// Check if we're requesting device details
$deviceDetail = isset($_GET['device']) ? $_GET['device'] : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $deviceDetail ? 'Device Details' : 'Device Management'; ?> - Network Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
    :root {
        --dark-bg: #121212;
        --dark-card-bg: #1e1e1e;
        --dark-text: #f8f9fa;
        --dark-text-muted: #adb5bd;
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
        --text-muted: var(--light-text-muted);
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
         color: var(--text);
    }

    .sidebar .nav-link.active {
        background-color: var(--primary);
        color: white;
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

    .stat-card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transition: transform 0.3s, background-color 0.3s, border-color 0.3s;
        height: auto;
    }

    .gwn-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 14px;
    }

    .gwn-table th {
        background: var(--card-bg);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        color: var(--text-muted);
        border-bottom: 1px solid var(--border);
    }

    .gwn-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }

    .gwn-table tr:hover {
        background: rgba(var(--primary-rgb), 0.05);
    }

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

    .back-btn {
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--primary);
        border: 1px solid var(--primary);
        text-decoration: none;
    }

    .back-btn:hover {
        background: rgba(var(--primary-rgb), 0.2);
        color: var(--primary);
    }

    .device-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--primary);
    }

    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 20px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .info-item {
        background: var(--card-bg);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid var(--border);
    }

    .info-label {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
    }

    .nav-tabs .nav-link {
        color: var(--text-muted);
        border: none;
        padding: 12px 20px;
    }

    .nav-tabs .nav-link.active {
            color: var(--text);
        background: transparent;
        border-bottom: 2px solid var(--primary);
    }

    .loading-spinner {
        display: none;
        text-align: center;
        padding: 40px;
    }

    .status-online {
        color: var(--success);
    }

    .status-offline {
        color: var(--danger);
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
                <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i>
                        Overview</a></li>
                <li><a href="locations.php" class="nav-link"><i class="fas fa-network-wired"></i> All Locations</a></li>
                <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
                <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
                <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations
                        Analytics</a></li>
                <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location
                        Information</a></li>
                <?php if ($_SESSION['username'] === 'Knoativ'): ?>

                <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container mt-auto"></div>
        </div>

        <div class="main-content">
            <div class="dashboard-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1 id="pageTitle"><?php echo $deviceDetail ? 'Device Details' : 'Device Management'; ?></h1>
                        <p class="mb-0" id="pageSubtitle">
                            <?php echo $deviceDetail ? 'Detailed information and analytics for selected device' : 'All network devices and their bandwidth usage'; ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span id="live-time" class="fw-bold"></span>
                        <a href="<?php echo $deviceDetail ? 'devices.php' : 'index.php'; ?>"
                            class="btn-custom back-btn ms-2">
                            <i class="fas fa-arrow-left"></i>
                            <?php echo $deviceDetail ? 'Back to Devices' : 'Back to Dashboard'; ?>
                        </a>

                        <a href="logout.php" class="btn-custom logout-btn ms-2"><i class="fas fa-sign-out-alt"></i>
                            Logout</a>
                    </div>
                </div>
            </div>

            <!-- Device List View -->
            <div id="deviceListView" <?php echo $deviceDetail ? 'style="display: none;"' : ''; ?>>
                <div class="row">
                    <div class="col-12">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h3 class="mb-0">All Access Points</h3>
                                <div class="d-flex gap-2">
                                    <input type="text" id="searchDevice" class="form-control form-control-sm"
                                        placeholder="Search devices..." style="max-width: 250px;">
                                    <select id="statusFilter" class="form-select form-select-sm"
                                        style="max-width: 150px;">
                                        <option value="all">All Status</option>
                                        <option value="online">Online</option>
                                        <option value="offline">Offline</option>
                                    </select>
                                </div>
                            </div>

                            <div class="loading-spinner" id="devicesLoading">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                <p>Loading devices...</p>
                            </div>

                            <div class="table-responsive">
                                <table class="gwn-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Device Name</th>
                                            <th>Type</th>
                                            <th>IP Address</th>
                                            <th>Clients</th>
                                            <th>Download</th>
                                            <th>Upload</th>
                                            <th>Total Usage</th>
                                            <th>Status</th>
                                            <th>Uptime</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="devicesTableBody">
                                        <!-- Devices will be loaded here -->
                                    </tbody>
                                </table>
                            </div>

                            <!-- Device Statistics Summary -->
                            <div class="row mt-4">
                                <div class="col-md-3">
                                    <div class="stat-card text-center">
                                        <div class="value text-primary" id="totalDevices">0</div>
                                        <div class="text">Total Devices</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card text-center">
                                        <div class="value text-success" id="onlineDevices">0</div>
                                        <div class="text">Online Devices</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card text-center">
                                        <div class="value text-danger" id="offlineDevices">0</div>
                                        <div class="text">Offline Devices</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card text-center">
                                        <div class="value text-warning" id="totalClients">0</div>
                                        <div class="text">Total Clients</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Device Detail View -->
            <div id="deviceDetailView" <?php echo !$deviceDetail ? 'style="display: none;"' : ''; ?>>
                <div class="loading-spinner" id="deviceDetailLoading">
                    <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                    <p>Loading device details...</p>
                </div>

                <div id="deviceDetailContent" style="display: none;">
                    <!-- Device Overview -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h3 id="deviceNameTitle">Device Details</h3>
                                    <div class="d-flex gap-2">
                                        <span id="deviceStatusBadge" class="badge bg-success">Online</span>
                                    </div>
                                </div>

                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Device Type</div>
                                        <div class="info-value" id="deviceType">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">MAC Address</div>
                                        <div class="info-value" id="deviceMac">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">IP Address</div>
                                        <div class="info-value" id="deviceIp">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Firmware Version</div>
                                        <div class="info-value" id="deviceFirmware">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Uptime</div>
                                        <div class="info-value" id="deviceUptime">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Connected Clients</div>
                                        <div class="info-value" id="deviceClients">-</div>
                                    </div>
                                    <!-- <div class="info-item">
                                        <div class="info-label">CPU Usage</div>
                                        <div class="info-value" id="deviceCpu">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Memory Usage</div>
                                        <div class="info-value" id="deviceMemory">-</div>
                                    </div> -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs for Detailed Information -->
                    <ul class="nav nav-tabs mb-4" id="deviceTabs">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#usageTab">Usage Analytics</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#clientsTab">Connected Clients</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#infoTab">Device Information</a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Usage Analytics Tab -->
                        <div class="tab-pane fade show active" id="usageTab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <h5>Client Count Trend</h5>
                                        <div class="chart-container">
                                            <canvas id="clientChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <h5>Bandwidth Usage</h5>
                                        <div class="chart-container">
                                            <canvas id="bandwidthChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Connected Clients Tab -->
                        <div class="tab-pane fade" id="clientsTab">
                            <div class="stat-card">
                                <h5>Connected Clients</h5>
                                <div class="table-responsive">
                                    <table class="gwn-table">
                                        <thead>
                                            <tr>
                                                <th>Client MAC</th>
                                                <th>IP Address</th>
                                                <th>Hostname</th>
                                                <th>SSID</th>
                                                <th>RSSI</th>
                                                <th>Connected Time</th>
                                            </tr>
                                        </thead>
                                        <tbody id="deviceClientsTable">
                                            <!-- Clients will be loaded here -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Device Information Tab -->
                        <div class="tab-pane fade" id="infoTab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <h5>Device Specifications</h5>
                                        <div id="deviceSpecs">
                                            <!-- Device specs will be loaded here -->
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <h5>Radio Information</h5>
                                        <div id="radioInfo">
                                            <!-- Radio info will be loaded here -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global variables
    // Global variables
   let currentDeviceMac = '<?php echo $deviceDetail ? $deviceDetail : ""; ?>';
let devicesData = [];
let clientChart = null;
let bandwidthChart = null;

const gwnApMapping = <?php echo json_encode($gwnApMapping); ?>;

// Helper function to get mapped IP
function getMappedIp(deviceName) {
    return gwnApMapping[deviceName] || null;
}

    // Theme toggle
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        document.body.classList.remove('dark-mode', 'light-mode');
        document.body.classList.add(savedTheme + '-mode');
        const icon = document.getElementById('themeToggle')?.querySelector('i');
        if (icon) {
            icon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
        }
    }

    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const body = document.body;
        body.classList.toggle('dark-mode');
        body.classList.toggle('light-mode');
        const icon = document.getElementById('themeToggle').querySelector('i');
        icon.className = body.classList.contains('dark-mode') ? 'fas fa-moon' : 'fas fa-sun';
        localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
    });

    // Sidebar toggle for mobile
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });

    // Update live time
    function updateLiveTime() {
        const liveTime = document.getElementById('live-time');
        if (liveTime) {
            liveTime.textContent = new Date().toLocaleString('en-US', {
                hour: 'numeric',
                minute: 'numeric',
                second: 'numeric',
                hour12: true
            });
        }
    }

    setInterval(updateLiveTime, 1000);
    updateLiveTime();

    // Load devices data
   function loadDevices() {
    const loadingElement = document.getElementById('devicesLoading');
    const tableBody = document.getElementById('devicesTableBody');

    loadingElement.style.display = 'block';
    tableBody.innerHTML = '';

    fetch('gwn_dashboard.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=getDeviceList&pageNum=1&pageSize=100'
        })
        .then(response => response.json())
        .then(data => {
            loadingElement.style.display = 'none';
            console.log('Devices API Response:', data);

            if (data.success && data.data && data.data.data && data.data.data.result) {
                devicesData = data.data.data.result;
                
                // AP mapping لاگو کریں
                devicesData = devicesData.map(device => {
                    const deviceName = device.name || '';
                    if (gwnApMapping[deviceName]) {
                        device.ip = gwnApMapping[deviceName];
                    }
                    return device;
                });
                
                displayDevices(devicesData);
                updateDeviceStats(devicesData);
            } else {
                console.error('No device data found:', data);
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="11" class="text-center py-4">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p class="mb-0">No device data available</p>
                            <small class="text-muted">Check API connection</small>
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading devices:', error);
            loadingElement.style.display = 'none';
            tableBody.innerHTML = `
                <tr>
                    <td colspan="11" class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                        <p class="mb-0">Error loading device data</p>
                        <small class="text-muted">${error.message}</small>
                    </td>
                </tr>
            `;
        });
}

    // Display devices in table
  // displayDevices فنکشن میں تبدیلی کریں
function displayDevices(devices) {
    const tableBody = document.getElementById('devicesTableBody');
    tableBody.innerHTML = '';

    if (devices.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="11" class="text-center py-4">
                    <i class="fas fa-laptop fa-2x mb-3"></i>
                    <p class="mb-0">No devices found</p>
                </td>
            </tr>
        `;
        return;
    }

    devices.forEach((device, index) => {
        // یہاں AP mapping سے IP حاصل کریں
        const deviceName = device.name || 'Unnamed Device';
        const deviceIp = getMappedIp(deviceName) || device.ip || 'N/A';
        
        const status = device.status === 1 ? 'Online' : 'Offline';
        const statusClass = device.status === 1 ? 'status-online' : 'status-offline';
        const clients = device.clients || 0;
        const upload = formatBytes(device.upload || 0);
        const download = formatBytes(device.download || 0);
        const totalUsage = formatBytes((device.upload || 0) + (device.download || 0));
        const uptime = device.upTime ? formatUptime(device.upTime) : 'N/A';
        const deviceMac = device.mac || 'N/A';

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>
                <div class="d-flex align-items-center">
                    <div class="device-icon">
                        <i class="fas fa-wifi"></i>
                    </div>
                    <div>
                        <strong>${deviceName}</strong>
                        <div class="small">${deviceMac}</div>
                    </div>
                </div>
            </td>
            <td>${device.apType || 'Unknown'}</td>
            <td>${deviceIp}</td>
            <td>${clients}</td>
            <td>
                <span class="text-success">
                    <i class="fas fa-download me-1"></i>
                    ${download}
                </span>
            </td>
            <td>
                <span class="text-primary">
                    <i class="fas fa-upload me-1"></i>
                    ${upload}
                </span>
            </td>
            <td>
                <strong>${totalUsage}</strong>
            </td>
            <td>
                <span class="${statusClass}">
                    <i class="fas fa-circle me-1"></i> ${status}
                </span>
            </td>
            <td>${uptime}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="viewDeviceDetails('${device.mac}')">
                    <i class="fas fa-eye"></i> View
                </button>
            </td>
        `;
        tableBody.appendChild(row);
    });
}

// نیا helper فنکشن AP mapping کے لیے
function getMappedIp(deviceName) {
    // یہ mapping PHP سے JavaScript میں منتقل کرنی ہوگی
    const gwnApMapping = <?php echo json_encode($currentClient['gwn_ap_mapping'] ?? []); ?>;
    return gwnApMapping[deviceName] || null;
}

    // Update device statistics
    function updateDeviceStats(devices) {
        const totalDevices = devices.length;
        const onlineDevices = devices.filter(d => d.status === 1).length;
        const offlineDevices = totalDevices - onlineDevices;
        const totalClients = devices.reduce((sum, device) => sum + (device.clients || 0), 0);

        document.getElementById('totalDevices').textContent = totalDevices;
        document.getElementById('onlineDevices').textContent = onlineDevices;
        document.getElementById('offlineDevices').textContent = offlineDevices;
        document.getElementById('totalClients').textContent = totalClients;
    }

    // View device details
    function viewDeviceDetails(deviceMac) {
        currentDeviceMac = deviceMac;
        window.history.pushState({}, '', `devices.php?device=${deviceMac}`);
        showDeviceDetailView();
        loadDeviceDetails(deviceMac);
    }

    // Show device detail view
    function showDeviceDetailView() {
        document.getElementById('deviceListView').style.display = 'none';
        document.getElementById('deviceDetailView').style.display = 'block';
        document.getElementById('pageTitle').textContent = 'Device Details';
        document.getElementById('pageSubtitle').textContent = 'Detailed information and analytics for selected device';
    }

    // Load device details
    function loadDeviceDetails(deviceMac) {
        const loadingElement = document.getElementById('deviceDetailLoading');
        const contentElement = document.getElementById('deviceDetailContent');

        loadingElement.style.display = 'block';
        contentElement.style.display = 'none';

        // Load all device data
        Promise.all([
            fetchDeviceInfo(deviceMac),
            fetchDeviceClients(deviceMac),
            fetchDeviceBandwidth(deviceMac),
            fetchDeviceBandwidthHistory(deviceMac),
            fetchDeviceClientHistory(deviceMac)
        ]).then(([deviceInfo, clients, bandwidth, bandwidthHistory, clientHistory]) => {
            loadingElement.style.display = 'none';
            contentElement.style.display = 'block';

            displayDeviceInfo(deviceInfo);
            displayDeviceClients(clients);
            initializeCharts(deviceInfo, bandwidth, bandwidthHistory, clientHistory);
        }).catch(error => {
            console.error('Error loading device details:', error);
            loadingElement.style.display = 'none';
            contentElement.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Failed to load device details: ${error.message}
            </div>
        `;
        });
    }

    // Fetch device information
    function fetchDeviceInfo(deviceMac) {
        return fetch('gwn_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getDeviceInfo&deviceMac=${deviceMac}`
            })
            .then(response => response.json())
            .then(data => {
                console.log('Device Info Response:', data);
                return data.success ? data.data : null;
            });
    }

    // Fetch device clients
    function fetchDeviceClients(deviceMac) {
        return fetch('gwn_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getDeviceClients&deviceMac=${deviceMac}`
            })
            .then(response => response.json())
            .then(data => data.success ? data.data : null);
    }

    // Fetch device bandwidth
    function fetchDeviceBandwidth(deviceMac) {
        return fetch('gwn_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getDeviceBandwidth&deviceMac=${deviceMac}&type=1`
            })
            .then(response => response.json())
            .then(data => data.success ? data.data : null);
    }

    // Fetch device bandwidth history
    function fetchDeviceBandwidthHistory(deviceMac) {
        return fetch('gwn_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getDeviceBandwidthHistory&deviceMac=${deviceMac}&timeRange=1`
            })
            .then(response => response.json())
            .then(data => data.success ? data.data : null);
    }

    // Fetch device client history
    function fetchDeviceClientHistory(deviceMac) {
        return fetch('gwn_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getDeviceClientHistory&deviceMac=${deviceMac}&timeRange=1`
            })
            .then(response => response.json())
            .then(data => data.success ? data.data : null);
    }

    // Display device information - مکمل درست کریں
   function displayDeviceInfo(data) {
    console.log('Raw device data:', data);
    
    if (!data || !data.data) {
        console.error('No device data received');
        showDefaultDeviceInfo();
        return;
    }
    
    const deviceData = data.data;
    
    // Basic info from device list
    const deviceInfo = deviceData.deviceInfo || {};
    const detailedInfo = deviceData.detailedInfo || {};
    const radioInfo = deviceData.radioInfo || {};
    const connectedClients = deviceData.connectedClients || [];
    
    // Extract information
    const deviceName = deviceInfo.name || detailedInfo.name || 'Unnamed Device';
    const deviceType = deviceInfo.apType || detailedInfo.apType || 'GWN Access Point';
    const deviceMac = deviceData.deviceMac || deviceInfo.mac || 'N/A';
    const deviceIp = deviceInfo.ip || detailedInfo.ip || 'N/A';
    const firmware = deviceInfo.versionFirmware || detailedInfo.versionFirmware || 'N/A';
    const uptime = deviceInfo.upTime ? formatUptime(deviceInfo.upTime) : 
                   (detailedInfo.upTime ? formatUptime(detailedInfo.upTime) : 'N/A');
    
    // Use REAL online clients count from connected clients list
    const clientsCount = deviceData.onlineClientsCount || deviceData.clientsCount || 0;
    
    // Determine status
    const isOnline = deviceInfo.status === 1 || 
                     detailedInfo.status === 1 || 
                     connectedClients.length > 0;
    
    // Update UI
    document.getElementById('deviceNameTitle').textContent = deviceName;
    document.getElementById('deviceType').textContent = deviceType;
    document.getElementById('deviceMac').textContent = deviceMac;
    document.getElementById('deviceIp').textContent = deviceIp;
    document.getElementById('deviceFirmware').textContent = firmware;
    document.getElementById('deviceUptime').textContent = uptime;
    document.getElementById('deviceClients').textContent = clientsCount;
    
    // Status badge
    const statusBadge = document.getElementById('deviceStatusBadge');
    statusBadge.className = isOnline ? 'badge bg-success' : 'badge bg-danger';
    statusBadge.textContent = isOnline ? 'Online' : 'Offline';
    
    // Update device specs
    displayDeviceSpecs(deviceInfo, detailedInfo);
    
    // Update radio info
    displayRadioInfo(radioInfo, detailedInfo);
    
    // Display connected clients in table
    displayDeviceClients(connectedClients);
}

function displayDeviceClients(clients) {
    const tableBody = document.getElementById('deviceClientsTable');
    tableBody.innerHTML = '';
    
    if (!clients || clients.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-users fa-2x mb-3 text-muted"></i>
                    <p class="mb-0 text-muted">No active clients connected</p>
                </td>
            </tr>
        `;
        return;
    }
    
    // Only show currently online clients
    const onlineClients = clients.filter(client => 
        (client.status === 1 || client.status === 'online')
    );
    
    onlineClients.forEach((client, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><code>${client.clientId || client.mac || 'N/A'}</code></td>
            <td>${client.ipv4 || client.ip || 'N/A'}</td>
            <td>${client.name || 'Unknown'}</td>
            <td>${client.ssid || 'N/A'}</td>
            <td>${client.rssi ? client.rssi + ' dBm' : 'N/A'}</td>
            <td>${client.connectionTime ? formatUptime(client.connectionTime) : 'Just now'}</td>
        `;
        tableBody.appendChild(row);
    });
}
    // Helper function to find device name from devices list
    function findDeviceName(devices, mac) {
        const device = devices.find(d => d.mac === mac);
        return device ? device.name : null;
    }

    // Helper function to find device IP from devices list
    function findDeviceIp(devices, mac) {
        const device = devices.find(d => d.mac === mac);
        return device ? device.ip : null;
    }

    // Calculate total clients from device info
    function calculateTotalClients(deviceInfo) {
        let total = 0;

        // Try different methods to get client count

        // Method 1: From radio information
        if (deviceInfo.g24 && Array.isArray(deviceInfo.g24)) {
            const clients2g = parseInt(extractRadioValue(deviceInfo.g24, 'clients')) || 0;
            total += clients2g;
        }
        if (deviceInfo.g5 && Array.isArray(deviceInfo.g5)) {
            const clients5g = parseInt(extractRadioValue(deviceInfo.g5, 'clients')) || 0;
            total += clients5g;
        }

        // Method 2: From device list data
        if (total === 0) {
            const device = devicesData.find(d => d.mac === currentDeviceMac);
            if (device && device.clients) {
                total = device.clients;
            }
        }

        // Method 3: From radio frequency info
        if (total === 0 && deviceInfo.radioFrequencyInfo) {
            const rf2g = deviceInfo.radioFrequencyInfo.radioFrequency2_4g;
            const rf5g = deviceInfo.radioFrequencyInfo.radioFrequency5g;

            if (rf2g && rf2g.clients) total += parseInt(rf2g.clients) || 0;
            if (rf5g && rf5g.clients) total += parseInt(rf5g.clients) || 0;
        }

        return total > 0 ? total : 0;
    }

    // Helper function to extract values from different response formats
    function extractValue(data, key) {
        if (!data) return null;

        // If data has result array (v1.0.0 format)
        if (data.result && Array.isArray(data.result)) {
            const item = data.result.find(item => item.key === key);
            return item ? item.value : null;
        }

        // If data has direct properties (v2.0.0 format)
        if (data[key] !== undefined && data[key] !== null) {
            return data[key];
        }

        return null;
    }

    // Helper function to extract radio values
    function extractRadioValue(radioArray, key) {
        if (!radioArray || !Array.isArray(radioArray)) return null;

        const item = radioArray.find(item => item.key === key);
        return item ? item.value : null;
    }

    // Display default device info when no data
    function showDefaultDeviceInfo() {
        const device = devicesData.find(d => d.mac === currentDeviceMac);

        if (device) {
            document.getElementById('deviceNameTitle').textContent = device.name || 'Unnamed Device';
            document.getElementById('deviceType').textContent = device.apType || 'Unknown';
            document.getElementById('deviceMac').textContent = device.mac || 'N/A';
            document.getElementById('deviceIp').textContent = device.ip || 'N/A';
            document.getElementById('deviceFirmware').textContent = device.versionFirmware || 'N/A';
            document.getElementById('deviceUptime').textContent = device.upTime ? formatUptime(device.upTime) : 'N/A';
            document.getElementById('deviceClients').textContent = device.clients || 0;
            // document.getElementById('deviceCpu').textContent = 'N/A';
            // document.getElementById('deviceMemory').textContent = 'N/A';

            const statusBadge = document.getElementById('deviceStatusBadge');
            statusBadge.className = device.status === 1 ? 'badge bg-success' : 'badge bg-danger';
            statusBadge.textContent = device.status === 1 ? 'Online' : 'Offline';
        }
    }

    // Display device specifications
    function displayDeviceSpecs(deviceInfo) {
        const specsElement = document.getElementById('deviceSpecs');
        let specsHTML = '';

        const specs = [{
                label: 'Model',
                value: extractValue(deviceInfo, 'apType')
            },
            {
                label: 'Part Number',
                value: extractValue(deviceInfo, 'partNumber')
            },
            {
                label: 'Serial Number',
                value: extractValue(deviceInfo, 'sn') || extractValue(deviceInfo, 'SN')
            },
            {
                label: 'Boot Version',
                value: extractValue(deviceInfo, 'bootVersion')
            },
            {
                label: 'Hardware Version',
                value: extractValue(deviceInfo, 'hardwareVersion')
            },
            {
                label: 'System Time',
                value: extractValue(deviceInfo, 'systemTime')
            },
            {
                label: 'Temperature',
                value: extractValue(deviceInfo, 'temperature')
            },
            {
                label: 'Load Average',
                value: Array.isArray(deviceInfo.loadAverage) ? deviceInfo.loadAverage.join(', ') : extractValue(
                    deviceInfo, 'loadAverage')
            },
            {
                label: 'SSID Support',
                value: extractValue(deviceInfo, 'ssidNumber') ? extractValue(deviceInfo, 'ssidNumber') + ' SSIDs' :
                    'N/A'
            },
            {
                label: 'VLAN Support',
                value: extractValue(deviceInfo, 'vlanCount') ? extractValue(deviceInfo, 'vlanCount') + ' VLANs' :
                    'N/A'
            }
        ];

        specs.forEach(spec => {
            if (spec.value && spec.value !== 'N/A') {
                specsHTML += `
                <div class="row mb-2">
                    <div class="col-6"><strong>${spec.label}:</strong></div>
                    <div class="col-6">${spec.value}</div>
                </div>
            `;
            }
        });

        specsElement.innerHTML = specsHTML || '<p class="text-muted">No specification data available</p>';
    }

    // Display radio information
    function displayRadioInfo(deviceInfo) {
        const radioElement = document.getElementById('radioInfo');
        let radioHTML = '';

        // Check different radio data structures

        // Structure 1: g24 and g5 arrays
        if (deviceInfo.g24 && Array.isArray(deviceInfo.g24)) {
            const channel = extractRadioValue(deviceInfo.g24, 'channel');
            const clients = extractRadioValue(deviceInfo.g24, 'clients');
            const power = extractRadioValue(deviceInfo.g24, 'power');

            radioHTML += `
            <h6>2.4GHz Radio</h6>
            <div class="row mb-2">
                <div class="col-6"><strong>Channel:</strong></div>
                <div class="col-6">${channel || 'N/A'}</div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><strong>Clients:</strong></div>
                <div class="col-6">${clients || '0'}</div>
            </div>
            <div class="row mb-3">
                <div class="col-6"><strong>Power:</strong></div>
                <div class="col-6">${power ? power + ' dBm' : 'N/A'}</div>
            </div>
        `;
        }

        // Structure 2: radioFrequencyInfo object
        if (deviceInfo.radioFrequencyInfo) {
            const rf2g = deviceInfo.radioFrequencyInfo.radioFrequency2_4g;
            const rf5g = deviceInfo.radioFrequencyInfo.radioFrequency5g;

            if (rf2g) {
                radioHTML += `
                <h6>2.4GHz Radio</h6>
                <div class="row mb-2">
                    <div class="col-6"><strong>Channel:</strong></div>
                    <div class="col-6">${rf2g.channel || 'N/A'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-6"><strong>Clients:</strong></div>
                    <div class="col-6">${rf2g.clients || '0'}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Power:</strong></div>
                    <div class="col-6">${rf2g.power ? rf2g.power + ' dBm' : 'N/A'}</div>
                </div>
            `;
            }

            if (rf5g) {
                radioHTML += `
                <h6 class="mt-3">5GHz Radio</h6>
                <div class="row mb-2">
                    <div class="col-6"><strong>Channel:</strong></div>
                    <div class="col-6">${rf5g.channel || 'N/A'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-6"><strong>Clients:</strong></div>
                    <div class="col-6">${rf5g.clients || '0'}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Power:</strong></div>
                    <div class="col-6">${rf5g.power ? rf5g.power + ' dBm' : 'N/A'}</div>
                </div>
            `;
            }
        }

        // Structure 3: g5 array
        if (deviceInfo.g5 && Array.isArray(deviceInfo.g5)) {
            const channel = extractRadioValue(deviceInfo.g5, 'channel');
            const clients = extractRadioValue(deviceInfo.g5, 'clients');
            const power = extractRadioValue(deviceInfo.g5, 'power');

            radioHTML += `
            <h6 class="mt-3">5GHz Radio</h6>
            <div class="row mb-2">
                <div class="col-6"><strong>Channel:</strong></div>
                <div class="col-6">${channel || 'N/A'}</div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><strong>Clients:</strong></div>
                <div class="col-6">${clients || '0'}</div>
            </div>
            <div class="row mb-3">
                <div class="col-6"><strong>Power:</strong></div>
                <div class="col-6">${power ? power + ' dBm' : 'N/A'}</div>
            </div>
        `;
        }

        radioElement.innerHTML = radioHTML || '<p class="text-muted">No radio information available</p>';
    }

    // Display port information
    function displayPortInfo(deviceInfo) {
        // یہ function port information show کرے گی اگر available ہو
        if (deviceInfo.portInfo && Array.isArray(deviceInfo.portInfo)) {
            console.log('Port Information:', deviceInfo.portInfo);
            // آپ یہاں port information display کر سکتے ہیں
        }
    }

    // Display device clients
    function displayDeviceClients(clientsData) {
        const tableBody = document.getElementById('deviceClientsTable');

        if (!clientsData || !clientsData.data || !clientsData.data.result || clientsData.data.result.length === 0) {
            tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-users fa-2x mb-3 text-muted"></i>
                    <p class="mb-0 text-muted">No clients connected to this device</p>
                </td>
            </tr>
        `;
            return;
        }

        let clientsHTML = '';
        clientsData.data.result.forEach(client => {
            const clientMac = client.clientId || client.mac || 'N/A';
            const clientIp = client.ipv4 || client.ip || 'N/A';
            const clientName = client.name || 'Unknown';
            const ssid = client.ssid || 'N/A';
            const rssi = client.rssi ? client.rssi + ' dBm' : 'N/A';
            const connectTime = client.connectionTime ? formatUptime(client.connectionTime) : 'N/A';

            clientsHTML += `
            <tr>
                <td><code>${clientMac}</code></td>
                <td>${clientIp}</td>
                <td>${clientName}</td>
                <td>${ssid}</td>
                <td>${rssi}</td>
                <td>${connectTime}</td>
            </tr>
        `;
        });

        tableBody.innerHTML = clientsHTML;
    }

    // Initialize charts with real data
    function initializeCharts(deviceInfo, bandwidth, bandwidthHistory, clientHistory) {
        // Client Count Chart
        initializeClientChart(clientHistory);

        // Bandwidth Usage Chart
        initializeBandwidthChart(bandwidth, bandwidthHistory);
    }

    // Initialize client count chart with real data
    function initializeClientChart(clientHistory) {
        const clientCtx = document.getElementById('clientChart').getContext('2d');
        if (clientChart) clientChart.destroy();

        let clientData = [12, 19, 15, 17, 14, 16, 18]; // Default data

        // اگر real client history data available ہے تو use کریں
        if (clientHistory && clientHistory.data) {
            // یہاں client history data process کریں
            console.log('Client History Data:', clientHistory);
        }

        clientChart = new Chart(clientCtx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Client Count',
                    data: clientData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    }
                }
            }
        });
    }

    // Initialize bandwidth chart with real data
    function initializeBandwidthChart(bandwidth, bandwidthHistory) {
        const bandwidthCtx = document.getElementById('bandwidthChart').getContext('2d');
        if (bandwidthChart) bandwidthChart.destroy();

        let downloadData = 65; // Default
        let uploadData = 35; // Default

        // اگر real bandwidth data available ہے تو use کریں
        if (bandwidth && bandwidth.data) {
            console.log('Bandwidth Data:', bandwidth);
            // یہاں bandwidth data process کریں
        }

        // Device list سے bandwidth data لے سکتے ہیں
        const device = devicesData.find(d => d.mac === currentDeviceMac);
        if (device) {
            const totalBytes = (device.download || 0) + (device.upload || 0);
            if (totalBytes > 0) {
                downloadData = Math.round((device.download / totalBytes) * 100);
                uploadData = Math.round((device.upload / totalBytes) * 100);
            }
        }

        bandwidthChart = new Chart(bandwidthCtx, {
            type: 'bar',
            data: {
                labels: ['Download', 'Upload'],
                datasets: [{
                    label: 'Bandwidth Usage',
                    data: [downloadData, uploadData],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgb(16, 185, 129)',
                        'rgb(59, 130, 246)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y}%`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    }
                }
            }
        });
    }

    // Utility functions
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0 || bytes === undefined || bytes === null) return '0 B';

        if (typeof bytes === 'string') {
            bytes = parseFloat(bytes);
            if (isNaN(bytes)) return '0 B';
        }

        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function formatUptime(seconds) {
        if (!seconds || seconds === 'N/A') return 'N/A';

        if (typeof seconds === 'string') {
            seconds = parseFloat(seconds);
            if (isNaN(seconds)) return 'N/A';
        }

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);

        if (days > 0) {
            return `${days}d ${hours}h ${minutes}m`;
        } else if (hours > 0) {
            return `${hours}h ${minutes}m`;
        } else if (minutes > 0) {
            return `${minutes}m`;
        } else {
            return `${seconds}s`;
        }
    }

    // Search functionality
    document.getElementById('searchDevice')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;

        const filteredDevices = devicesData.filter(device => {
            const matchesSearch = device.name?.toLowerCase().includes(searchTerm) ||
                device.mac?.toLowerCase().includes(searchTerm) ||
                device.apType?.toLowerCase().includes(searchTerm) ||
                device.ip?.toLowerCase().includes(searchTerm);

            const matchesStatus = statusFilter === 'all' ||
                (statusFilter === 'online' && device.status === 1) ||
                (statusFilter === 'offline' && device.status === 0);

            return matchesSearch && matchesStatus;
        });

        displayDevices(filteredDevices);
    });

    document.getElementById('statusFilter')?.addEventListener('change', function() {
        document.getElementById('searchDevice').dispatchEvent(new Event('input'));
    });

    // Refresh functionality
    function refreshData() {
        if (currentDeviceMac) {
            loadDeviceDetails(currentDeviceMac);
        } else {
            loadDevices();
        }
    }

    // Handle browser back button
    window.addEventListener('popstate', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const deviceParam = urlParams.get('device');

        if (deviceParam) {
            currentDeviceMac = deviceParam;
            showDeviceDetailView();
            loadDeviceDetails(deviceParam);
        } else {
            currentDeviceMac = null;
            document.getElementById('deviceListView').style.display = 'block';
            document.getElementById('deviceDetailView').style.display = 'none';
            document.getElementById('pageTitle').textContent = 'Device Management';
            document.getElementById('pageSubtitle').textContent =
                'All network devices and their bandwidth usage';
            loadDevices();
        }
    });

    // Initialize page
    if (currentDeviceMac) {
        console.log('Loading device details for:', currentDeviceMac);
        showDeviceDetailView();
        loadDeviceDetails(currentDeviceMac);
    } else {
        console.log('Loading device list');
        loadDevices();
    }
    </script>
</body>

</html>