<?php
// File: /public_html/clients.php

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
session_write_close();

require_once(__DIR__ . '/db_connect.php');
require_once(__DIR__ . '/config.php');

try {
    $currentClient = getCurrentClient();
    $isKnoativ = ($_SESSION['username'] ?? '') === 'Knoativ';

    if (!$isKnoativ) {
        header('Location: index.php');
        exit;
    }

    // Live data (no cache): the same snapshot the dashboards use
    require_once(__DIR__ . '/live_snapshot.php');
    $liveData = [];
    try {
        $liveData = buildLiveSnapshot($currentClient, true);
    } catch (Throwable $e) {
        error_log('clients.php live snapshot: ' . $e->getMessage());
    }

    // Get all clients from cache and filter only online ones
    $allClients = $liveData['gwn_dashboard']['top_clients'] ?? [];
    $clients = [];

    foreach ($allClients as $client) {
        // Only include clients with status 'online' or undefined (assumed online)
        if (!isset($client['status']) || $client['status'] === 'online') {
            $clients[] = $client;
        }
    }

    // Get detailed client information
    $detailedClients = $liveData['gwn_dashboard']['clients']['data']['result'] ?? [];

    // Debug information
    error_log("Clients Page - Total clients in cache: " . count($allClients));
    error_log("Clients Page - Online clients after filtering: " . count($clients));
} catch (Exception $e) {
    error_log("Error in clients.php: " . $e->getMessage());
    $clients = [];
    $detailedClients = [];
}
// formatBytes() comes from live_snapshot.php
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Details - Network Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Same CSS as before */
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
            vertical-align: middle;
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
            text-decoration: none;
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
        }

        .back-btn:hover {
            background: rgba(var(--primary-rgb), 0.2);
            color: var(--primary);
        }

        .status-badge-online {
            background: rgba(var(--success-rgb), 0.1);
            color: var(--success);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-badge-offline {
            background: rgba(var(--danger-rgb), 0.1);
            color: var(--danger);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .client-info-small {
            font-size: 11px;
            color: var(--text-muted);
        }

        .value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .text {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .table-responsive {
            max-height: auto;
            overflow-y: auto;
        }

        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-radius: 50%;
            border-top: 3px solid #3498db;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .toast-container {
            z-index: 9999;
        }

        .btn-details {
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary);
            border: 1px solid var(--primary);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-details:hover {
            background: rgba(var(--primary-rgb), 0.2);
            color: var(--primary);
        }
    </style>
</head>

<body class="dark-mode">
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
                <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Overview</a></li>
                <li><a href="locations.php" class="nav-link"><i class="fas fa-network-wired"></i> All Locations</a></li>
                <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
                <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
                <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations Analytics</a></li>
                <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location Information</a></li>
                <?php if ($isKnoativ): ?>
                    <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container mt-auto"></div>
        </div>

        <div class="main-content">
            <div class="dashboard-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1>Client Details</h1>
                        <p class="mb-0">All connected clients and their bandwidth usage</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span id="live-time" class="fw-bold"></span>
                        <a href="index.php" class="btn-custom back-btn ms-2"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

                        <a href="logout.php" class="btn-custom logout-btn ms-2"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h3 class="mb-0">All Online Clients (<span id="totalClientsCount"><?php echo count($clients); ?></span>)</h3>
                            <div class="d-flex gap-2">
                                <input type="text" id="searchClient" class="form-control form-control-sm" placeholder="Search clients..." style="max-width: 250px;">
                                <!-- <button id="forceRefreshBtn" class="btn-custom refresh-btn"><i class="fas fa-bolt"></i> Force Refresh</button> -->
                            </div>
                        </div>

                        <!-- Client Statistics Summary -->


                        <div class="table-responsive">
                            <table class="gwn-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Client Name</th>
                                        <th>IP Address</th>
                                        <th>AP Name</th>
                                        <th>SSID</th>
                                        <th>Download</th>
                                        <th>Upload</th>
                                        <th>Total Usage</th>
                                        <th>Status</th>
                                        <th>Connection Time</th>
                                        <!-- <th>Actions</th> -->
                                    </tr>
                                </thead>
                                <tbody id="clientsTableBody">
                                    <?php if (!empty($clients)): ?>
                                        <?php
                                        $onlineClients = [];
                                        foreach ($clients as $index => $client) {
                                            // Find matching detailed client information
                                            $clientMac = $client['mac'] ?? '';
                                            $clientIp = $client['ip'] ?? 'N/A';
                                            $clientAp = $client['apName'] ?? 'N/A';
                                            $clientSsid = $client['ssid'] ?? 'N/A';

                                            // If we have TEST_MAC, use it for the link
                                            $linkMac = $clientMac;
                                            if (empty($linkMac) || $linkMac === 'N/A') {
                                                $linkMac = 'TEST_MAC_' . ($index + 1);
                                            }

                                            // Try to find more details from detailed clients list
                                            foreach ($detailedClients as $detailedClient) {
                                                $detailedMac = $detailedClient['clientId'] ?? $detailedClient['mac'] ?? '';
                                                $detailedName = $detailedClient['name'] ?? '';

                                                if ($detailedMac === $clientMac || $detailedName === $client['name']) {
                                                    $clientIp = $detailedClient['ipv4'] ?? $detailedClient['ip'] ?? $clientIp;
                                                    $clientAp = $detailedClient['apName'] ?? $clientAp;
                                                    $clientSsid = $detailedClient['ssid'] ?? $clientSsid;
                                                    break;
                                                }
                                            }

                                            $onlineClients[] = [
                                                'index' => $index,
                                                'client' => $client,
                                                'mac' => $clientMac,
                                                'linkMac' => $linkMac,
                                                'ip' => $clientIp,
                                                'ap' => $clientAp,
                                                'ssid' => $clientSsid,
                                                'status' => 'online'
                                            ];
                                        }
                                        ?>

                                        <?php foreach ($onlineClients as $item): ?>
                                            <tr>
                                                <td><?php echo $item['index'] + 1; ?></td>
                                                <td>
                                                    <a href="client_detail.php?mac=<?php echo urlencode($item['linkMac']); ?>&name=<?php echo urlencode($item['client']['name'] ?? 'Unknown Client'); ?>"
                                                        <div class="d-flex align-items-center">
                                                        <i class="fas fa-desktop me-2"></i>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($item['client']['name'] ?? 'Unknown Client'); ?></strong>
                                                            <div class="client-info-small">
                                                                MAC: <?php echo htmlspecialchars($item['mac']); ?>
                                                            </div>
                                                        </div>
                        </div>
                        </a>
                        </td>
                        <td><?php echo htmlspecialchars($item['ip']); ?></td>
                        <td><?php echo htmlspecialchars($item['ap']); ?></td>
                        <td><?php echo htmlspecialchars($item['ssid']); ?></td>
                        <td>
                            <span class="text-success">
                                <i class="fas fa-download me-1"></i>
                                <?php echo htmlspecialchars($item['client']['download'] ?? '0 B'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-primary">
                                <i class="fas fa-upload me-1"></i>
                                <?php echo htmlspecialchars($item['client']['upload'] ?? '0 B'); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['client']['total'] ?? '0 B'); ?></strong>
                        </td>
                        <td>
                            <span class="status-badge-online">
                                <i class="fas fa-circle me-1"></i> Online
                            </span>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo date('H:i:s'); ?></small>
                        </td>
                        <!-- <td>
                            <a href="client_detail.php?mac=<?php echo urlencode($item['linkMac']); ?>&name=<?php echo urlencode($item['client']['name'] ?? 'Unknown Client'); ?>"
                                class="btn-details"
                                title="View Client Details">
                                <i class="fas fa-eye me-1"></i>
                            </a>
                        </td> -->
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">
                            <i class="fas fa-users fa-2x mb-3"></i>
                            <p class="mb-0">No online clients available</p>
                            <small class="text-muted">Try refreshing the data or check the GWN connection</small>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
                </table>
                    </div>
                    <br />
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card text-center">
                                <div class="value text-primary" id="totalOnlineClients"><?php echo count($clients); ?></div>
                                <div class="text">Total Online Clients</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card text-center">
                                <div class="value text-success" id="totalDownload">0 B</div>
                                <div class="text">Total Download</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card text-center">
                                <div class="value text-primary" id="totalUpload">0 B</div>
                                <div class="text">Total Upload</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card text-center">
                                <div class="value text-warning" id="totalUsage">0 B</div>
                                <div class="text">Total Usage</div>
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

        // Search functionality
        document.getElementById('searchClient')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#clientsTableBody tr');

            let visibleCount = 0;
            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const clientName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const clientIP = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                    const clientAP = row.querySelector('td:nth-child(4)').textContent.toLowerCase();

                    if (clientName.includes(searchTerm) || clientIP.includes(searchTerm) || clientAP.includes(searchTerm)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            // Update visible count
            document.getElementById('totalClientsCount').textContent = visibleCount;
        });

        // Calculate total statistics
        function calculateTotals() {
            let totalDownload = 0;
            let totalUpload = 0;
            let totalUsage = 0;

            const clients = <?php echo json_encode($clients); ?>;

            clients.forEach(client => {
                // Extract numeric values from bandwidth strings
                const download = extractBytes(client.download || '0 B');
                const upload = extractBytes(client.upload || '0 B');

                totalDownload += download;
                totalUpload += upload;
                totalUsage += download + upload;
            });

            document.getElementById('totalDownload').textContent = formatBytes(totalDownload);
            document.getElementById('totalUpload').textContent = formatBytes(totalUpload);
            document.getElementById('totalUsage').textContent = formatBytes(totalUsage);
        }

        function extractBytes(sizeString) {
            const units = {
                'B': 1,
                'KB': 1024,
                'MB': 1024 * 1024,
                'GB': 1024 * 1024 * 1024,
                'TB': 1024 * 1024 * 1024 * 1024
            };

            const match = sizeString.match(/([\d.]+)\s*([KMGTP]?B)/i);
            if (match) {
                const value = parseFloat(match[1]);
                const unit = match[2].toUpperCase();
                return value * (units[unit] || 1);
            }
            return 0;
        }

        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) return;

            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            toastContainer.appendChild(toast);
            const bootstrapToast = new bootstrap.Toast(toast);
            bootstrapToast.show();
            toast.addEventListener('hidden.bs.toast', () => toast.remove());
        }

        // Refresh data
        function refreshData() {
            document.getElementById('refreshBtn').disabled = true;
            document.getElementById('refreshBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';

            // No cache: a reload fetches live data from the routers
            location.reload();
        }

        // Force refresh GWN data
        // function forceRefreshGWNData() {
        //     document.getElementById('forceRefreshBtn').disabled = true;
        //     document.getElementById('forceRefreshBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';

        //     fetch('gwn_dashboard.php', {
        //             method: 'POST',
        //             headers: {
        //                 'Content-Type': 'application/x-www-form-urlencoded',
        //             },
        //             body: 'action=getCompleteDashboard&networkId=38678&type=1'
        //         })
        //         .then(response => response.json())
        //         .then(data => {
        //             if (data.success) {
        //                 showToast('GWN data refreshed successfully', 'success');
        //                 // Also refresh the cache
        //                 refreshData();
        //             } else {
        //                 showToast('Failed to refresh GWN data', 'danger');
        //                 document.getElementById('forceRefreshBtn').disabled = false;
        //                 document.getElementById('forceRefreshBtn').innerHTML = '<i class="fas fa-bolt"></i> Force Refresh';
        //             }
        //         })
        //         .catch(error => {
        //             console.error('Force refresh error:', error);
        //             showToast('Error refreshing GWN data', 'danger');
        //             document.getElementById('forceRefreshBtn').disabled = false;
        //             document.getElementById('forceRefreshBtn').innerHTML = '<i class="fas fa-bolt"></i> Force Refresh';
        //         });
        // }

        // Event listeners
        document.getElementById('refreshBtn')?.addEventListener('click', refreshData);

        // Initialize totals calculation
        calculateTotals();

        // Debug info
        console.log("Total clients from PHP:", <?php echo count($clients); ?>);
        console.log("Raw client data sample:", <?php echo json_encode(array_slice($clients, 0, 3)); ?>);
    </script>
</body>

</html>