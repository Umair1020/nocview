<?php
// locations.php

// Include config and get client-specific locations
require 'config.php';

$current_page = basename($_SERVER['PHP_SELF']);

// Get locations based on logged-in user
$locations = getClientLocations();

// Function to parse ip_details (kept for compatibility or future use)
function parseIpDetails($ipDetails) {
    $details = ['IP Range' => '', 'Netmask' => '', 'Gateway' => '', 'DNS' => '', 'S.DNS' => '', 'VLAN' => ''];
    if (strpos($ipDetails, 'Not assigned') !== false || strpos($ipDetails, 'Not fully assigned') !== false) {
        return $details; // Return empty details for incomplete entries
    }
    $parts = explode(' ', $ipDetails);
    foreach ($parts as $part) {
        if (strpos($part, 'NM:') === 0) {
            $details['Netmask'] = substr($part, 3);
        } elseif (strpos($part, 'GW:') === 0) {
            $details['Gateway'] = substr($part, 3);
        } elseif (strpos($part, 'DNS:') === 0 || strpos($part, 'P.DNS:') === 0) {
            $details['DNS'] = substr($part, strpos($part, ':') + 1);
        } elseif (strpos($part, 'S.DNS:') === 0) {
            $details['S.DNS'] = substr($part, 6);
        } elseif (strpos($part, 'Vlan:') === 0) {
            $details['VLAN'] = substr($part, 5);
        } elseif (strpos($part, '-') !== false || filter_var($part, FILTER_VALIDATE_IP)) {
            $details['IP Range'] = $part;
        }
    }
    return $details;
}

// Get current user for display
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_user = $_SESSION['username'] ?? 'ziauddin';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Locations | Network Dashboard</title>
 
     <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
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
            overflow-y: auto;
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
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
            color: var(--success);
        }

        .nav-tabs .nav-link.active {
            color: var(--success);
            background-color: transparent;
            border-color: var(--border) var(--border) var(--bg);
            border-bottom: none;
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
        .text-netdata-blue { color: var(--text-muted); }
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
        .location-card.warning { border-left-color: var(--success); }
        .location-card.down { border-left-color: var(--success); }

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

        .location-card .status-badge.warning {
            background: rgba(var(--warning-rgb), 0.1);
            color: var(--warning);
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

        .location-card { border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 30px; overflow: hidden; }
        .location-header { display: flex; align-items: center; gap: 15px; padding: 15px; background: var(--card-bg); border-bottom: 1px solid var(--border); }

        iframe { width: 100%; height: 250px; border: 0; border-radius: 0 0 15px 15px; }
        .details-table { width: 100%; font-size: 12px; }
        .details-table th, .details-table td { padding: 8px; vertical-align: top; }
        .details-table th { color: var(--text-muted); width: 30%; font-weight: 600; }
        .details-table td { color: var(--text); }
        .not-assigned { font-style: italic; color: var(--text-muted); }
    </style>
</head>
<body class="dark-mode">
    <!-- Theme Toggle -->
    <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3 text-decoration-none">
                <div class="logo-container"></div>
            </a>
            <hr>
<ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item"><a href="index.php" class="nav-link "><i class="fas fa-tachometer-alt"></i> Overview</a></li>
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
        <li><a href="location_information.php" class="nav-link active"><i class="fas fa-map-marker-alt"></i> Location Information</a></li>
        <?php if ($_SESSION['username'] === 'Knoativ'): ?>
          <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
        <?php endif; ?>
      </ul>
            <div class="spotcomm-logo-container mt-auto"></div>
        </div>

        <!-- Main content -->
        <div class="main-content flex-grow-1">
            <div class="dashboard-header mb-4">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1><i class="fas fa-map-marker-alt"></i> All Locations</h1>
                        <p class="mb-0">Detailed view of all network locations </p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="d-flex justify-content-md-end gap-3">
                            <a href="index.php" class="btn-custom back-btn"><i class="fas fa-arrow-left"></i> Back</a>
                            <a href="logout.php" class="btn-custom logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            <button class="btn-custom refresh-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Network Summary -->

            <!-- Locations Grid -->
            <div class="row">
                <?php if (empty($locations)): ?>
                    <div class="col-12">
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle"></i> No locations found for your account.
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($locations as $index => $location): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="location-card <?= strpos($location['status'], 'Live') !== false ? 'up' : (strpos($location['status'], 'disrupting') !== false ? 'warning' : 'down') ?>">
                                <h3>
                                    <span><?= htmlspecialchars($location['name']) ?></span>
                                    <span class="public-ip"><?= htmlspecialchars($location['ip']) ?></span>
                                </h3>
                                <div class="location-body">
                                    <table class="details-table">
                                        <tr>
                                            <th>S.No</th>
                                            <td><?= $index + 1 ?></td>
                                        </tr>
                                        <tr>
                                            <th>Address</th>
                                            <td>
                                                <a href="https://www.google.com/maps?q=<?= $location['lat'] ?>,<?= $location['lng'] ?>" target="_blank" class="text-netdata-blue">
                                                    <?= htmlspecialchars($location['address']) ?> (Click For Directions)
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>CIR Connectivity</th>
                                            <td><?= htmlspecialchars($location['connectivity']) ?></td>
                                        </tr>
                                        
                                        <tr>
                                            <th>IP Range</th>
                                            <td><?= htmlspecialchars($location['ip_details']) ?: 'Not assigned' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Netmask</th>
                                            <td><?= htmlspecialchars($location['NM']) ?: 'Not assigned' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Gateway</th>
                                            <td><?= htmlspecialchars($location['GW']) ?: 'Not assigned' ?></td>
                                        </tr>
                                        <tr>
                                            <th>DNS</th>
                                            <td><?= htmlspecialchars($location['DNS']) ?: 'Not assigned' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Secondary DNS</th>
                                            <td><?= htmlspecialchars($location['S.DNS']) ?: 'Not assigned' ?></td>
                                        </tr>
                                        <tr>
                                            <th>VLAN</th>
                                            <td><?= htmlspecialchars($location['Vlan']) ?: 'Not assigned' ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="mt-2">
                                    <iframe src="https://www.google.com/maps?q=<?= $location['lat'] ?>,<?= $location['lng'] ?>&z=15&output=embed" loading="lazy"></iframe>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('themeToggle').addEventListener('click', function() {
            document.body.classList.toggle('light-mode');
            let icon = this.querySelector('i');
            if(document.body.classList.contains('light-mode')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        });
    </script>
</body>
</html>