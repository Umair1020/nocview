<?php
// heatmap.php
session_start();
require_once('db_connect.php');
require_once('config.php');

// Get current client configuration
$currentClient = getCurrentClient();
$isKnoativ = ($_SESSION['username'] ?? '') === 'Knoativ';

// Get GWN AP mapping from config
$gwnApMapping = $currentClient['gwn_ap_mapping'] ?? [];

// Get username for cache file
$username = $_SESSION['username'] ?? 'default';

// No cache: data is loaded live from update_cache.php by the browser
$nodeData = ['locations' => [], 'stats' => []];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Location Heatmap</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- ECharts -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <style>
        /* Existing CSS remains unchanged */
        body { background-color: #f8f9fa; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
        }
        .main-content {
            flex: 1;
            position: relative;
            overflow: hidden;
        }
        #netdata-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }
        #locationsTreemap {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
        }
        :root {
            /* Dark Theme Variables */
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
            /* Light Theme Variables */
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
            /* Default to dark theme */
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
            margin: 370px 2px 0px 40px;
            background-position: center;
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
            --spotcomm-logo: var(--light-spotcomm-logo);
            --success: var(--light-success);
            --warning: var(--light-warning);
            --danger: var(--light-danger);
            --highlight: var(--light-highlight);
            --tooltip-bg: var(--light-tooltip-bg);
        }
        /* Alert Circle */
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
        #uptimeChart {
            height: 250px;
            width: 100%;
        }
        .dark-mode .alert-circle-inner {
            background-color: #2a2a2a;
            color: white;
        }
        .light-mode .alert-circle-inner {
            background-color: #f8f9fa;
            color: #212529;
        }
        /* Dashboard Components */
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
        /* Location Components */
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
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: transparent;
            border-color: var(--border) var(--border) var(--bg);
            border-bottom: none;
        }
        /* Alert Components */
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
        /* Group Cards */
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
        /* Ping Components */
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
        /* Buttons */
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
        /* Uptime Components */
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
        /* Chart Containers */
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
        /* Tabs */
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
        /* Metric Cards */
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
        /* Node Health */
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
        /* Responsive Styles */
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
        .main-content {
            width: 100%;
            padding: 15px;
        }
        .sidebar {
            background-color: var(--card-bg);
            border-right: 1px solid var(--border);
            min-height: 100vh;
            width: 250px;
        }
        .sidebar .nav-link {
            color: var(--text-light);
            padding: 10px 15px;
            border-radius: 6px;
            margin: 5px 10px;
            transition: all 0.2s;
            font-weight: 500;
        }
        .sidebar-toggle { display: none; }
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
        /* Additional RGB variables for opacity controls */
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
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    <button class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon"></i>
    </button>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 text text-decoration-none">
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
                <li>
                    <a href="heatmap.php" class="nav-link active <?= $current_page == 'heatmap.php' ? 'active' : '' ?>">
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
                    <a href="locations_Analytics.php" class="nav-link <?= $current_page == 'overallping.php' ? 'active' : '' ?>">
                        <i class="fas fa-wifi"></i>
                        Locations Analytics
                    </a>
                </li>
                 <li>
        <a href="location_information.php" class="nav-link  <?= $current_page == 'location_information.php' ? 'active' : '' ?>">
            <i class="fas fa-map-marker-alt"></i> Location Information
        </a>
    </li>
      <?php if ($isKnoativ): ?>
                <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container text-center mt-auto"></div>
        </div>
        <!-- Main Content -->
        <div class="main-content">
            <canvas id="netdata-bg"></canvas>
            <div id="locationsTreemap"></div>
        </div>
    </div>
    <script src="assets/js/live-poll.js?v=1"></script>
    <script>
// Simple treemap like before
const COLORS = {
    up: '#10b981',
    down: '#ef4444',
    stale: '#f59e0b',
    unknown: '#6b7280'
};

// PHP سے GWN AP mapping
const gwnApMapping = <?php echo json_encode($gwnApMapping); ?>;

// Simple treemap data function
function makeTreemapData(locationsObj) {
    const data = [];
    
    for (const name in locationsObj) {
        const loc = locationsObj[name] || {};
        const status = (loc.status || 'unknown').toLowerCase();
        const type = loc.type || 'mikrotik';
        
        // Get correct IP
        let deviceIp = loc.public_ip || '-';
        if (type === 'gwn_ap' && gwnApMapping[name]) {
            deviceIp = gwnApMapping[name];
        }
        
        // Simple size based on status and type
        let value = 100; // Default size
        
        if (type === 'gwn_ap') {
            value = 80; // APs smaller than routers
            const clients = parseInt(loc.clients) || 0;
            if (clients > 0) value += clients * 2;
        }
        
        if (status === 'up') value = value * 1.2;
        if (status === 'down') value = 40;

        data.push({
            name: name,
            value: value,
            itemStyle: {
                color: status === 'up' ? COLORS.up : 
                       status === 'down' ? COLORS.down : 
                       status === 'stale' ? COLORS.stale : COLORS.unknown
            },
            info: {
                public_ip: deviceIp,
                status: status,
                type: type,
                bandwidth_usage: loc.bandwidth_usage,
                clients: parseInt(loc.clients) || 0
            }
        });
    }
    
    return data;
}

// Initialize chart with simple options
const chart = echarts.init(document.getElementById('locationsTreemap'));
const option = {
    tooltip: {
        trigger: 'item',
        formatter: function(info) {
            const d = info.data || {};
            const inf = d.info || {};
            
            let html = `<div style="padding: 10px; min-width: 200px;">
                <strong style="font-size: 14px;">${echarts.format.encodeHTML(d.name)}</strong><br>`;
            
            if (inf.type === 'gwn_ap') {
                html += `<small>GWN Access Point</small><br>`;
            } else if (inf.type === 'mikrotik') {
                html += `<small>MikroTik Router</small><br>`;
            }
            
            html += `<hr style="margin: 8px 0; border-color: #555;">
                <strong>IP:</strong> ${inf.public_ip}<br>
                <strong>Status:</strong> <span style="color: ${inf.status === 'up' ? '#10b981' : inf.status === 'down' ? '#ef4444' : '#9ca3af'}">
                ${inf.status.toUpperCase()}</span>`;
            
            if (inf.type === 'sangfor' && inf.bandwidth_usage != null) {
                html += `<br><strong>Bandwidth:</strong> ${inf.bandwidth_usage} Mbps`;
            }
            if (inf.type === 'gwn_ap' && inf.clients > 0) {
                html += `<br><strong>Clients:</strong> ${inf.clients}`;
            }
            
            html += `</div>`;
            return html;
        }
    },
    series: [{
        type: 'treemap',
        roam: false,
        nodeClick: 'none',
        breadcrumb: { show: false },
        label: {
            show: true,
            formatter: '{b}',
            color: '#ffffff',
            fontSize: 14,
            fontWeight: 'bold',
            lineHeight: 18,
            position: 'inside',
            verticalAlign: 'middle',
            textAlign: 'center'
        },
        itemStyle: {
            borderColor: '#374151',
            borderWidth: 1
        },
        data: []
    }],
    animation: false
};

chart.setOption(option);

// Simple click handler
chart.on('click', function(params) {
    const info = params.data?.info || {};
    
    if (info.type === 'gwn_ap' || info.type === 'sangfor') {
        // Devices without a RouterOS detail page are not clickable
        console.log('No RouterOS detail page for:', params.name);
        return;
    }
    
    // MikroTik devices go to Test.php
    if (info.public_ip && info.public_ip !== '-') {
        window.location.href = `Test.php?ip=${encodeURIComponent(info.public_ip)}&status=${encodeURIComponent(info.status)}`;
    }
});

// Simple real-time updates
function initializeLivePolling() {
    const poller = LivePoller.create('update_cache.php?polling=1', function (data) {
        if (data && data.success && data.locations) {
            const treemapData = makeTreemapData(data.locations);
            chart.setOption({ series: [{ data: treemapData }] });
        }
    }, { interval: 10000 });
    window.dashboardPoller = poller;
    poller.start();
}


// Simple initial load
document.addEventListener('DOMContentLoaded', function() {
    try {
        const initialData = <?php echo json_encode($nodeData); ?>;
        const treemapData = makeTreemapData(initialData.locations || {});
        chart.setOption({ series: [{ data: treemapData }] });
    } catch (e) {
        console.error('Initial data error:', e);
    }
    
    initializeLivePolling();
});

window.addEventListener('resize', () => chart.resize());
    </script>
    <script>
        const canvas = document.getElementById('netdata-bg');
        const ctx = canvas.getContext('2d');

        let w, h;
        function resize() {
            w = canvas.width = window.innerWidth;
            h = canvas.height = window.innerHeight;
        }
        window.addEventListener('resize', resize);
        resize();

        const dots = [];
        const dotCount = 80;

        for (let i = 0; i < dotCount; i++) {
            dots.push({
                x: Math.random() * w,
                y: Math.random() * h,
                vx: (Math.random() - 0.5) * 0.7,
                vy: (Math.random() - 0.5) * 0.7,
                radius: Math.random() * 3 + 1
            });
        }

        function draw() {
            ctx.clearRect(0, 0, w, h);
            const gradient = ctx.createLinearGradient(0, 0, w, h);
            gradient.addColorStop(0, '#0f172a');
            gradient.addColorStop(1, '#1e293b');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, w, h);

            for (let i = 0; i < dotCount; i++) {
                let dot = dots[i];
                dot.x += dot.vx;
                dot.y += dot.vy;

                if (dot.x < 0 || dot.x > w) dot.vx *= -1;
                if (dot.y < 0 || dot.y > h) dot.vy *= -1;

                ctx.beginPath();
                ctx.arc(dot.x, dot.y, dot.radius, 0, Math.PI * 2);
                ctx.fillStyle = "#38bdf8";
                ctx.fill();

                for (let j = i + 1; j < dotCount; j++) {
                    let other = dots[j];
                    let dx = dot.x - other.x;
                    let dy = dot.y - other.y;
                    let dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 120) {
                        ctx.beginPath();
                        ctx.moveTo(dot.x, dot.y);
                        ctx.lineTo(other.x, other.y);
                        ctx.strokeStyle = "rgba(56, 189, 248, 0.2)";
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(draw);
        }
        draw();

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

            // Sidebar toggle for mobile
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
            });
        });
    </script>
</body>
</html>