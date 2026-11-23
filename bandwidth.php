<?php
// File: bandwidth.php
// Bandwidth monitoring: live router traffic + stored history. No file cache.
// History is recorded by collect_bandwidth.php (cron) and by live requests (when no sample exists in the last 45s).
require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bandwidth_lib.php';
require_once __DIR__ . '/parallel_routers.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
$isAjax = isset($_GET['ajax']);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Session expired, please log in again']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'default';
$userGroup = bw_user_group($username);
getCurrentClient(); // keeps the session-based client mapping consistent with the rest of the dashboard
// Release the session lock now. Otherwise every other tab of the same browser waits until this page finishes.
session_write_close();

global $clientConfig;
$targets = bw_targets_for_group($clientConfig, $userGroup);
$pollIntervalSec = 10;

// ================= AJAX (JSON) =================
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $mode = $_GET['mode'] ?? 'live';
    $location = $_GET['location'] ?? null;

    if ($mode !== 'live' && !isset(BW_RANGES[$mode])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown range: ' . $mode]);
        exit;
    }

    $selected = $targets;
    if ($location !== null && $location !== '') {
        $selected = array_values(array_filter($targets, function ($t) use ($location) {
            return $t['name'] === $location;
        }));
        if (!$selected) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not available for this user']);
            exit;
        }
    }

    $out = [];
    try {
        if ($mode === 'live') {
            // All selected targets are polled at the same time
            $jobs = [];
            foreach ($selected as $t) {
                if (($t['type'] ?? 'mikrotik') === 'snmp') {
                    $jobs[$t['name']] = ['task' => 'bw_snmp', 'ip' => $t['ip'], 'iface' => $t['iface']];
                } else {
                    $jobs[$t['name']] = ['task' => 'bw', 'ip' => $t['ip'], 'iface' => $t['iface'], 'user' => $t['user'], 'pass' => $t['pass']];
                }
            }
            $parallel = runRouterJobsParallel($jobs, 8.0);
            foreach ($selected as $t) {
                $key = $t['name'];
                $isSnmp = ($t['type'] ?? 'mikrotik') === 'snmp';
                if ($parallel !== null && isset($parallel[$key]) && is_array($parallel[$key])) {
                    $live = $parallel[$key];
                } elseif ($parallel !== null && isset($parallel[$key]) && $parallel[$key] === false) {
                    $live = $isSnmp
                        ? ['status' => 'unavailable', 'rx_mbps' => null, 'tx_mbps' => null, 'error' => null]
                        : ['status' => 'down', 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'error' => 'Router did not answer in time'];
                } else {
                    $live = $isSnmp
                        ? bw_poll_snmp_live($t['ip'], $t['iface'])
                        : bw_poll_live($t['ip'], $t['iface'], $t['user'], $t['pass']);
                }
                if (!$isSnmp || $live['status'] === 'up') {
                    logBandwidthSampleIfDue($t['name'], $t['iface'], $live['rx_mbps'], $live['tx_mbps'], $live['status'], 45);
                }
                $out[$t['name']] = [
                    'public_ip' => $t['ip'],
                    'interface' => $t['iface'],
                    'status' => $live['status'],
                    'rx_mbps' => $live['rx_mbps'],
                    'tx_mbps' => $live['tx_mbps'],
                    'error' => $live['error'] ?? null,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
        } else {
            foreach ($selected as $t) {
                $out[$t['name']] = [
                    'public_ip' => $t['ip'],
                    'interface' => $t['iface'],
                    'status' => 'historical',
                    'historical' => bw_history($t['name'], $t['iface'], $mode),
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
        }
        echo json_encode([
            'ts' => date('c'),
            'mode' => $mode,
            'locations' => $out,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (Throwable $e) {
        error_log('bandwidth.php ajax error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Bandwidth request failed, please try again']);
    }
    exit;
}

// ================= Page data =================
$tiles = [];
foreach ($targets as $i => $t) {
    $tiles[] = [
        'id' => 'tile' . $i,
        'name' => $t['name'],
        'ip' => $t['ip'],
        'iface' => $t['iface'],
    ];
}
$rangeOptions = ['live' => 'Live', '24h' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days', '6m' => '6 Months', '1y' => '1 Year'];
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Network Dashboard — Bandwidth</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
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
        --primary-rgb: 0, 98, 255;
        --success-rgb: 16, 185, 129;
        --warning-rgb: 245, 158, 11;
        --danger-rgb: 239, 68, 68;
        --card-bg-rgb: 30, 30, 30;
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

    .fullscreen-toggle {
        background: transparent;
        border: none;
        color: var(--text);
        padding: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .fullscreen-toggle:hover {
        background: rgba(var(--primary-rgb), 0.1);
        color: var(--primary);
    }

    .fullscreen-toggle i {
        font-size: 16px;
    }

    .card.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 2000;
        margin: 0;
        border: none;
        border-radius: 0;
        background: var(--bg);
        padding: 20px;
        box-shadow: none;
    }

    .card.fullscreen .chart {
        height: calc(100vh - 100px);
    }

    .card.fullscreen .card-header {
        background: var(--card-bg);
        padding: 10px;
        border-bottom: 1px solid var(--border);
    }

    .card.fullscreen .card-body {
        padding: 20px;
    }

    body:has(.card.fullscreen) .sidebar,
    body:has(.card.fullscreen) .theme-toggle,
    body:has(.card.fullscreen) .sidebar-toggle {
        display: none;
    }

    body {
        background-color: var(--bg);
        color: var(--text);
        min-height: 100vh;
        transition: background-color 0.3s, color 0.3s;
        margin: 0;
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

    a {
        text-decoration: none;
    }

    .sidebar {
        overflow-y: hidden;
        overflow-x: hidden;
        background: var(--card-bg);
        border-right: 1px solid var(--border);
        width: 250px;
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        overflow: auto;
        padding: 14px;
    }

    .logo-container {
        width: 100%;
        height: 80px;
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        margin-bottom: 6px;
    }

    .sidebar .nav-link {
        color: var(--text);
        padding: 10px 12px;
        border-radius: 6px;
        margin: 6px 4px;
        display: flex;
        gap: 8px;
        align-items: center;
        text-decoration: none;
    }

    .sidebar .nav-link:hover {
        background: rgba(var(--primary-rgb), 0.06);
        color: var(--primary);
    }

    .sidebar .nav-link.active {
        background: var(--primary);
        color: white;
    }

    .main-content {
        margin-left: 300px;
        padding: 18px;
        transition: margin .2s;
    }

    @media (max-width: 992px) {
        .sidebar {
            left: -250px;
            transition: left .25s;
        }

        .sidebar.show {
            left: 0;
        }

        .main-content {
            margin-left: 0;
        }

        .sidebar-toggle {
            display: block;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1100;
        }
    }

    .dashboard-header {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 18px;
        margin-bottom: 18px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .header-left h1 {
        margin: 0;
        font-size: 20px;
    }

    .header-left p {
        margin: 0;
        color: var(--text-muted);
        font-size: 13px;
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
        gap: 16px;
    }

    .card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 12px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
    }

    .card .meta {
        color: var(--text-muted);
        font-size: 13px;
        margin-bottom: 8px;
    }

    .chart {
        width: 100%;
        height: 300px;
    }

    .controls {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .btn {
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: transparent;
        color: var(--text);
        cursor: pointer;
    }

    .badge {
        padding: 6px 8px;
        border-radius: 999px;
        font-size: 12px;
        background: rgba(var(--primary-rgb), 0.08);
        color: var(--primary);
    }

    .theme-toggle {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--card-bg);
        border: 1px solid var(--border);
        cursor: pointer;
        z-index: 1200;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
    }

    .select-container {
        position: relative;
        display: inline-block;
        min-width: 120px;
    }

    .select-container select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-color: var(--card-bg);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 8px 32px 8px 12px;
        font-size: 14px;
        cursor: pointer;
        outline: none;
        width: 100%;
    }

    .select-container::after {
        content: "▼";
        font-size: 12px;
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: var(--text-muted);
    }

    @media (max-width: 640px) {
        .chart {
            height: 220px;
        }

        .card {
            padding: 10px;
        }
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .card-title {
        margin: 0;
        font-size: 16px;
    }

    .location-dropdown {
        min-width: 100px;
        margin-left: 10px;
    }
    </style>

    <style>
    .range-select { min-width: 110px; }
    .tile-meta-line { font-size: 12px; color: var(--text-muted); }
    .tile-coverage { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
    .status-pill { font-size: 12px; padding: 2px 8px; border-radius: 999px; }
    .status-pill.up { background: rgba(16,185,129,.15); color: var(--success); }
    .status-pill.down { background: rgba(239,68,68,.15); color: var(--danger); }
    .status-pill.warn { background: rgba(245,158,11,.15); color: var(--warning); }
    .status-pill.hist { background: rgba(96,165,250,.15); color: var(--highlight); }
    </style>
</head>

<body class="dark-mode">
    <button class="sidebar-toggle btn" id="sidebarToggle"
        style="display:none; position:fixed; top:10px; left:10px; z-index:1200; border-radius:50%; width:44px; height:44px;">
        <i class="fas fa-bars" style="font-size:18px;"></i>
    </button>
    <div class="theme-toggle" id="themeToggle" title="Toggle theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>
    <div class="d-flex">
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 text text-decoration-none">
                <div class="logo-container"></div>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Overview
                    </a>
                </li>
                <li>
                    <a href="locations.php" class="nav-link">
                        <i class="fas fa-network-wired"></i> All Locations
                    </a>
                </li>
                <li>
                    <a href="heatmap.php" class="nav-link">
                        <i class="fas fa-th"></i> Heatmap
                    </a>
                </li>
                <li>
                    <a href="bandwidth.php" class="nav-link active">
                        <i class="fas fa-chart-line"></i> Bandwidth
                    </a>
                </li>
                <li>
                    <a href="locations_Analytics.php" class="nav-link">
                        <i class="fas fa-wifi"></i> Locations Analytics
                    </a>
                </li>
                <li>
                    <a href="location_information.php" class="nav-link">
                        <i class="fas fa-map-marker-alt"></i> Location Information
                    </a>
                </li>
                <?php if ($username === 'Knoativ'): ?>
                <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container text-center mt-auto"></div>
        </div>
        <div class="main-content flex-grow-1">
            <div class="dashboard-header">
                <div class="header-left">
                    <h1>Bandwidth Overview</h1>
                    <p>User: <?php echo htmlspecialchars($username); ?> | Locations: <?php echo count($tiles); ?></p>
                </div>
                <div class="header-right" style="display:flex; gap:10px; align-items:center;">
                    <button id="refreshNow" class="btn"><i class="fas fa-sync"></i> Refresh Now</button>
                    <div id="lastTs" style="color:var(--text-muted); font-size:13px;"></div>
                    <a href="logout.php" class="btn" style="background:var(--danger); color:white;"><i
                            class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
            <div class="container-fluid">
                <div class="row">
                    <?php foreach ($tiles as $tile): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <strong class="meta"><?php echo htmlspecialchars($tile['name']); ?></strong>
                                    <span id="<?php echo $tile['id']; ?>_status" class="status-pill ms-2">—</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button class="btn btn-sm download-chart" data-tile="<?php echo $tile['id']; ?>"
                                        title="Download Chart"><i class="fas fa-download"></i></button>
                                    <button class="btn btn-sm fullscreen-toggle" data-tile="<?php echo $tile['id']; ?>"
                                        title="Toggle Fullscreen"><i class="fas fa-expand"></i></button>
                                    <div class="select-container range-select">
                                        <select id="<?php echo $tile['id']; ?>_range"
                                            class="form-select form-select-sm time-range-dropdown"
                                            data-tile="<?php echo $tile['id']; ?>">
                                            <?php foreach ($rangeOptions as $val => $label): ?>
                                            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="tile-meta-line mb-2">
                                    Interface: <?php echo htmlspecialchars($tile['iface']); ?> ·
                                    IP: <?php echo htmlspecialchars($tile['ip']); ?>
                                </div>
                                <div id="<?php echo $tile['id']; ?>_chart" class="chart" style="height: 220px;"></div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span id="<?php echo $tile['id']; ?>_speed" class="meta small">RX: — | TX: —</span>
                                </div>
                                <div id="<?php echo $tile['id']; ?>_cov" class="tile-coverage"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    const TILES = <?php echo json_encode($tiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const LIVE_INTERVAL_MS = <?php echo (int)$pollIntervalSec; ?> * 1000;
    const HISTORY_REFRESH_MS = 60 * 1000;
    const MAX_LIVE_POINTS = 120;
    const RANGE_LABELS = <?php echo json_encode($rangeOptions, JSON_UNESCAPED_UNICODE); ?>;

    const tileState = {};

    function fmtMbps(v) {
        return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(2);
    }

    function setStatus(tile, text, cls) {
        const el = document.getElementById(tile.id + '_status');
        if (!el) return;
        el.textContent = text;
        el.className = 'status-pill ms-2 ' + (cls || '');
    }

    function setSpeed(tile, text) {
        const el = document.getElementById(tile.id + '_speed');
        if (el) el.textContent = text;
    }

    function setCoverage(tile, text) {
        const el = document.getElementById(tile.id + '_cov');
        if (el) el.textContent = text;
    }

    function fetchJson(url, timeoutMs) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeoutMs);
        return fetch(url, { cache: 'no-store', credentials: 'same-origin', signal: ctrl.signal, headers: { 'Accept': 'application/json' } })
            .then(async res => {
                let payload = null;
                try { payload = await res.json(); } catch (e) { /* non-JSON */ }
                if (!res.ok) {
                    const msg = (payload && payload.error) ? payload.error : ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                return payload;
            })
            .finally(() => clearTimeout(timer));
    }

    function chartOption() {
        return {
            tooltip: {
                trigger: 'axis',
                formatter: function (params) {
                    if (!params || !params.length) return '';
                    const label = params[0].axisValue;
                    const rx = params[0] ? params[0].data : null;
                    const tx = params[1] ? params[1].data : null;
                    return `${label}<br/>RX: ${fmtMbps(rx)} Mbps<br/>TX: ${fmtMbps(tx)} Mbps`;
                }
            },
            animation: false,
            legend: { show: false },
            xAxis: {
                type: 'category',
                boundaryGap: false,
                data: [],
                axisLabel: { hideOverlap: true, color: '#9ca3af' },
                axisLine: { lineStyle: { color: '#ccc' } }
            },
            yAxis: {
                type: 'value',
                name: 'Mbps',
                axisLabel: { color: '#9ca3af' },
                splitLine: { lineStyle: { type: 'dashed', color: '#444' } }
            },
            dataZoom: [{ type: 'inside', throttle: 50 }],
            grid: { left: 52, right: 16, bottom: 40, top: 24 },
            series: [
                {
                    name: 'RX (Mbps)', type: 'line', smooth: true, showSymbol: false, connectNulls: false,
                    areaStyle: { color: 'rgba(34,197,94,0.25)' },
                    lineStyle: { color: 'rgb(16,185,129)', width: 2 }, data: []
                },
                {
                    name: 'TX (Mbps)', type: 'line', smooth: true, showSymbol: false, connectNulls: false,
                    areaStyle: { color: 'rgba(59,130,246,0.25)' },
                    lineStyle: { color: 'rgb(37,99,235)', width: 2 }, data: []
                }
            ]
        };
    }

    function initTiles() {
        TILES.forEach(tile => {
            const dom = document.getElementById(tile.id + '_chart');
            const chart = echarts.init(dom);
            chart.setOption(chartOption());
            tileState[tile.id] = {
                chart: chart,
                mode: 'live',
                inFlight: false,
                live: { ts: [], rx: [], tx: [] },
            };
            const select = document.getElementById(tile.id + '_range');
            select.addEventListener('change', () => {
                const st = tileState[tile.id];
                st.mode = select.value;
                if (st.mode === 'live') {
                    renderLive(tile);
                    setCoverage(tile, '');
                    loadTile(tile);
                } else {
                    setStatus(tile, 'Loading…', 'hist');
                    setSpeed(tile, 'Loading ' + RANGE_LABELS[st.mode] + ' history…');
                    loadTile(tile);
                }
            });
        });
    }

    function renderLive(tile) {
        const st = tileState[tile.id];
        st.chart.setOption({
            xAxis: { data: st.live.ts },
            series: [{ name: 'RX (Mbps)', data: st.live.rx }, { name: 'TX (Mbps)', data: st.live.tx }]
        });
        const n = st.live.ts.length;
        if (n) {
            const rx = st.live.rx[n - 1], tx = st.live.tx[n - 1];
            setSpeed(tile, `RX: ${fmtMbps(rx)} Mbps | TX: ${fmtMbps(tx)} Mbps`);
        }
    }

    function pushLive(tile, label, rx, tx) {
        const st = tileState[tile.id];
        st.live.ts.push(label);
        st.live.rx.push(rx);
        st.live.tx.push(tx);
        if (st.live.ts.length > MAX_LIVE_POINTS) {
            st.live.ts.shift(); st.live.rx.shift(); st.live.tx.shift();
        }
        if (st.mode === 'live') renderLive(tile);
    }

    function renderHistory(tile, hist) {
        const st = tileState[tile.id];
        const d = hist.data;
        st.chart.setOption({
            xAxis: { data: d.ts },
            series: [{ name: 'RX (Mbps)', data: d.rx }, { name: 'TX (Mbps)', data: d.tx }]
        });
        const s = hist.summary;
        setSpeed(tile, `Avg RX: ${fmtMbps(s.avg_rx)} | Avg TX: ${fmtMbps(s.avg_tx)} Mbps · Peak RX: ${fmtMbps(s.peak_rx)} | Peak TX: ${fmtMbps(s.peak_tx)} Mbps`);

        const cov = hist.coverage || {};
        const covText = cov.first
            ? `Stored data: ${cov.first} → ${cov.last}`
            : 'No stored data for this location yet';
        const rangeText = s.points_with_data
            ? `${s.points_with_data} of ${s.points_total} ${hist.bucket_seconds >= 3600 ? 'intervals' : 'buckets'} have data (${s.samples} samples)`
            : `No samples in the last ${RANGE_LABELS[hist.range]}`;
        setCoverage(tile, `${rangeText} · ${covText}`);
        setStatus(tile, 'History · ' + RANGE_LABELS[hist.range], s.points_with_data ? 'hist' : 'warn');
    }

    async function loadTile(tile) {
        const st = tileState[tile.id];
        if (!st || st.inFlight) return;          // never stack requests for the same tile
        st.inFlight = true;
        const mode = st.mode;
        try {
            const url = 'bandwidth.php?ajax=1&mode=' + encodeURIComponent(mode) +
                        '&location=' + encodeURIComponent(tile.name) + '&_=' + Date.now();
            const payload = await fetchJson(url, 30000);
            if (st.mode !== mode) return;        // user switched range while this request was running
            const val = (payload.locations || {})[tile.name];
            if (!val) {
                setStatus(tile, 'No data', 'warn');
                return;
            }
            if (mode === 'live') {
                if (val.status === 'up') {
                    setStatus(tile, 'Online', 'up');
                } else if (val.status === 'unavailable') {
                    setStatus(tile, 'N/A', 'warn');
                } else if (val.status === 'no_data') {
                    setStatus(tile, 'No data', 'warn');
                } else {
                    setStatus(tile, 'Offline', 'down');
                }
                const el = document.getElementById(tile.id + '_status');
                if (el) el.title = val.error || '';
                if (val.status === 'up') {
                    pushLive(tile, new Date().toLocaleTimeString(), Number(val.rx_mbps) || 0, Number(val.tx_mbps) || 0);
                } else if (val.status === 'unavailable') {
                    setSpeed(tile, 'RX: N/A | TX: N/A');
                } else {
                    setSpeed(tile, val.error || 'Router not reachable');
                }
            } else {
                renderHistory(tile, val.historical);
            }
            document.getElementById('lastTs').textContent = 'Updated: ' + new Date().toLocaleTimeString();
        } catch (err) {
            if (st.mode === mode) {
                setStatus(tile, 'Error', 'down');
                setSpeed(tile, 'Failed: ' + err.message);
            }
        } finally {
            st.inFlight = false;
        }
    }

    function liveTick() {
        TILES.forEach(tile => {
            if (tileState[tile.id].mode === 'live') loadTile(tile);
        });
    }

    function historyTick() {
        TILES.forEach(tile => {
            if (tileState[tile.id].mode !== 'live') loadTile(tile);
        });
    }

    function startUpdates() {
        liveTick();
        setInterval(liveTick, LIVE_INTERVAL_MS);
        setInterval(historyTick, HISTORY_REFRESH_MS);
    }

    document.getElementById('refreshNow').addEventListener('click', () => {
        TILES.forEach(tile => loadTile(tile));
    });

    // ---------- Layout, theme, fullscreen, download ----------
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    function showSidebarToggleIfNeeded() {
        if (window.innerWidth <= 992) {
            sidebarToggle.style.display = 'inline-flex';
        } else {
            sidebarToggle.style.display = 'none';
            sidebar.classList.remove('show');
        }
    }
    showSidebarToggleIfNeeded();
    window.addEventListener('resize', showSidebarToggleIfNeeded);
    sidebarToggle && sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('show'));

    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const savedTheme = localStorage.getItem('nd_theme') || 'dark';
    if (savedTheme === 'light') {
        document.body.classList.add('light-mode');
        themeIcon.className = 'fas fa-sun';
    } else {
        document.body.classList.remove('light-mode');
        themeIcon.className = 'fas fa-moon';
    }
    themeToggle.addEventListener('click', () => {
        const isLightMode = document.body.classList.contains('light-mode');
        document.body.classList.toggle('light-mode', !isLightMode);
        themeIcon.className = isLightMode ? 'fas fa-moon' : 'fas fa-sun';
        localStorage.setItem('nd_theme', isLightMode ? 'dark' : 'light');
    });

    function tileById(id) {
        return TILES.find(t => t.id === id);
    }

    document.querySelectorAll('.fullscreen-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const tile = tileById(button.getAttribute('data-tile'));
            const card = document.getElementById(tile.id + '_chart').closest('.card');
            const isFullscreen = card.classList.toggle('fullscreen');
            button.querySelector('i').className = isFullscreen ? 'fas fa-compress' : 'fas fa-expand';
            setTimeout(() => tileState[tile.id].chart.resize(), 200);
        });
    });

    window.addEventListener('resize', () => {
        Object.values(tileState).forEach(s => s.chart.resize());
    });

    document.querySelectorAll('.download-chart').forEach(button => {
        button.addEventListener('click', () => {
            const tile = tileById(button.getAttribute('data-tile'));
            const chart = tileState[tile.id].chart;
            const icon = button.querySelector('i');
            const originalIcon = icon.className;
            try {
                const bg = document.body.classList.contains('light-mode') ? '#ffffff' : '#1e1e1e';
                const dataUrl = chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: bg, excludeComponents: ['toolbox'] });
                const link = document.createElement('a');
                link.href = dataUrl;
                link.download = tile.name.replace(/[^a-z0-9]+/gi, '_') + '_' + Date.now() + '.png';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                icon.className = 'fas fa-check';
            } catch (e) {
                console.error('Download failed:', e);
                icon.className = 'fas fa-exclamation-triangle';
            }
            setTimeout(() => icon.className = originalIcon, 1500);
        });
    });

    // ---------- Start ----------
    initTiles();
    startUpdates();
    </script>
</body>

</html>
