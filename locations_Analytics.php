<?php
require_once __DIR__ . '/analytics_poll.php';
require_once __DIR__ . '/parallel_routers.php';

// locations_Analytics.php - FINAL VERSION
// تمام لوکیشنز کا پنگ ہمیشہ 115.42.66.56 سے ہوگا (دونوں کلائنٹس کے لیے)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require_once __DIR__ . '/routeros_api.class.php';

$clientConfig = getCurrentClient();
$mt_user = $clientConfig['MT_USER'];
$mt_pass = $clientConfig['MT_PASS'];

$locationMap = $clientConfig['locationMap'];
$interfaceMap = $clientConfig['interfaceMap'];

$username = $_SESSION['username'] ?? 'ziauddin';
$isKnoativ = ($username === 'Knoativ');

// اہم: پنگ ہمیشہ central router 115.42.66.56 سے کرو
$main_router_ip = '115.42.66.56';  // فکسڈ IP — اب Knoativ ہو یا Ziauddin، یہی استعمال ہوگا

// No cache: every request reads the routers live.

// AJAX endpoint
if (isset($_GET['fetch_metrics'])) {
    set_time_limit(120);   // config.php sets 30 s; the sequential fallback can need more
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    session_write_close(); // do not hold the session lock while talking to the routers
    $metrics = fetchLocationMetrics(array_keys($locationMap), $mt_user, $mt_pass, $interfaceMap, $main_router_ip);
    echo json_encode(['location_metrics' => $metrics, 'mode' => $GLOBALS['metrics_mode'] ?? 'unknown']);
    exit;
}

function fetchLocationMetrics($ips, $mt_user, $mt_pass, $interfaceMap, $main_router_ip) {
    $jobs = [];
    foreach ($ips as $ip) {
        $jobs[$ip] = [
            'task' => 'analytics',
            'ip' => $ip,
            'user' => $mt_user,
            'pass' => $mt_pass,
            'iface' => $interfaceMap[$ip] ?? 'ether1',
            'location' => $GLOBALS['locationMap'][$ip] ?? $ip,
            'central' => $main_router_ip,
        ];
    }
    $parallel = runRouterJobsParallel($jobs, 20.0);   // null when child processes are not available
    $GLOBALS['metrics_mode'] = $parallel === null ? 'sequential' : 'parallel';
    $budgetEnd = microtime(true) + 45.0;               // sequential fallback: stop starting new routers after this

    $metrics = [];
    foreach ($jobs as $ip => $job) {
        if ($parallel !== null && isset($parallel[$ip]) && is_array($parallel[$ip])) {
            $metrics[$ip] = $parallel[$ip];
        } elseif ($parallel !== null && isset($parallel[$ip]) && $parallel[$ip] === false) {
            // timed out: show as unavailable, never as an invented value
            $metrics[$ip] = [
                'ip' => $ip, 'location' => $job['location'],
                'cpu' => 0, 'memory' => 0, 'rx' => 0, 'tx' => 0,
                'total_memory' => 0, 'free_memory' => 0, 'total_hdd' => 0, 'free_hdd' => 0,
                'packet_loss' => 0, 'ping_status' => false, 'ping_ms' => 'N/A',
                'uptime' => '0s', 'cpu_temp' => 'N/A', 'error' => 'timeout',
            ];
        } elseif (microtime(true) > $budgetEnd) {
            $metrics[$ip] = [
                'ip' => $ip, 'location' => $job['location'],
                'cpu' => 0, 'memory' => 0, 'rx' => 0, 'tx' => 0,
                'total_memory' => 0, 'free_memory' => 0, 'total_hdd' => 0, 'free_hdd' => 0,
                'packet_loss' => 0, 'ping_status' => false, 'ping_ms' => 'N/A',
                'uptime' => '0s', 'cpu_temp' => 'N/A', 'error' => 'not_polled_time_budget',
            ];
        } else {
            $metrics[$ip] = analyticsForRouter($ip, $mt_user, $mt_pass, $job['iface'], $job['location'], $main_router_ip);
        }
    }
    return $metrics;
}
?>

 
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locations Analytics Dashboard</title>
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
    /* Same styles as in the original code */
    :root {
        --dark-bg: #121212;
        --dark-card-bg: #1e1e1e;
        --dark-text: #f8f9fa;
        --dark-text-muted: #adb5bd;
        --dark-border: #333;
        --dark-logo: url('/noclogo1.png');
        --dark-spotcomm-logo: url('/logo1.png');
        --dark-primary: #0062ff;
        --dark-success: #10b981;
        --dark-warning: #f59e0b;
        --dark-danger: #ef4444;
        --dark-tooltip-bg: #2c2c2c;
        --light-bg: #f8f9fa;
        --light-card-bg: #ffffff;
        --light-text: #212529;
        --light-text-muted: #6c757d;
        --light-border: #dee2e6;
        --light-logo: url('/nclogo.png');
        --light-spotcomm-logo: url('/logo.png');
        --light-primary: #0d6efd;
        --light-success: #198754;
        --light-warning: #ffc107;
        --light-danger: #dc3545;
        --light-tooltip-bg: #ffffff;
        --bg: var(--dark-bg);
        --card-bg: var(--dark-card-bg);
        --text: var(--dark-text);
        --text-muted: var(--dark-text-muted);
        --border: var(--dark-border);
        --primary: var(--dark-primary);
        --logo: var(--dark-logo);
        --spotcomm-logo: var(--dark-spotcomm-logo);
        --success: var(--dark-success);
        --warning: var(--dark-warning);
        --danger: var(--dark-danger);
        --tooltip-bg: var(--dark-tooltip-bg);
        --primary-rgb: 0, 98, 255;
        --success-rgb: 16, 185, 129;
        --warning-rgb: 245, 158, 11;
        --danger-rgb: 239, 68, 68;
        --card-bg-rgb: 30, 30, 30;
    }

    .bg-netdata-tooltip {
        background-color: var(--tooltip-bg);
    }

    .border-netdata-dark {
        border-color: var(--border) !important;
    }

    .text-netdata-blue {
        color: var(--primary);
    }

    .text-netdata-purple {
        color: var(--secondary);
    }

    .text-netdata-orange {
        color: var(--warning);
    }

    .text-netdata-red {
        color: var(--danger);
    }

    .text-netdata-green {
        color: var(--success);
    }

    body.light-mode {
        --bg: var(--light-bg);
        --card-bg: var(--light-card-bg);
        --text: var(--light-text);
        --text-muted: var(--light-text-muted);
        --border: var(--light-border);
        --primary: var(--light-primary);
        --logo: var(--light-logo);
        --spotcomm-logo: var(--light-spotcomm-logo);
        --success: var(--light-success);
        --warning: var(--light-warning);
        --danger: var(--light-danger);
        --tooltip-bg: var(--light-tooltip-bg);
        --primary-rgb: 13, 110, 253;
        --success-rgb: 25, 135, 84;
        --warning-rgb: 255, 193, 7;
        --danger-rgb: 220, 53, 69;
        --card-bg-rgb: 255, 255, 255;
    }

    body {
        background-color: var(--bg);
        color: var(--text);
        min-height: 100vh;
        margin: 0;
        transition: background-color 0.3s, color 0.3s;
    }

    body {
        background: var(--bg);
        color: var(--text);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
    }

    .sidebar {
        background: var(--card-bg);
        border-right: 1px solid var(--border);
        width: 250px;
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
    }

    .main-content {
        margin-left: 250px;
        padding: 20px;
    }

    .logo-container {
        width: 100%;
        height: 80px;
        background-image: var(--logo);
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
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
        background-color: var(--card-bg);
        color: var(--text);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: 1px solid var(--border);
        z-index: 100;
        transition: all 0.3s ease;
    }

    .theme-toggle:hover {
        transform: scale(1.1);
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
        transition: all 0.3s ease;
    }

    .main-content {
        margin-left: 250px;
        width: calc(100% - 250px);
        padding: 15px;
        transition: margin-left 0.3s ease, width 0.3s ease;
    }

    @media (max-width: 992px) {
        .sidebar {
            left: -250px;
        }

        .sidebar.show {
            left: 0;
        }

        .sidebar-toggle {
            display: flex;
        }

        .main-content {
            margin-left: 0;
            width: 100%;
        }
    }

    .nav-link {
        color: var(--text-muted);
        padding: 10px 15px;
        border-radius: 4px;
    }

    .nav-link:hover,
    .nav-link.active {
        background: var(--primary);
        color: white;
    }

    .dashboard-header {
        margin-bottom: 2rem;
    }

    .location-card {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.5rem;
        position: relative;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .location-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
    }

    .status-indicator {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }

    .status-indicator.up {
        background: var(--success);
    }

    .status-indicator.down {
        background: var(--danger);
    }

    .ip-address {
        color: var(--text-muted);
        font-size: 0.9rem;
        margin-bottom: 1rem;
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

    .metric-box {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid var(--border);
    }

    .metric-label {
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .metric-value {
        font-weight: 600;
        font-size: 1rem;
    }

    .metric-value.up {
        color: var(--success);
    }

    .metric-value.down {
        color: var(--danger);
    }

    .metric-value.warning {
        color: var(--warning);
    }

    .gauge-container {
        height: 120px;
        width: 100%;
    }

    .last-updated {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-top: 1rem;
        text-align: right;
    }

    .location-title {
        display: flex;
        align-items: center;
        font-size: 1.5rem;
        font-weight: 600;
    }

    .location-icon {
        width: 24px;
        height: 24px;
        margin-right: 10px;
    }

    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .loading-spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid var(--primary);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    @media (max-width: 992px) {
        .sidebar {
            position: fixed;
            left: -250px;
            transition: left 0.3s;
        }

        .sidebar.show {
            left: 0;
        }

        .main-content {
            margin-left: 0;
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
        }
    }
    </style>
</head>

<body>
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 text-decoration-none">
                <div class="logo-container"></div>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="index.php" class="nav-link "><i class="fas fa-tachometer-alt"></i>
                        Overview</a></li>
                <li><a href="locations.php" class="nav-link"><i class="fas fa-network-wired"></i> All Locations</a></li>
                <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
                <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
                <li><a href="locations_Analytics.php" class="nav-link active"><i class="fas fa-wifi"></i> Locations
                        Analytics</a></li>
                <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location
                        Information</a></li>
                <?php if ($isKnoativ): ?>
                <li><a href="queues.php" class="nav-link"><i class="fas fa-list"></i> Queues</a></li>
                <?php endif; ?>
            </ul>
            <div class="spotcomm-logo-container mt-auto"></div>
        </div>
        <!-- Main content -->
        <div class="main-content flex-grow-1">
            <div class="container">
                <div class="dashboard-header">
                    <h2>Locations Analytics Dashboard</h2>
                    <span id="live-time" class="fw-bold"></span>
                </div>
                <div class="row" id="locationsContainer"></div>
            </div>
        </div>
    </div>
    <script>
    // Location map for client-side rendering
    const locationMap = <?php echo json_encode($locationMap); ?>;
    // Initialize page with loading state
    function renderLocations(metrics) {
        const container = $('#locationsContainer');
        const fragment = document.createDocumentFragment();
        const tempDiv = document.createElement('div');
        Object.keys(metrics).forEach(ip => {
            const metric = metrics[ip];
            const card = `
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="location-card">
                            <div class="status-indicator ${metric.ping_status ? 'up' : 'down'}"></div>
                            <h3 class="location-title">
                                ${metric.location}
                            </h3>
                            <div class="ip-address">IP: ${metric.ip}</div>
                            <div class="metric-box">
            <span class="metric-label">Ping</span>
            <span class="metric-value ${metric.ping_status ? 'up' : 'down'}">
                ${metric.ping_ms === 'N/A' ? 'N/A' : metric.ping_ms + ' ms'}
            </span>
        </div>
                            <div class="metric-box">
                                <span class="metric-label">Bandwidth (RX/TX)</span>
                                <span class="metric-value ${metric.rx > 0 || metric.tx > 0 ? 'up' : 'down'}">
                                    ${metric.rx.toFixed(3)} / ${metric.tx.toFixed(3)} Mbps
                                </span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-label">CPU Usage</span>
                                <span class="metric-value ${metric.cpu > 80 ? 'warning' : 'up'}">
                                    ${metric.cpu.toFixed(2)}%
                                </span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-label">Memory Usage</span>
                                <span class="metric-value ${metric.memory > 80 ? 'warning' : 'up'}">
                                    ${metric.memory.toFixed(2)}% (${metric.free_memory.toFixed(2)} MB free)
                                </span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-label">Disk Usage</span>
                                <span class="metric-value ${(metric.total_hdd - metric.free_hdd) / Math.max(1, metric.total_hdd) * 100 > 80 ? 'warning' : 'up'}">
                                    ${((metric.total_hdd - metric.free_hdd) / Math.max(1, metric.total_hdd) * 100).toFixed(2)}% (${metric.free_hdd.toFixed(2)} MB free)
                                </span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-label">Packet Loss</span>
                                <span class="metric-value ${metric.packet_loss > 5 ? 'warning' : 'up'}">
                                    ${metric.packet_loss.toFixed(2)}%
                                </span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-label">CPU Temperature</span>
                                <span class="metric-value ${metric.cpu_temp === 'N/A' ? '' : (parseFloat(metric.cpu_temp) > 60 ? 'warning' : 'up')}">
                                    ${metric.cpu_temp}
                                </span>
                            </div>
                          
                            <div class="metric-box">
                                <span class="metric-label">Uptime</span>
                                <span class="metric-value">${metric.uptime}</span>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="gauge-container" id="cpu-gauge-${ip.replace(/\./g, '_')}"></div>
                                </div>
                                <div class="col-6">
                                    <div class="gauge-container" id="memory-gauge-${ip.replace(/\./g, '_')}"></div>
                                </div>
                            </div>
                            <div class="last-updated">
                                Last Updated: <span class="update-time">${new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: 'numeric', second: 'numeric', hour12: true })}</span>
                            </div>
                        </div>
                    </div>`;
            tempDiv.innerHTML = card;
            fragment.appendChild(tempDiv.firstElementChild);
        });
        // Dispose the previous gauges before their DOM is replaced; otherwise each poll leaks charts
        container.find('.gauge-container').each(function () {
            const old = echarts.getInstanceByDom(this);
            if (old) old.dispose();
        });
        container.empty().append(fragment);
        Object.keys(metrics).forEach(ip => {
            const metric = metrics[ip];
            const ipId = ip.replace(/\./g, '_');
            const cpuEl = document.getElementById(`cpu-gauge-${ipId}`);
            if (!cpuEl) return;
            const cpuGauge = echarts.init(cpuEl, 'dark');
            cpuGauge.setOption({
                series: [{
                    type: 'gauge',
                    min: 0,
                    max: 100,
                    progress: {
                        show: true,
                        width: 10
                    },
                    axisLine: {
                        lineStyle: {
                            width: 10,
                            color: [
                                [0.8, '#10b981'],
                                [0.9, '#f59e0b'],
                                [1, '#ef4444']
                            ]
                        }
                    },
                    axisTick: {
                        show: false
                    },
                    splitLine: {
                        show: false
                    },
                    axisLabel: {
                        show: false
                    },
                    pointer: {
                        show: false
                    },
                    detail: {
                        valueAnimation: true,
                        formatter: '{value}%',
                        fontSize: 14,
                        offsetCenter: [0, '10%']
                    },
                    data: [{
                        value: metric.cpu
                    }]
                }]
            });
            const memEl = document.getElementById(`memory-gauge-${ipId}`);
            if (!memEl) return;
            const memoryGauge = echarts.init(memEl, 'dark');
            memoryGauge.setOption({
                series: [{
                    type: 'gauge',
                    min: 0,
                    max: 100,
                    progress: {
                        show: true,
                        width: 10
                    },
                    axisLine: {
                        lineStyle: {
                            width: 10,
                            color: [
                                [0.8, '#10b981'],
                                [0.9, '#f59e0b'],
                                [1, '#ef4444']
                            ]
                        }
                    },
                    axisTick: {
                        show: false
                    },
                    splitLine: {
                        show: false
                    },
                    axisLabel: {
                        show: false
                    },
                    pointer: {
                        show: false
                    },
                    detail: {
                        valueAnimation: true,
                        formatter: '{value}%',
                        fontSize: 14,
                        offsetCenter: [0, '10%']
                    },
                    data: [{
                        value: metric.memory
                    }]
                }]
            });
        });
        $('#loadingOverlay').hide();
    }

    let metricsBusy = false;  // never start a new request while one is still running
    function fetchData() {
        if (metricsBusy) return;
        metricsBusy = true;
        $.ajax({
            url: '?fetch_metrics=1',
            dataType: 'json',
            cache: false,
            timeout: 90000,
            complete: function() { metricsBusy = false; },
            success: function(data) {
                renderLocations(data.location_metrics);
                $('#live-time').text('Updated: ' + new Date().toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: 'numeric',
                    second: 'numeric',
                    hour12: true
                }));
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
                $('#loadingOverlay').hide();
                $('#locationsContainer').html(
                    '<div class="alert alert-danger">Failed to load location metrics. Please try again later.</div>'
                );
            }
        });
    }
    // Initial fetch
    fetchData();
    setInterval(fetchData, 10000);
    // Resize gauges that exist on the page
    window.addEventListener('resize', function() {
        document.querySelectorAll('.gauge-container').forEach(function (el) {
            const chart = echarts.getInstanceByDom(el);
            if (chart) chart.resize();
        });
    });
    // Live time update
    function updateTime() {
        const now = new Date();
        const options = {
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: true
        };
        document.getElementById("live-time").innerText =
            "Updated: " + now.toLocaleTimeString('en-US', options);
    }
    setInterval(updateTime, 1000);
    updateTime();
    // Sidebar toggle
    document.getElementById('sidebarToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
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