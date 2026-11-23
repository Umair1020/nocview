<?php
session_start();
require_once 'config.php';
require('routeros_api.class.php');

$currentClient = getCurrentClient();
$mt_user = $currentClient['MT_USER'];     // CC: 'api'
$mt_pass = $currentClient['MT_PASS'];     // CC: 'compaq123_'
$locationMap = $currentClient['locationMap'];
$interfaceMap = $currentClient['interfaceMap'];

$allowed_ips = [
    '103.246.108.50', '103.246.109.74', '103.246.109.82', '103.246.109.90',
    '103.246.109.98', '103.246.109.106', '103.246.109.114', '103.246.109.130',
    '103.246.109.138', '103.246.109.146', '103.246.109.154', '115.42.67.210' ,'115.42.56.66'
];

$ip = $_GET['ip'] ?? '115.42.67.210';  // CC DEFAULT!

// Ping-only devices have no RouterOS detail page; never log in using MikroTik credentials.
if (isset($currentClient['pingLocations'][$ip])) {
    http_response_code(404);
    exit('RouterOS details are not available for this device.');
}

if (!in_array($ip, $allowed_ips) || !filter_var($ip, FILTER_VALIDATE_IP)) {
    $ip = '115.42.67.210';
}

$interface = $interfaceMap[$ip] ?? 'ether1';
$locationName = $locationMap[$ip] ?? $ip;

// Location CPU is read live from the routers. No file cache.
// It is fetched only when a request asks for it (page load and every 30 s from the browser).
function fetchLocationCpu($locationMap, $mt_user, $mt_pass) {
    $out = [];
    foreach (array_keys($locationMap) as $loc_ip) {
        try {
            $loc_api = new RouterosAPI();
            $loc_api->timeout = 3;
            if ($loc_api->connect($loc_ip, $mt_user, $mt_pass)) {
                $loc_api->write('/system/resource/print');
                $loc_res = $loc_api->read();
                $out[$locationMap[$loc_ip] ?? $loc_ip] = ['cpu' => round($loc_res[0]['cpu-load'] ?? 0, 2)];
                $loc_api->disconnect();
            }
        } catch (Exception $e) {
            error_log("Location API error for $loc_ip: " . $e->getMessage());
        }
    }
    return $out;
}
$location_metrics = []; // filled by the first partial poll, not on page load
$wantLocations = isset($_GET['locations']) && $_GET['locations'] === '1';

// Increase limits for shared hosting
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// ---------------- PING AND DNS LATENCY FUNCTIONS ----------------
// Real TCP connect time to the router's API port, in ms. null when unreachable.
function getPingLatency($host) {
    $start = microtime(true);
    $fp = @fsockopen($host, 8728, $errno, $errstr, 2);
    if (!$fp) return null;
    $ms = round((microtime(true) - $start) * 1000, 1);
    fclose($fp);
    return $ms;
}
// Real DNS lookup time in ms. null when the lookup fails.
function getDnsLatency() {
    $start = microtime(true);
    $resolved = @gethostbyname('google.com');
    $ms = round((microtime(true) - $start) * 1000, 1);
    return ($resolved && $resolved !== 'google.com') ? $ms : null;
}

// ---------------- SAFE DEFAULTS ----------------
$stats = [
    'interface' => [],
    'system' => [
        'cpu-load' => 0, 'free-memory' => 1, 'total-memory' => 1, 'uptime' => '0s',
        'free-hdd-space' => 1, 'total-hdd-space' => 1, 'cpu-count' => 1, 'cpu-frequency' => 1400
    ],
    'health' => []
];
$connected = false;

// ---------------- FETCH MINIMAL DATA ----------------
try {
    $API = new RouterosAPI();
    $API->timeout = 15;
    $API->debug = false;
    if ($API->connect($ip, $mt_user, $mt_pass)) {
        $connected = true;
        error_log("Connected to $ip successfully");
        $API->write('/system/resource/print');
        $resource = $API->read();
        if (!empty($resource[0])) $stats['system'] = array_merge($stats['system'], $resource[0]);
        $API->write('/interface/monitor-traffic', false);
        $API->write("=interface={$interface}", false);
        $API->write('=once=');
        $ifaces = $API->read();
        $stats['interface'] = $ifaces[0] ?? [];
        $API->write('/system/health/print');
        $health = $API->read();
        if (!empty($health[0])) $stats['health'] = $health[0];
        $API->disconnect();
    } else {
        error_log("Failed to connect to $ip");
    }
} catch (Exception $e) {
    error_log("RouterOS API error for $ip: " . $e->getMessage());
}

// ---------------- PARSE METRICS ----------------
$cpu_load = round($stats['system']['cpu-load'] ?? 0, 2);
$total_mem = max(1, $stats['system']['total-memory'] ?? 1);
$free_mem = $stats['system']['free-memory'] ?? 0;
$mem_usage = round((1 - ($free_mem / $total_mem)) * 100, 2);
$total_hdd = $stats['system']['total-hdd-space'] ?? 1;
$free_hdd = $stats['system']['free-hdd-space'] ?? 0;
$disk_usage = round((($total_hdd - $free_hdd) / $total_hdd) * 100, 2);
$rx_bps = $stats['interface']['rx-bits-per-second'] ?? 0;
$tx_bps = $stats['interface']['tx-bits-per-second'] ?? 0;
$rx_mbps = round($rx_bps / 1000000, 3);
$tx_mbps = round($tx_bps / 1000000, 3);
$uptime = formatUptime(parse_uptime_seconds($stats['system']['uptime'] ?? '0s'));

function parse_uptime_seconds($u) {
    preg_match_all('/(\d+)([wdhms])/i', $u, $m, PREG_SET_ORDER);
    $s = 0;
    foreach ($m as $p) {
        $n = (int)$p[1];
        $unit = strtolower($p[2]);
        $s += $unit == 'w' ? $n * 604800 : ($unit == 'd' ? $n * 86400 : ($unit == 'h' ? $n * 3600 : ($unit == 'm' ? $n * 60 : $n)));
    }
    return $s;
}

function formatUptime($s) {
    $d = floor($s / 86400);
    $s %= 86400;
    $h = floor($s / 3600);
    $s %= 3600;
    $m = floor($s / 60);
    return "{$d}d {$h}h {$m}m";
}

$cpu_temp = round($stats['health']['cpu-temperature'] ?? ($stats['health']['temperature'] ?? 0), 1);
$cpu_temp_display = $cpu_temp > 0 ? $cpu_temp . '°C' : '0°C';
$voltage = round($stats['health']['voltage'] ?? 0, 2);
$voltage_display = $voltage > 0 ? $voltage . 'V' : '0V';
$cpu_pressure_some = max(0, min(100, $cpu_load * 0.12));
$memory_pressure_some = round($mem_usage * 0.01, 3);
$memory_stall_ms = round($mem_usage * 0.1, 2);
$memory_full_pressure = round($mem_usage * 0.02, 3);
$disk_io_pressure = round((($rx_mbps + $tx_mbps) > 0) ? (abs($rx_mbps - $tx_mbps) / max(0.0001, ($rx_mbps + $tx_mbps))) * 100 : 0, 2);
$io_stall_ms = round(max($rx_mbps, $tx_mbps) * 2.5, 2);
$system_load_1m = round($cpu_load / max(1, ($stats['system']['cpu-count'] ?? 1)), 3);
$active_processes = $stats['system']['cpu-count'] ?? 0;
$rx_packets = $stats['interface']['rx-packets-per-second'] ?? 0;
$tx_packets = $stats['interface']['tx-packets-per-second'] ?? 0;
$rx_errors = $stats['interface']['rx-errors-per-second'] ?? 0;
$tx_errors = $stats['interface']['tx-errors-per-second'] ?? 0;
$packet_loss = ($rx_packets + $tx_packets) > 0 ? round(($rx_errors + $tx_errors) / ($rx_packets + $tx_packets) * 100, 2) : 0;
$ping_ms = getPingLatency($ip);
$dns_ms = getDnsLatency();

// ---------------- AJAX / PARTIAL JSON ----------------
if (isset($_GET['partial'])) {
    $data = [
        'connected' => false, 'ip' => $ip, 'interface' => $interface, 'location' => $locationName,
        'timestamp' => round(microtime(true) * 1000), 'cpu_load' => 0, 'memory_usage' => 0, 'disk_usage' => 0,
        'rx_mbps' => 0, 'tx_mbps' => 0, 'formatted_uptime' => '0s', 'cpu_temp' => '0°C', 'cpu_temp_raw' => 0,
        'voltage' => '0V', 'active_processes' => 0, 'system_load_1m' => 0, 'total_memory' => 0, 'free_memory' => 0,
        'total_hdd' => 0, 'free_hdd' => 0, 'packet_loss' => 0, 'ping_ms' => null, 'dns_ms' => null,
        'cpu_pressure_some' => 0, 'memory_pressure_some' => 0, 'memory_stall_ms' => 0, 'memory_full_pressure' => 0,
        'disk_io_pressure' => 0, 'io_stall_ms' => 0, 'location_metrics' => [], 'error' => null
    ];

    // Location CPU: live, and only when the browser asks for it
    $data['location_metrics'] = $wantLocations ? fetchLocationCpu($locationMap, $mt_user, $mt_pass) : null;

    try {
        $API = new RouterosAPI();
        $API->timeout = 15;
        $API->debug = false;
        if ($API->connect($ip, $mt_user, $mt_pass)) {
            $data['connected'] = true;
            $API->write('/system/resource/print');
            $res = $API->read();
            if (!empty($res[0])) {
                $data['cpu_load'] = round($res[0]['cpu-load'] ?? 0, 2);
                $total_mem = max(1, $res[0]['total-memory'] ?? 1);
                $data['free_memory'] = round(($res[0]['free-memory'] ?? 0) / 1024 / 1024, 2);
                $data['total_memory'] = round($total_mem / 1024 / 1024, 2);
                $data['memory_usage'] = round((1 - ($res[0]['free-memory'] / $total_mem)) * 100, 2);
                $data['formatted_uptime'] = formatUptime(parse_uptime_seconds($res[0]['uptime'] ?? '0s'));
                $data['active_processes'] = $res[0]['cpu-count'] ?? 0;
                $data['system_load_1m'] = round($data['cpu_load'] / max(1, ($res[0]['cpu-count'] ?? 1)), 3);
            }

            $API->write('/interface/monitor-traffic', false);
            $API->write("=interface={$interface}", false);
            $API->write('=once=');
            $ifaces = $API->read();
            if (!empty($ifaces[0])) {
                $rx_bps = $ifaces[0]['rx-bits-per-second'] ?? 0;
                $tx_bps = $ifaces[0]['tx-bits-per-second'] ?? 0;
                $data['rx_mbps'] = round($rx_bps / 1000000, 3);
                $data['tx_mbps'] = round($tx_bps / 1000000, 3);
                $rx_packets = $ifaces[0]['rx-packets-per-second'] ?? 0;
                $tx_packets = $ifaces[0]['tx-packets-per-second'] ?? 0;
                $rx_errors = $ifaces[0]['rx-errors-per-second'] ?? 0;
                $tx_errors = $ifaces[0]['tx-errors-per-second'] ?? 0;
                $data['packet_loss'] = ($rx_packets + $tx_packets) > 0 ? round(($rx_errors + $tx_errors) / ($rx_packets + $tx_packets) * 100, 2) : 0;
            }

            $API->write('/system/health/print');
            $health = $API->read();
            if (!empty($health[0])) {
                $data['cpu_temp_raw'] = round($health[0]['cpu-temperature'] ?? ($health[0]['temperature'] ?? 0), 1);
                $data['cpu_temp'] = $data['cpu_temp_raw'] > 0 ? $data['cpu_temp_raw'] . '°C' : '0°C';
                $data['voltage'] = round($health[0]['voltage'] ?? 0, 2) . 'V';
            }

            $total_hdd = $res[0]['total-hdd-space'] ?? 1;
            $free_hdd = $res[0]['free-hdd-space'] ?? 0;
            $data['total_hdd'] = round($total_hdd / 1024 / 1024, 2);
            $data['free_hdd'] = round($free_hdd / 1024 / 1024, 2);
            $data['disk_usage'] = round((($total_hdd - $free_hdd) / $total_hdd) * 100, 2);

            $data['cpu_pressure_some'] = max(0, min(100, $data['cpu_load'] * 0.12));
            $data['memory_pressure_some'] = round($data['memory_usage'] * 0.01, 3);
            $data['memory_stall_ms'] = round($data['memory_usage'] * 0.1, 2);
            $data['memory_full_pressure'] = round($data['memory_usage'] * 0.02, 3);
            $data['disk_io_pressure'] = round((($data['rx_mbps'] + $data['tx_mbps']) > 0) ? (abs($data['rx_mbps'] - $data['tx_mbps']) / max(0.0001, ($data['rx_mbps'] + $data['tx_mbps']))) * 100 : 0, 2);
            $data['io_stall_ms'] = round(max($data['rx_mbps'], $data['tx_mbps']) * 2.5, 2);

            $data['ping_ms'] = getPingLatency($ip);
            $data['dns_ms'] = getDnsLatency();

            $API->disconnect();
        } else {
            $data['error'] = "Failed to connect to router at $ip. Check IP, credentials, or network.";
        }
    } catch (Exception $e) {
        $data['error'] = "RouterOS API error: " . $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($data, JSON_NUMERIC_CHECK);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MikroTik Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
      :root {
            /* Dark Theme Variables */
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
            /* RGB for opacity */
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
    display: flex
;
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
        .logo {
            display: block;
            margin: 6px auto 12px;
            width: 110px;
        }
        .nav-link {
         
            padding: 10px;
            border-radius: 8px;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.02);
            color: var(--text);
        }
        .main {
            margin-left: 280px;
            padding: 18px;
            overflow-x: hidden;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }
        .card-panel {
            background: linear-gradient(180deg, var(--panel), var(--panel));
            border-radius: 10px;
            padding: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
        }
        .card-panel.disconnected {
            opacity: 0.5;
            filter: grayscale(50%);
        }
        .small {
            font-size: 13px;
            color: var(--muted);
        }
        .kv {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-up {
            background: rgba(46,160,67,0.12);
            color: var(--accent);
            padding: 6px 10px;
            border-radius: 10px;
        }
        .badge-down {
            background: rgba(248,81,73,0.08);
            color: #f85149;
            padding: 6px 10px;
            border-radius: 10px;
        }
        .small-muted {
            font-size: 12px;
            color: var(--muted);
            font-weight: bold;
        }
        .toggle-btn {
            display: none;
        }
        .theme-toggle-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: var(--text);
        }
        .gauge-container {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin: 20px 0;
        }
        .gauge {
            width: 100%;
            height: 400px;
        }
        .chart {
            width: 100%;
            height: 180px;
        }
        .chart-large {
            width: 100%;
            height: 350px;
        }
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 20px 0 10px;
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        @media (max-width: 991px) {
            .main {
                margin-left: 0;
                padding: 12px;
            }
            .sidebar {
                display: none;
            }
            .sidebar.show {
                display: block;
            }
            .toggle-btn {
                display: block;
                margin: 10px;
                background: none;
                border: none;
                color: var(--text);
            }
        }
        .light-mode .form-control {
            background: #fff;
            color: #000;
            border-color: #ced4da;
        }
        .light-mode .btn-outline-light {
            border-color: #ced4da;
            color: #000;
        }
        .light-mode .btn-outline-light:hover {
            background: #e9ecef;
        }
    </style>
</head>
<body class="dark-mode">
    <button class="sidebar-toggle toggle-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
   <div class="theme-toggle" id="themeToggle" title="Toggle theme">
  <i class="fas fa-moon" id="themeIcon"></i>
</div>

    <div class="d-flex">
        <div class="sidebar d-flex flex-column p-3" id="sidebar">
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
              <a href="locations.php" class="nav-link <?= $current_page == 'locations.php' ? 'active' : '' ?>">
                  <i class="fas fa-network-wired"></i>
                  All Locations
              </a>
          </li>
          <li>
              <a href="heatmap.php" class="nav-link <?= $current_page == 'heatmap.php' ? 'active' : '' ?>">
                  <i class="fas fa-th"></i>
                  Heatmap
              </a>
          </li>
          <li>
              <a href="bandwidth.php" class="nav-link  <?= $current_page == 'bandwidth.php' ? 'active' : '' ?>">
                  <i class="fas fa-chart-line"></i>
                  Bandwidth
              </a>
          </li>
          <li>
              <a href="locations_Analytics.php" class="nav-link  <?= $current_page == 'overallping.php' ? 'active' : '' ?>">
                  <i class="fas fa-wifi"></i>
                  Locations Analytics
              </a>
          </li>
           <li>
        <a href="location_information.php" class="nav-link  <?= $current_page == 'location_information.php' ? 'active' : '' ?>">
            <i class="fas fa-map-marker-alt"></i> Location Information
        </a>
    </li>
      </ul>
          
            <div class="spotcomm-logo-container text-center mt-auto"></div>
        </div>

        <main class="main container">
            <div class="header">
                <div>
                    <h3 style="margin:0;font-weight:600"><?= htmlspecialchars($locationName) ?></h3>
                    <div class="small-muted"><?= htmlspecialchars($ip) ?></div>
                    <span id="live-time" class="fw-bold"></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="small-muted">Uptime</div>
                    <div id="uptime" class="badge-up"><?= htmlspecialchars($uptime) ?></div>
                    <div id="apiStatus" class="<?= $connected ? 'badge-up' : 'badge-down' ?>"><?= $connected ? 'Connected' : 'Disconnected' ?></div>
                </div>
            </div>

            <div id="errorToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <strong class="me-auto">Error</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body" id="errorToastBody"></div>
            </div>

            <!-- System Metrics Section -->
            <div class="section-title">System Metrics</div>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mb-3">
                <div class="col">
                    <div class="card-panel" id="cpuPanel">
                        <div class="kv mb-2">
                            <div><strong>CPU</strong><div class="small-muted">Utilization / Pressure / Load</div></div>
                            <div id="cpuNow" class="small-muted"><?= $cpu_load ?>%</div>
                        </div>
                        <div id="cpuGauge" class="chart"></div>
                        <div class="d-flex justify-content-between small-muted mt-2">
                            <div>Pressure: <strong id="cpuPressureText"><?= $cpu_pressure_some ?>%</strong></div>
                            <div>Load: <strong id="cpuLoadText"><?= $system_load_1m ?></strong></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="memPanel">
                        <div class="kv mb-2">
                            <div><strong>Memory Stats</strong><div class="small-muted">Total / Free</div></div>
                            <div id="memNow" class="small-muted"><?= $mem_usage ?>%</div>
                        </div>
                        <div id="memGauge" class="chart"></div>
                        <div class="d-flex justify-content-between small-muted mt-2">
                            <div>Stall: <strong id="memStallText"><?= $memory_stall_ms ?> ms</strong></div>
                            <div>FullP: <strong id="memFullText"><?= $memory_full_pressure ?></strong></div>
                            <div>Total: <strong id="totalMem"><?= round($total_mem / 1024 / 1024, 2) ?> MB</strong></div>
                            <div>Free: <strong id="freeMem"><?= round($free_mem / 1024 / 1024, 2) ?> MB</strong></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="diskPanel">
                        <div class="kv mb-2">
                            <div><strong>Disk Stats</strong><div class="small-muted">Total / Free</div></div>
                            <div id="diskNow" class="small-muted"><?= $disk_usage ?>%</div>
                        </div>
                        <div id="diskGauge" class="chart"></div>
                        <div class="d-flex justify-content-between small-muted mt-2">
                            <div>Total: <strong id="totalHdd"><?= round($total_hdd / 1024 / 1024, 2) ?> MB</strong></div>
                            <div>Free: <strong id="freeHdd"><?= round($free_hdd / 1024 / 1024, 2) ?> MB</strong></div>
                            <div>IO P: <strong id="diskPressureText"><?= $disk_io_pressure ?>%</strong></div>
                            <div>IO Stall: <strong id="ioStallText"><?= $io_stall_ms ?> ms</strong></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Network Metrics Section -->
            <div class="section-title">Network Metrics</div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card-panel" id="downloadPanel">
                        <div class="kv mb-2">
                            <div><strong>Download (RX)</strong></div>
                            <div class="small-muted" id="rxNow"><?= $rx_mbps ?> Mbps</div>
                        </div>
                        <div id="downloadGauge" class="gauge"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card-panel" id="uploadPanel">
                        <div class="kv mb-2">
                            <div><strong>Upload (TX)</strong></div>
                            <div class="small-muted" id="txNow"><?= $tx_mbps ?> Mbps</div>
                        </div>
                        <div id="uploadGauge" class="gauge"></div>
                    </div>
                </div>
            </div>

            <!-- Historical Data Section -->
            <div class="section-title">Historical Data</div>
            <div class="row g-3 mb-3">
                <div class="col">
                    <div class="card-panel" id="cpuChartPanel">
                        <div class="kv mb-2">
                            <div><strong>CPU Usage</strong><div class="small-muted">Line Chart</div></div>
                        </div>
                        <div id="cpuChart" class="chart-large"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="memChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Memory Usage</strong><div class="small-muted">Line Chart</div></div>
                        </div>
                        <div id="memChart" class="chart-large"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="bandwidthChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Bandwidth</strong><div class="small-muted">RX/TX</div></div>
                        </div>
                        <div id="bandwidthChart" class="chart-large"></div>
                    </div>
                </div>
            </div>

            <!-- Location Analytics Section -->
           

            <!-- Advanced Metrics Section -->
            <div class="section-title">Advanced Metrics</div>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mb-3">
                <div class="col">
                    <div class="card-panel" id="pressureChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Pressure Metrics</strong><div class="small-muted">Stacked Polar Bar</div></div>
                        </div>
                        <div id="pressureChart" class="chart"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="doughnutChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Resource Usage</strong><div class="small-muted">Doughnut</div></div>
                        </div>
                        <div id="doughnutChart" class="chart"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="packetLossChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Packet Loss</strong><div class="small-muted">Area Chart</div></div>
                        </div>
                        <div id="packetLossChart" class="chart"></div>
                    </div>
                </div>
            </div>

            <!-- System Health Section -->
            <div class="section-title">System Health</div>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mb-3">
                <div class="col">
                    <div class="card-panel" id="cpuTempChartPanel">
                        <div class="kv mb-2">
                            <div><strong>CPU Temperature</strong><div class="small-muted">Line Chart</div></div>
                        </div>
                        <div id="cpuTempChart" class="chart"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="systemLoadChartPanel">
                        <div class="kv mb-2">
                            <div><strong>System Load</strong><div class="small-muted">Gradient Line</div></div>
                        </div>
                        <div id="systemLoadChart" class="chart"></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel" id="pingPanel">
                        <div class="kv mb-2">
                            <div><strong>Ping</strong><div class="small-muted">Location Latency</div></div>
                            <div id="pingNow" class="small-muted"><?= $ping_ms === null ? 'N/A' : $ping_ms . ' ms' ?></div>
                        </div>
                        <div id="pingGauge" class="chart"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <div class="card-panel" id="dnsPanel">
                        <div class="kv mb-2">
                            <div><strong>DNS Latency</strong><div class="small-muted">Resolution time to google.com</div></div>
                            <div id="dnsNow" class="small-muted"><?= $dns_ms === null ? 'N/A' : $dns_ms . ' ms' ?></div>
                        </div>
                        <div id="dnsGauge" class="chart"></div>
                    </div>
                </div>
            </div>
 <div class="section-title">Location Analytics</div>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card-panel" id="locationRaceChartPanel">
                        <div class="kv mb-2">
                            <div><strong>Location CPU Race</strong><div class="small-muted">Bar Race</div></div>
                        </div>
                        <div id="locationRaceChart" class="chart-large"></div>
                    </div>
                </div>
            </div>

            
            <!-- Resource Summary Section -->
            <div class="section-title">Resource Summary</div>
            <div class="row row-cols-1 row-cols-md-2 g-3 mb-3">
                <div class="col">
                    <div class="card-panel">
                        <div class="kv mb-2">
                            <div><strong>Memory Stats</strong><div class="small-muted">Total / Free</div></div>
                        </div>
                        <div class="small-muted">Total: <strong id="totalMem"><?= round($total_mem / 1024 / 1024, 2) ?> MB</strong></div>
                        <div class="small-muted">Free: <strong id="freeMem"><?= round($free_mem / 1024 / 1024, 2) ?> MB</strong></div>
                        <div class="small-muted">Used: <strong id="usedMem"><?= round(($total_mem - $free_mem) / 1024 / 1024, 2) ?> MB</strong></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel">
                        <div class="kv mb-2">
                            <div><strong>Disk Stats</strong><div class="small-muted">Total / Free</div></div>
                        </div>
                        <div class="small-muted">Total: <strong id="totalHdd"><?= round($total_hdd / 1024 / 1024, 2) ?> MB</strong></div>
                        <div class="small-muted">Free: <strong id="freeHdd"><?= round($free_hdd / 1024 / 1024, 2) ?> MB</strong></div>
                        <div class="small-muted">Used: <strong id="usedHdd"><?= round(($total_hdd - $free_hdd) / 1024 / 1024, 2) ?> MB</strong></div>
                    </div>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-3 mb-3">
                <div class="col">
                    <div class="card-panel small-muted">
                        CPU Temp
                        <div class="fw-bold mt-2" id="cpuTemp"><?= htmlspecialchars($cpu_temp_display) ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel small-muted">
                        Voltage
                        <div class="fw-bold mt-2" id="voltageText"><?= htmlspecialchars($voltage_display) ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel small-muted">
                        Active Processes
                        <div class="fw-bold mt-2" id="activeProc"><?= $active_processes ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card-panel small-muted">
                        System Load (1m)
                        <div class="fw-bold mt-2" id="sysLoad"><?= $system_load_1m ?></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    const charts = {};
    const MAX_POINTS = 15;
    let times = [], cpuSeries = [], memSeries = [], rxSeries = [], txSeries = [], ioSeries = [],
        cpuPressureSeries = [], memPressureSeries = [], packetLossSeries = [], cpuTempSeries = [],
        systemLoadSeries = [], pingSeries = [], dnsSeries = [], locationSeries = {};
    let lastLocFetch = 0; // ms timestamp of the last live location fetch
    const initialLocationMetrics = <?= json_encode($location_metrics, JSON_NUMERIC_CHECK) ?>;

    // Initialize with current data
    times = Array(10).fill(new Date().toLocaleTimeString());
    cpuSeries = Array(10).fill(<?= json_encode($cpu_load) ?>);
    memSeries = Array(10).fill(<?= json_encode($mem_usage) ?>);
    rxSeries = Array(10).fill(<?= json_encode($rx_mbps) ?>);
    txSeries = Array(10).fill(<?= json_encode($tx_mbps) ?>);
    ioSeries = Array(10).fill(<?= json_encode($io_stall_ms) ?>);
    cpuPressureSeries = Array(10).fill(<?= json_encode($cpu_pressure_some) ?>);
    memPressureSeries = Array(10).fill(<?= json_encode($memory_pressure_some) ?>);
    packetLossSeries = Array(10).fill(<?= json_encode($packet_loss) ?>);
    cpuTempSeries = Array(10).fill(<?= json_encode($cpu_temp) ?>);
    systemLoadSeries = Array(10).fill(<?= json_encode($system_load_1m) ?>);
    pingSeries = Array(10).fill(<?= json_encode($ping_ms) ?>);
    dnsSeries = Array(10).fill(<?= json_encode($dns_ms) ?>);
    locationSeries = initialLocationMetrics;

    function ringGaugeOpt(value, max = 100, color = '#2ea043', label) {
        return {
            series: [{
                type: 'gauge', radius: '80%', center: ['50%', '60%'], startAngle: 270, endAngle: -270,
                min: 0, max: max, progress: { show: true, width: 8, itemStyle: { color: color } },
                axisLine: { lineStyle: { width: 8, color: [[1, '#1e2a38']] } },
                axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
                pointer: { show: false }, detail: { valueAnimation: true, formatter: `{value}${label}`, offsetCenter: [0, '10%'], fontSize: 14 },
                data: [{ value: value }]
            }]
        };
    }

    function gaugeOption(title, value, max = 1000) {
        return {
            series: [{
                type: 'gauge', min: 0, max: max, splitNumber: 5, axisLine: { lineStyle: { width: 6, color: [[1, '#2ea043']] } },
                pointer: { length: '60%', width: 3 }, title: { show: true, offsetCenter: [0, '70%'], fontSize: 12, color: '#9aa4ad' },
                detail: { valueAnimation: true, formatter: '{value} Mbps', fontSize: 16, color: '#fff' },
                data: [{ value: value, name: title }]
            }]
        };
    }

    function pingGaugeOpt(value) {
        return {
            series: [{
                type: 'gauge', min: 0, max: 100, splitNumber: 5, radius: '90%',
                axisLine: { lineStyle: { width: 20, color: [[0.3, '#67e0e3'], [0.7, '#37a2da'], [1, '#fd666d']] } },
                pointer: { itemStyle: { color: 'auto' } }, axisTick: { distance: -20, length: 5, lineStyle: { color: '#fff', width: 1 } },
                splitLine: { distance: -20, length: 20, lineStyle: { color: '#fff', width: 2 } },
                axisLabel: { color: 'auto', distance: 25, fontSize: 10, formatter: value => value + ' ms' },
                detail: { valueAnimation: true, formatter: '{value} ms', fontSize: 18, offsetCenter: [0, '70%'] },
                data: [{ value: value }]
            }]
        };
    }

    function dnsGaugeOpt(value) {
        return {
            series: [{
                type: 'gauge', startAngle: 180, endAngle: 0, min: 0, max: 100, splitNumber: 5,
                axisLine: { lineStyle: { width: 10, color: [[0.3, '#67e0e3'], [0.7, '#37a2da'], [1, '#fd666d']] } },
                pointer: { show: true, length: '60%', width: 4, itemStyle: { color: 'auto' } },
                axisTick: { show: true, distance: -20, length: 5, lineStyle: { color: '#999', width: 1 } },
                splitLine: { show: true, distance: -20, length: 20, lineStyle: { color: '#999', width: 2 } },
                axisLabel: { show: true, distance: 10, color: '#999', fontSize: 8, formatter: value => value === 0 ? '0 ms' : value + ' ms' },
                anchor: { show: true, showAbove: true, size: 20, itemStyle: { color: '#999', borderColor: '#fff', borderWidth: 2 } },
                title: { show: true, offsetCenter: [0, '-40%'], fontWeight: 'bold', fontSize: 14, color: '#fff' },
                detail: { show: true, valueAnimation: true, formatter: '{value} ms', color: '#fff', fontSize: 20, offsetCenter: [0, '40%'] },
                data: [{ value: value, name: 'DNS' }]
            }]
        };
    }

    function lineOpt(name, data, yLabel, color, yType = 'value') {
        return {
            xAxis: { type: 'category', data: times, axisLabel: { fontSize: 10 } },
            yAxis: { type: yType, name: yLabel, axisLabel: { fontSize: 10 } },
            series: [{ name: name, type: 'line', data: data, smooth: true, lineStyle: { color: color }, animation: true }]
        };
    }

    function gradientLineOpt(name, data, yLabel, color) {
        return {
            xAxis: { type: 'category', data: times, axisLabel: { fontSize: 10 } },
            yAxis: { type: 'value', name: yLabel, axisLabel: { fontSize: 10 } },
            series: [{
                name: name, type: 'line', data: data, smooth: true, animation: true,
                lineStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: color }, { offset: 1, color: '#fff' }]) }
            }]
        };
    }

    function initCharts() {
        requestAnimationFrame(() => {
            charts.cpuGauge = echarts.init(document.getElementById('cpuGauge'));
            charts.memGauge = echarts.init(document.getElementById('memGauge'));
            charts.diskGauge = echarts.init(document.getElementById('diskGauge'));
            charts.downloadGauge = echarts.init(document.getElementById('downloadGauge'));
            charts.uploadGauge = echarts.init(document.getElementById('uploadGauge'));
            charts.cpuChart = echarts.init(document.getElementById('cpuChart'));
            charts.memChart = echarts.init(document.getElementById('memChart'));
            charts.bandwidthChart = echarts.init(document.getElementById('bandwidthChart'));
            charts.pressureChart = echarts.init(document.getElementById('pressureChart'));
            charts.doughnutChart = echarts.init(document.getElementById('doughnutChart'));
            charts.locationRaceChart = echarts.init(document.getElementById('locationRaceChart'));
            charts.packetLossChart = echarts.init(document.getElementById('packetLossChart'));
            charts.cpuTempChart = echarts.init(document.getElementById('cpuTempChart'));
            charts.systemLoadChart = echarts.init(document.getElementById('systemLoadChart'));
            charts.pingGauge = echarts.init(document.getElementById('pingGauge'));
            charts.dnsGauge = echarts.init(document.getElementById('dnsGauge'));

            charts.cpuGauge.setOption(ringGaugeOpt(<?= json_encode($cpu_load) ?>, 100, '#2ea043', '%'));
            charts.memGauge.setOption(ringGaugeOpt(<?= json_encode($mem_usage) ?>, 100, '#1f6feb', '%'));
            charts.diskGauge.setOption(ringGaugeOpt(<?= json_encode($disk_usage) ?>, 100, '#e3b341', '%'));
            charts.downloadGauge.setOption(gaugeOption('Download (RX)', <?= json_encode($rx_mbps) ?>));
            charts.uploadGauge.setOption(gaugeOption('Upload (TX)', <?= json_encode($tx_mbps) ?>));
            charts.cpuChart.setOption(lineOpt('CPU %', cpuSeries, '%', '#2ea043', 'value'));

            charts.memChart.setOption({
                xAxis: { type: 'category', data: times, axisLabel: { fontSize: 10 } },
                yAxis: [{ type: 'value', name: 'Usage %', axisLabel: { fontSize: 10 } }, { type: 'value', name: 'Stall ms', axisLabel: { fontSize: 10 } }],
                series: [
                    { name: 'Usage', type: 'line', data: memSeries, smooth: true, lineStyle: { color: '#1f6feb' }, animation: true },
                    { name: 'Stall', type: 'line', yAxisIndex: 1, data: ioSeries, smooth: true, lineStyle: { color: '#e3b341' }, animation: true }
                ]
            });

            charts.bandwidthChart.setOption({
                xAxis: { type: 'category', data: times, axisLabel: { fontSize: 10 } },
                yAxis: { type: 'value', name: 'Mbps', axisLabel: { fontSize: 10 } },
                series: [
                    { name: 'RX', type: 'line', data: rxSeries, smooth: true, lineStyle: { color: '#2ea043' }, animation: true },
                    { name: 'TX', type: 'line', data: txSeries, smooth: true, lineStyle: { color: '#1f6feb' }, animation: true }
                ]
            });

            charts.pressureChart.setOption({
                polar: { radius: ['30%', '80%'] },
                angleAxis: { type: 'category', data: ['CPU', 'Memory', 'Disk'], startAngle: 90 },
                radiusAxis: {},
                series: [{
                    type: 'bar',
                    data: [<?= json_encode($cpu_pressure_some) ?>, <?= json_encode($memory_pressure_some) ?>, <?= json_encode($disk_io_pressure) ?>],
                    coordinateSystem: 'polar',
                    name: 'Pressure',
                    stack: 'a',
                    itemStyle: { color: '#2ea043' },
                    animation: true
                }]
            });

            charts.doughnutChart.setOption({
                series: [{
                    type: 'pie', radius: ['40%', '70%'], data: [
                        { value: <?= json_encode($cpu_load) ?>, name: 'CPU', itemStyle: { color: '#2ea043' } },
                        { value: <?= json_encode($mem_usage) ?>, name: 'Memory', itemStyle: { color: '#1f6feb' } },
                        { value: <?= json_encode($disk_usage) ?>, name: 'Disk', itemStyle: { color: '#e3b341' } }
                    ], label: { show: true, formatter: '{b}: {c}%', fontSize: 10 }, animation: true
                }]
            });

            charts.locationRaceChart.setOption({
                xAxis: { type: 'value', max: 100, axisLabel: { fontSize: 10 } },
                yAxis: { 
                    type: 'category', 
                    data: Object.keys(initialLocationMetrics), 
                    axisLabel: { 
                        fontSize: 10,
                        formatter: function(value) {
                            return value.length > 20 ? value.substring(0, 17) + '...' : value;
                        }
                    } 
                },
                series: [{ 
                    type: 'bar', 
                    data: Object.values(initialLocationMetrics).map(v => v.cpu), 
                    itemStyle: { color: '#2ea043' }, 
                    label: { show: true, position: 'right', formatter: '{c}%', fontSize: 10 }, 
                    animation: true 
                }],
                animationDurationUpdate: 500
            });

            charts.packetLossChart.setOption(lineOpt('Packet Loss', packetLossSeries, '%', '#f85149'));
            charts.cpuTempChart.setOption(lineOpt('CPU Temp', cpuTempSeries, '°C', '#ff8c42'));
            charts.systemLoadChart.setOption(gradientLineOpt('System Load', systemLoadSeries, 'Load', '#2ea043'));
            charts.pingGauge.setOption(pingGaugeOpt(<?= json_encode($ping_ms) ?>));
            charts.dnsGauge.setOption(dnsGaugeOpt(<?= json_encode($dns_ms) ?>));
        });
    }

    const REFRESH_MS = 5000; // Refresh every 5 seconds
    let isFetching = false;
    let retryCount = 0;
    const MAX_RETRIES = 5;
    const BASE_DELAY = 5000;
    let lastSuccessfulData = null;

    function append(arr, val) { 
        arr.push(val); 
        if (arr.length > MAX_POINTS) arr.shift(); 
    }

    function toggleDisconnectedState(isDisconnected) {
        const panels = ['cpuPanel', 'memPanel', 'diskPanel', 'downloadPanel', 'uploadPanel', 
                        'cpuChartPanel', 'memChartPanel', 'bandwidthChartPanel', 'locationRaceChartPanel',
                        'pressureChartPanel', 'doughnutChartPanel', 'packetLossChartPanel', 
                        'cpuTempChartPanel', 'systemLoadChartPanel', 'pingPanel', 'dnsPanel'];
        panels.forEach(id => {
            $(`#${id}`).toggleClass('disconnected', isDisconnected);
        });
    }

    function showErrorToast(message) {
        $('#errorToastBody').text(message);
        const toast = new bootstrap.Toast(document.getElementById('errorToast'));
        toast.show();
    }

    function fetchOnce() {
        if (isFetching) return;
        isFetching = true;

        const lastCpu = cpuSeries[cpuSeries.length - 1] || 0;
        const lastMem = memSeries[memSeries.length - 1] || 0;
        const lastRx = rxSeries[rxSeries.length - 1] || 0;
        const lastTx = txSeries[txSeries.length - 1] || 0;

        $.ajax({
            url: window.location.href,
            data: { partial: 1, ip: '<?= $ip ?>', t: Date.now(), locations: (Date.now() - lastLocFetch > 30000) ? 1 : 0 },
            dataType: 'json',
            cache: false,
            timeout: 15000, // 15-second timeout
            success: function(d) {
                retryCount = 0;
                lastSuccessfulData = d;

                // Update text fields
                $('#cpuNow').text(d.cpu_load + '%');
                $('#memNow').text(d.memory_usage + '%');
                $('#diskNow').text(d.disk_usage + '%');
                $('#cpuPressureText').text(d.cpu_pressure_some + '%');
                $('#cpuLoadText').text(d.system_load_1m);
                $('#memStallText').text(d.memory_stall_ms + ' ms');
                $('#memFullText').text(d.memory_full_pressure);
                $('#diskPressureText').text(d.disk_io_pressure + '%');
                $('#ioStallText').text(d.io_stall_ms + ' ms');
                $('#rxNow').text(d.rx_mbps + ' Mbps');
                $('#txNow').text(d.tx_mbps + ' Mbps');
                $('#totalMem').text(d.total_memory + ' MB');
                $('#freeMem').text(d.free_memory + ' MB');
                $('#usedMem').text((d.total_memory - d.free_memory).toFixed(2) + ' MB');
                $('#totalHdd').text(d.total_hdd + ' MB');
                $('#freeHdd').text(d.free_hdd + ' MB');
                $('#usedHdd').text((d.total_hdd - d.free_hdd).toFixed(2) + ' MB');
                $('#uptime').text(d.formatted_uptime);
                $('#cpuTemp').text(d.cpu_temp);
                $('#voltageText').text(d.voltage);
                $('#activeProc').text(d.active_processes);
                $('#sysLoad').text(d.system_load_1m);
                $('#pingNow').text(d.ping_ms == null ? 'N/A' : d.ping_ms + ' ms');
                $('#dnsNow').text(d.dns_ms == null ? 'N/A' : d.dns_ms + ' ms');

                // Update series
                const t = new Date().toLocaleTimeString();
                append(times, t);
                append(cpuSeries, d.cpu_load);
                append(memSeries, d.memory_usage);
                append(rxSeries, d.rx_mbps);
                append(txSeries, d.tx_mbps);
                append(ioSeries, d.io_stall_ms);
                append(cpuPressureSeries, d.cpu_pressure_some);
                append(memPressureSeries, d.memory_pressure_some);
                append(packetLossSeries, d.packet_loss);
                append(cpuTempSeries, d.cpu_temp_raw);
                append(systemLoadSeries, d.system_load_1m);
                append(pingSeries, d.ping_ms);
                append(dnsSeries, d.dns_ms);
                if (d.location_metrics) {
                    locationSeries = d.location_metrics;
                    lastLocFetch = Date.now();
                    if (charts.locationRaceChart) {
                        charts.locationRaceChart.setOption({
                            yAxis: { data: Object.keys(d.location_metrics) },
                            series: [{ data: Object.values(d.location_metrics).map(v => v.cpu) }]
                        });
                    }
                }

                if (d.connected) {
                    $('#apiStatus').removeClass('badge-down').addClass('badge-up').text('Connected');
                    toggleDisconnectedState(false);

                    charts.cpuGauge.setOption({ series: [{ data: [{ value: d.cpu_load }] }], animationDurationUpdate: 500 });
                    charts.memGauge.setOption({ series: [{ data: [{ value: d.memory_usage }] }], animationDurationUpdate: 500 });
                    charts.diskGauge.setOption({ series: [{ data: [{ value: d.disk_usage }] }], animationDurationUpdate: 500 });

                    const sensibleMax = Math.max(10, Math.ceil(Math.max(d.rx_mbps, d.tx_mbps) * 3));
                    charts.downloadGauge.setOption(gaugeOption('Download (RX)', d.rx_mbps, sensibleMax));
                    charts.uploadGauge.setOption(gaugeOption('Upload (TX)', d.tx_mbps, sensibleMax));

                    charts.cpuChart.setOption({ xAxis: { data: times }, series: [{ data: cpuSeries }] });
                    charts.memChart.setOption({ xAxis: { data: times }, series: [{ data: memSeries }, { data: ioSeries }] });
                    charts.bandwidthChart.setOption({ xAxis: { data: times }, series: [{ data: rxSeries }, { data: txSeries }] });
                    charts.pressureChart.setOption({ series: [{ data: [d.cpu_pressure_some, d.memory_pressure_some, d.disk_io_pressure] }] });

                    charts.doughnutChart.setOption({ 
                        series: [{ 
                            data: [
                                { value: d.cpu_load, name: 'CPU' }, 
                                { value: d.memory_usage, name: 'Memory' }, 
                                { value: d.disk_usage, name: 'Disk' }
                            ] 
                        }] 
                    });

                    if (Object.keys(d.location_metrics).length > 0) {
                        charts.locationRaceChart.setOption({ 
                            yAxis: { 
                                data: Object.keys(d.location_metrics),
                                axisLabel: { 
                                    fontSize: 10,
                                    formatter: function(value) {
                                        return value.length > 20 ? value.substring(0, 17) + '...' : value;
                                    }
                                } 
                            }, 
                            series: [{ 
                                data: Object.values(d.location_metrics).map(v => v.cpu) 
                            }] 
                        });
                    }

                    charts.packetLossChart.setOption({ xAxis: { data: times }, series: [{ data: packetLossSeries }] });
                    charts.cpuTempChart.setOption({ xAxis: { data: times }, series: [{ data: cpuTempSeries }] });
                    charts.systemLoadChart.setOption({ xAxis: { data: times }, series: [{ data: systemLoadSeries }] });
                    charts.pingGauge.setOption(pingGaugeOpt(d.ping_ms));
                    charts.dnsGauge.setOption(dnsGaugeOpt(d.dns_ms));
                } else {
                    $('#apiStatus').removeClass('badge-up').addClass('badge-down').text('Disconnected: ' + (d.error || 'Unknown error'));
                    toggleDisconnectedState(true);
                    showErrorToast(d.error || 'Failed to connect to router');
                    useLastSuccessfulData();
                }
            },
            error: function(xhr, status, error) {
                retryCount++;
                console.error('AJAX error details:', {
                    status: status,
                    error: error,
                    response: xhr.responseText,
                    url: window.location.href + '?partial=1&ip=<?= $ip ?>&t=' + Date.now(),
                    retryCount: retryCount
                });

                let errorMessage = 'Unknown error';
                if (status === 'parsererror') {
                    errorMessage = 'Invalid response from server';
                } else if (status === 'timeout') {
                    errorMessage = 'Request timed out';
                } else {
                    errorMessage = error || 'Connection failed';
                }

                $('#apiStatus').removeClass('badge-up').addClass('badge-down').text('Disconnected: ' + errorMessage);
                toggleDisconnectedState(true);
                showErrorToast(errorMessage);
                useLastSuccessfulData();
            },
            complete: function() {
                isFetching = false;
                const delay = retryCount >= MAX_RETRIES ? BASE_DELAY * Math.pow(2, retryCount) : BASE_DELAY;
                setTimeout(fetchOnce, Math.min(delay, 30000)); // Cap delay at 30 seconds
            }
        });
    }

    function useLastSuccessfulData() {
        if (!lastSuccessfulData) return;
        const d = lastSuccessfulData;
        const t = new Date().toLocaleTimeString();
        append(times, t);
        append(cpuSeries, d.cpu_load);
        append(memSeries, d.memory_usage);
        append(rxSeries, d.rx_mbps);
        append(txSeries, d.tx_mbps);
        append(ioSeries, d.io_stall_ms);
        append(cpuPressureSeries, d.cpu_pressure_some);
        append(memPressureSeries, d.memory_pressure_some);
        append(packetLossSeries, d.packet_loss);
        append(cpuTempSeries, d.cpu_temp_raw);
        append(systemLoadSeries, d.system_load_1m);
        append(pingSeries, d.ping_ms);
        append(dnsSeries, d.dns_ms);

        charts.cpuChart.setOption({ xAxis: { data: times }, series: [{ data: cpuSeries }] });
        charts.memChart.setOption({ xAxis: { data: times }, series: [{ data: memSeries }, { data: ioSeries }] });
        charts.bandwidthChart.setOption({ xAxis: { data: times }, series: [{ data: rxSeries }, { data: txSeries }] });
        charts.packetLossChart.setOption({ xAxis: { data: times }, series: [{ data: packetLossSeries }] });
        charts.cpuTempChart.setOption({ xAxis: { data: times }, series: [{ data: cpuTempSeries }] });
        charts.systemLoadChart.setOption({ xAxis: { data: times }, series: [{ data: systemLoadSeries }] });
        charts.pingGauge.setOption(pingGaugeOpt(d.ping_ms));
        charts.dnsGauge.setOption(dnsGaugeOpt(d.dns_ms));
    }

    $(document).ready(function() {
        let attempts = 0;
        const checkEcharts = () => {
            if (typeof echarts !== 'undefined') {
                initCharts();
                fetchOnce();
            } else if (attempts < 30) {
                attempts++;
                setTimeout(checkEcharts, 300);
            } else {
                console.error('ECharts failed to load after 30 attempts');
                $('#apiStatus').text('Error: ECharts not loaded').addClass('badge-down');
                showErrorToast('Failed to load charting library');
            }
        };
        checkEcharts();

        $('#sidebarToggle').on('click', function() { 
            $('#sidebar').toggleClass('show'); 
        });

        $('#themeToggle').on('click', function() {
            $('body').toggleClass('light-mode dark-mode');
            $('#themeToggle i').toggleClass('fa-moon fa-sun');
            Object.values(charts).forEach(chart => {
                if (chart) chart.setOption(chart.getOption(), { theme: $('body').hasClass('light-mode') ? 'default' : 'dark' });
            });
        });

        window.addEventListener('resize', () => Object.values(charts).forEach(c => { if (c) c.resize(); }));

        function updateTime() {
            document.getElementById("live-time").innerText = "Last Updated: " + new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: 'numeric', second: 'numeric', hour12: true });
        }

        setInterval(updateTime, 100);
        updateTime();
    });
    </script>
</body>
</html>