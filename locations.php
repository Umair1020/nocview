<?php
// File: /public_html/locations.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
$current_page = basename($_SERVER['PHP_SELF']);

// Include required files
require('routeros_api.class.php');
require_once('db_connect.php');
require_once('config.php');

session_start();

// Get client configuration
try {
    $currentClient = getCurrentClient();
    $locationMap = $currentClient['locationMap'];
} catch (Exception $e) {
    die("Error getting client config: " . $e->getMessage());
}

// Get username for cache file (same as index.php)
$username = $_SESSION['username'] ?? 'default';

// No cache: the page starts with an empty snapshot and the browser fills it in
// with live data from update_cache.php (see initializeLivePolling below).
$nodeData = [
    'locations' => [],
    'stats' => [
        'total_locations' => 0, 'locations_up' => 0, 'locations_down' => 0,
        'total_nodes' => 0, 'nodes_up' => 0, 'nodes_down' => 0, 'avg_ping' => 0,
        'uptime_percent' => 0, 'live_count' => 0, 'offline_count' => 0,
        'active_alerts' => 0, 'critical_alerts' => 0, 'warning_alerts' => 0,
        'average_cpu_usage' => '0%',
    ],
    'google_dns' => 0,
    'cloudflare_dns' => 0,
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Locations | Network Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
        color: var(--primary);
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

    .location-card {
        background: var(--dark-card-bg);
        border-radius: 10px;
        padding: 15px;
        border-left: 4px solid var(--dark-border);
        transition: all 0.3s;
        text-decoration: none !important;
        color: inherit;
        display: block;
        margin-bottom: 15px;
        height: 100%;
    }

    body.light-mode .location-card {
        background: var(--light-card-bg);
        border-left: 4px solid var(--light-border);
    }

    .location-card.up {
        border-left-color: var(--dark-success);
    }
    
    body.light-mode .location-card.up {
        border-left-color: var(--light-success);
    }

    .location-card.down {
        border-left-color: var(--dark-danger);
    }
    
    body.light-mode .location-card.down {
        border-left-color: var(--light-danger);
    }

    .location-card:hover {
        background: rgba(var(--success-rgb), 0.05);
        transform: translateY(-3px);
        text-decoration: none;
        color: inherit;
    }

    .down-alert {
        position: relative;
        overflow: hidden;
        border-left: 4px solid var(--dark-danger) !important;
        animation: blink-warning 1s infinite;
    }
    
    body.light-mode .down-alert {
        border-left: 4px solid var(--light-danger) !important;
    }

    @keyframes blink-warning {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }

    .search-box {
        background: var(--dark-card-bg);
        border: 1px solid var(--dark-border);
        color: var(--dark-text);
        padding: 10px 15px;
        border-radius: 6px;
        width: 100%;
        margin-bottom: 20px;
    }
    
    body.light-mode .search-box {
        background: var(--light-card-bg);
        border: 1px solid var(--light-border);
        color: var(--light-text);
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
    }

    .refresh-btn {
        background: var(--dark-primary);
        color: white;
    }
    
    body.light-mode .refresh-btn {
        background: var(--light-primary);
        color: white;
    }

    .refresh-btn:hover {
        background: var(--dark-highlight);
        color: white;
    }
    
    body.light-mode .refresh-btn:hover {
        background: var(--light-highlight);
        color: white;
    }

    .back-btn {
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--dark-primary);
        border: 1px solid var(--dark-primary);
        text-decoration: none;
    }
    
    body.light-mode .back-btn {
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--light-primary);
        border: 1px solid var(--light-primary);
    }

    .back-btn:hover {
        background: rgba(var(--primary-rgb), 0.2);
        color: var(--dark-primary);
        text-decoration: none;
    }
    
    body.light-mode .back-btn:hover {
        background: rgba(var(--primary-rgb), 0.2);
        color: var(--light-primary);
    }

    .connection-status {
        position: fixed;
        top: 10px;
        right: 10px;
        z-index: 1000;
        background: var(--dark-success);
        color: white;
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 12px;
    }
    
    body.light-mode .connection-status {
        background: var(--light-success);
        color: white;
    }

    .last-updated {
        text-align: center;
        margin-top: 20px;
        color: var(--dark-text-muted);
        font-size: 14px;
    }
    
    body.light-mode .last-updated {
        color: var(--light-text-muted);
    }

    .location-card h3 {
        font-size: 16px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 8px;
    }

    .location-card h3 span {
        font-size: 10px;
        background: rgba(var(--primary-rgb), 0.1);
        padding: 2px 6px;
        border-radius: 3px;
        color: var(--dark-primary);
        white-space: nowrap;
    }
    
    body.light-mode .location-card h3 span {
        color: var(--light-primary);
    }

    .location-stats {
        margin-top: 10px;
        border-top: 1px solid var(--dark-border);
        padding-top: 10px;
    }
    
    body.light-mode .location-stats {
        border-top: 1px solid var(--light-border);
    }

    .location-stats .row {
        margin-bottom: 5px;
    }

    .location-stats .small {
        font-size: 12px;
    }

    .status-badge {
        font-size: 12px;
        padding: 3px 8px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .status-badge.up {
        background: rgba(var(--success-rgb), 0.1);
        color: var(--dark-success);
    }
    
    body.light-mode .status-badge.up {
        color: var(--light-success);
    }

    .status-badge.down {
        background: rgba(var(--danger-rgb), 0.1);
        color: var(--dark-danger);
    }
    
    body.light-mode .status-badge.down {
        color: var(--light-danger);
    }

    .status-badge.unknown {
        background: rgba(156, 163, 175, 0.15);
        color: var(--text-muted);
    }

    .ping-value {
        color: var(--dark-success);
    }
    
    body.light-mode .ping-value {
        color: var(--light-success);
    }

    .ping-value.medium {
        color: var(--dark-warning);
    }
    
    body.light-mode .ping-value.medium {
        color: var(--light-warning);
    }

    .ping-value.high {
        color: var(--dark-danger);
    }
    
    body.light-mode .ping-value.high {
        color: var(--light-danger);
    }

    .uptime-bar {
        height: 8px;
        border-radius: 4px;
        background: var(--dark-border);
        overflow: hidden;
        margin-top: 10px;
    }
    
    body.light-mode .uptime-bar {
        background: var(--light-border);
    }

    .uptime-progress {
        height: 100%;
        background: var(--dark-success);
        transition: width 0.3s;
    }
    
    body.light-mode .uptime-progress {
        background: var(--light-success);
    }
    
    .non-clickable-card {
        cursor: default !important;
    }
    
    .non-clickable-card:hover {
        transform: none !important;
        background: var(--dark-card-bg) !important;
    }
    
    body.light-mode .non-clickable-card:hover {
        background: var(--light-card-bg) !important;
    }
    </style>
</head>

<body>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3">
                <div class="logo-container"></div>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="index.php" class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        Overview
                    </a>
                </li>
                <li>
    <a href="locations.php" class="nav-link active  <?= $current_page == 'locations.php' ? 'active' : '' ?>">
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
                <li>
                    <a href="heatmap.php" class="nav-link <?= $current_page == 'heatmap.php' ? 'active' : '' ?>">
                        <i class="fas fa-th"></i>
                        Heatmap
                    </a>
                </li>
                <li>
                    <a href="bandwidth.php" class="nav-link <?= $current_page == 'bandwidth.php' ? 'active' : '' ?>">
                        <i class="fas fa-chart-line"></i>
                        Bandwidth
                    </a>
                </li>
                <li>
                    <a href="locations_Analytics.php" class="nav-link <?= $current_page == 'locations_Analytics.php' ? 'active' : '' ?>">
                        <i class="fas fa-wifi"></i>
                        Locations Analytics
                    </a>
                </li>
                <li>
                    <a href="location_information.php" class="nav-link <?= $current_page == 'location_information.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marker-alt"></i> Location Information
                    </a>
                </li>
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

        <!-- Main content -->
        <div class="main-content flex-grow-1">
            <div class="dashboard-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1><i class="fas fa-map-marker-alt"></i> All Locations</h1>
                        <p class="mb-0">Real-time view of all network locations</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="d-flex justify-content-md-end gap-3">
                            <a href="index.php" class="btn-custom back-btn">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                            <button class="btn-custom refresh-btn" onclick="refreshData()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <a href="logout.php" class="btn-custom" style="background: var(--dark-danger); color: white;">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search Box -->
            <input type="text" class="search-box" placeholder="Search locations..." id="searchBox">

            <!-- All Locations -->
            <div class="row" id="locations-container">
                <?php if (isset($nodeData['locations']) && is_array($nodeData['locations']) && count($nodeData['locations']) > 0): ?>
                <?php foreach ($nodeData['locations'] as $name => $location): ?>
                <?php 
                    $isGWN = ($location['type'] ?? '') === 'gwn_ap';
                    
                    // GWN APs کلک ایبل نہیں ہوں گے
                    if ($isGWN) {
                        $clickable = false;
                        $cardClass = 'non-clickable-card';
                    } else {
                        $clickable = true;
                        $cardClass = '';
                    }

                    $status = $location['status'] ?? 'down';
                    $ping = $location['ping'] ?? 0;
                    $uptime = $location['uptime'] ?? 0;
                    $cpu_usage = $location['cpu_usage'] ?? 0;
                    $bandwidth_usage = $location['bandwidth_usage'] ?? 0;
                    $clients = $location['clients'] ?? 0;
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <?php if ($clickable): ?>
                    <?php 
                        // MikroTik devices کے لیے Test.php پر لنک
                        $link = 'Test.php?ip=' . htmlspecialchars($location['public_ip'] ?? '');
                    ?>
                    <a href="<?= $link ?>" class="location-card <?= htmlspecialchars($status) ?> 
                          <?= $status === 'down' ? 'down-alert' : '' ?> <?= $cardClass ?>">
                    <?php else: ?>
                    <div class="location-card <?= htmlspecialchars($status) ?> 
                          <?= $status === 'down' ? 'down-alert' : '' ?> <?= $cardClass ?>">
                    <?php endif; ?>

                        <?php if ($status === 'down'): ?>
                        <div style="background: rgba(var(--danger-rgb), 0.2); color: var(--dark-danger); padding: 5px 10px; border-radius: 4px; margin-bottom: 10px; text-align: center;">
                            <i class="fas fa-exclamation-triangle"></i> CONNECTION DOWN
                        </div>
                        <?php endif; ?>

                        <h3>
                            <?= htmlspecialchars($name) ?>
                            <span  style="color: var(--dark-success);background: rgba(var(--dark-success);">
                                <?= $isGWN ? 'GWN AP' : htmlspecialchars($location['public_ip'] ?? 'Unknown') ?>
                            </span>
                        </h3>

                        <div class="d-flex justify-content-between align-items-center">
                            <span class="status-badge <?= $status ?>">
                                <i class="fas fa-<?= $status === 'up' ? 'check-circle' : 'times-circle' ?>"></i>
                                <?= ucfirst(htmlspecialchars($status)) ?>
                            </span>
                            <?php if (!$isGWN): ?>
                            <!-- صرف MikroTik devices کے لیے ping دکھائیں -->
                            <span class="ping-value <?= $ping < 50 ? '' : ($ping < 100 ? 'medium' : 'high') ?>">
                                <i class="fas fa-signal"></i> <?= htmlspecialchars($ping) ?>ms
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="uptime-bar">
                            <div class="uptime-progress" style="width: <?= htmlspecialchars($uptime) ?>%;"></div>
                        </div>

                        <div class="location-stats">
                            <?php if (!$isGWN): ?>
                            <!-- MikroTik devices کے لیے CPU اور Bandwidth دکھائیں -->
                            <div class="row small" style="color: var(--dark-success);">
                                <div class="col-6">
                                    <i class="fas fa-microchip"></i> CPU:
                                    <?= htmlspecialchars($cpu_usage) ?>%
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-wifi"></i> BW:
                                    <?= htmlspecialchars($bandwidth_usage) ?> Mbps
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($isGWN): ?>
                            <!-- GWN APs کے لیے صرف connected clients دکھائیں -->
                            <div class="row small mt-2" style="color: var(--dark-success);">
                                <div class="col-12">
                                    <i class="fas fa-users"></i> Connected Clients:
                                    <?= htmlspecialchars($clients) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                    <?php if ($clickable): ?>
                    </a>
                    <?php else: ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="col-12 text-center">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> No location data available.
                        <br><small>Loading live data from the routers…</small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="last-updated">Last updated: <span id="lastUpdateTime"><?= date('H:i:s') ?></span></div>
        </div>
    </div>

    <script src="assets/js/live-poll.js?v=1"></script>
    <script>
    // Real-time updates for locations page (one poller, no SSE)
    function initializeLivePolling() {
        const poller = LivePoller.create('update_cache.php', function (data) {
            updateLocations(data);
            document.getElementById('lastUpdateTime').textContent = new Date().toLocaleTimeString();
        }, { interval: 10000 });
        window.dashboardPoller = poller;
        poller.start();
    }


    function updateLocations(data) {
        if (!data || !data.locations) return;

        const container = document.getElementById('locations-container');
        container.innerHTML = '';

        // Process all locations
        for (const name in data.locations) {
            const location = data.locations[name];
            const isGWN = location.type === 'gwn_ap';
            const isSangfor = location.type === 'sangfor';
            
            // GWN APs کلک ایبل نہیں ہوں گے
            const clickable = !isGWN && !isSangfor;
            const cardClass = !clickable ? 'non-clickable-card' : '';

            const statusClass = location.status || 'down';
            const isDown = statusClass === 'down';
            const ping = location.ping;
            const uptime = location.uptime || 0;
            const cpu_usage = location.cpu_usage || 0;
            const bandwidth_usage = location.bandwidth_usage || 0;
            const clients = location.clients || 0;
            
            const pingClass = ping == null || ping < 50 ? '' : (ping < 100 ? 'medium' : 'high');
            
            let card = '';
            
            if (clickable) {
                // MikroTik devices کے لیے Test.php پر لنک
                const link = 'Test.php?ip=' + encodeURIComponent(location.public_ip || '');
                card = `
                    <div class="col-md-6 col-lg-4 mb-4">
                        <a href="${link}" class="location-card ${statusClass} ${isDown ? 'down-alert' : ''} ${cardClass}">
                `;
            } else {
                card = `
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="location-card ${statusClass} ${isDown ? 'down-alert' : ''} ${cardClass}">
                `;
            }
            
            card += `
                            ${isDown ? `
                            <div style="background: rgba(var(--danger-rgb), 0.2); color: var(--dark-danger); padding: 5px 10px; border-radius: 4px; margin-bottom: 10px; text-align: center;">
                                <i class="fas fa-exclamation-triangle"></i> CONNECTION DOWN
                            </div>
                            ` : ''}
                            
                            <h3>
                                ${name}
                                <span>${isGWN ? 'GWN AP' : (location.public_ip || 'Unknown')}</span>
                            </h3>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="status-badge ${statusClass}">
                                    <i class="fas fa-${statusClass === 'up' ? 'check-circle' : statusClass === 'down' ? 'times-circle' : 'question-circle'}"></i>
                                    ${statusClass ? statusClass.charAt(0).toUpperCase() + statusClass.slice(1) : 'Down'}
                                </span>
                                ${!isGWN ? `
                                <span class="ping-value ${pingClass}">
                                    <i class="fas fa-signal"></i> ${ping == null ? 'N/A' : ping + 'ms'}
                                </span>
                                ` : ''}
                            </div>
                            
                            ${location.uptime != null ? `
                            <div class="uptime-bar">
                                <div class="uptime-progress" style="width: ${uptime}%;"></div>
                            </div>` : ''}
                            
                            <div class="location-stats">
                                ${!isGWN ? `
                                <div class="row small" style="color: var(--dark-success);">
                                    <div class="col-6">
                                        <i class="fas fa-microchip"></i> CPU: ${location.cpu_usage == null ? 'N/A' : cpu_usage + '%'}
                                    </div>
                                    <div class="col-6">
                                        <i class="fas fa-wifi"></i> BW: ${location.bandwidth_usage == null ? 'N/A' : bandwidth_usage + ' Mbps'}
                                    </div>
                                </div>
                                ` : ''}
                                
                                ${isGWN ? `
                                <div class="row small mt-2" style="color: var(--dark-primary);">
                                    <div class="col-12">
                                        <i class="fas fa-users"></i> Connected Clients: ${clients}
                                    </div>
                                </div>
                                ` : ''}
                            </div>
            `;
            
            if (clickable) {
                card += `
                        </a>
                    </div>
                `;
            } else {
                card += `
                        </div>
                    </div>
                `;
            }
            
            container.insertAdjacentHTML('beforeend', card);
        }

        // If no locations found
        if (container.children.length === 0) {
            container.innerHTML = `
                <div class="col-12 text-center">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> No location data available.
                        <br><small>Loading live data from the routers…</small>
                    </div>
                </div>`;
        }

        // Re-attach search functionality to new cards
        attachSearchFunctionality();
    }

    function refreshData() {
        fetch('update_cache.php', { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                updateLocations(data);
                document.getElementById('lastUpdateTime').textContent = new Date().toLocaleTimeString();
            })
            .catch(error => {
                console.error('Refresh error:', error);
            });
    }

    function attachSearchFunctionality() {
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const locationCards = document.querySelectorAll('.col-md-6.col-lg-4.mb-4');

                locationCards.forEach(card => {
                    const locationName = card.querySelector('h3').textContent.toLowerCase();
                    const locationType = card.querySelector('h3 span').textContent.toLowerCase();

                    if (locationName.includes(searchTerm) || locationType.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    }

    // Start real-time updates
    document.addEventListener('DOMContentLoaded', function() {
        initializeLivePolling();
        attachSearchFunctionality();
    });
    </script>
</body>

</html>