<?php
require('routeros_api.class.php');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Location map with all locations
$locationMap = [
    '103.246.108.50' => 'Ziauddin Hospital Korangi Creek',
    '103.246.109.74' => 'ZCR Boat Basin (Clifton)',
    '103.246.109.82' => 'Ziauddin Education Board (ZEB)',
    '103.246.109.98' => 'Nursing - North',
    '103.246.109.106' => 'ZCRS / MT / FM (North)',
    '103.246.109.114' => 'College of Intermediate',
    // '103.246.109.122' => 'KDLB-Kemari Hospital',
    // '103.246.109.130' => 'College of SLT',
    // '103.246.109.138' => 'MD - Home',
    '103.246.109.146' => 'SLT Clinic',
    // '103.246.109.154' => 'Ziauddin Memorial Hospital',
    '115.42.66.56' => 'Spotcomm Office',
];

$cacheFile = 'dashboard_cache.json';
$cacheTime = 300; // 5 minutes cache

// Check cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $liveData = json_decode(file_get_contents($cacheFile), true);
} else {
    $liveData = getOptimizedMikrotikData();
    file_put_contents($cacheFile, json_encode($liveData));
}

function getOptimizedMikrotikData() {
    global $locationMap;
    $API = new RouterosAPI();
    
    $data = [
        'stats' => [
            'total_locations' => count($locationMap),
            'locations_up' => 0,
            'locations_down' => 0,
            'total_nodes' => 0,
            'nodes_up' => 0,
            'nodes_down' => 0,
            'avg_ping' => 0,
            'ping_data' => [],
            'uptime_percent' => 0,
            'live_count' => 0,
            'offline_count' => 0,
            'stale_count' => 0,
            'active_alerts' => 0,
            'critical_alerts' => 0,
            'warning_alerts' => 0,
            'parents_count' => 11,
            'children_count' => 22,
            'standalone_count' => 5,
            'data_replication_none' => 21,
            'data_replication_single' => 8,
            'data_replication_multi' => 9,
        ],
        'locations' => [],
        'alerts' => [],
        'groups' => [
            'Hospital' => [],
            'University' => [],
            'Other' => []
        ]
    ];

    $total_ping = 0;
    $ping_count = 0;
    $uptime_sum = 0;
    $total_cpu_load_sum = 0;
    $cpu_load_count = 0;
    $total_bandwidth_sum_bytes = 0;
    $bandwidth_data_points = 0;

    foreach ($locationMap as $ip => $name) {
        $location = [
            'public_ip' => $ip,
            'nodes' => 0,
            'nodes_up' => 0,
            'status' => 'down',
            'ping' => 0,
            'bandwidth_usage' => 0,
            'uptime' => 'N/A',
            'cpu_usage' => 0,
            'average_uptime' => 'N/A',
        ];

        if ($API->connect($ip, 'spotcomm', 'admin@1234')) {
            try {
                $API->write('/interface/print');
                $interfaces = $API->read();

                $nodes = 0;
                $nodes_up = 0;

                foreach ($interfaces as $interface) {
                    if (isset($interface['disabled']) && $interface['disabled'] === 'true') continue;

                    $nodes++;
                    $status = isset($interface['running']) && $interface['running'] === 'true' ? 'up' : 'down';
                    if ($status === 'up') $nodes_up++;

                    if (isset($interface['tx-byte']) && isset($interface['rx-byte'])) {
                        $total_bytes = $interface['tx-byte'] + $interface['rx-byte'];
                        $location['bandwidth_usage'] += $total_bytes;
                    }
                }

                $API->write('/ping', false);
                $API->write('=address=8.8.8.8', false);
                $API->write('=count=3');
                $ping = $API->read();
                
                if (!empty($ping) && isset($ping[0]['avg-rtt'])) {
                    $ping_ms = preg_replace('/[^0-9.]/', '', $ping[0]['avg-rtt']);
                    if (is_numeric($ping_ms)) {
                        $ping_ms = (float)$ping_ms;
                        $location['ping'] = round($ping_ms, 2);
                        $total_ping += $ping_ms;
                        $ping_count++;
                    }
                }

                $API->write('/system/resource/print');
                $resource = $API->read();
                if (!empty($resource) && isset($resource[0]['uptime'])) {
                    $uptime_str = $resource[0]['uptime'];
                    $uptime_seconds = 0;
                    if (preg_match('/(\d+)w/', $uptime_str, $matches)) $uptime_seconds += $matches[1] * 7 * 24 * 3600;
                    if (preg_match('/(\d+)d/', $uptime_str, $matches)) $uptime_seconds += $matches[1] * 24 * 3600;
                    if (preg_match('/(\d+)h/', $uptime_str, $matches)) $uptime_seconds += $matches[1] * 3600;
                    if (preg_match('/(\d+)m/', $uptime_str, $matches)) $uptime_seconds += $matches[1] * 60;
                    if (preg_match('/(\d+)s/', $uptime_str, $matches)) $uptime_seconds += $matches[1];

                    $total_time_frame_seconds = 30 * 24 * 3600; // Last 30 days
                    $location['uptime'] = ($total_time_frame_seconds > 0) ? round(($uptime_seconds / $total_time_frame_seconds) * 100, 2) : 0;
                    $uptime_sum += $location['uptime'];
                    $location['average_uptime'] = $location['uptime'] . '%';
                }

                if (!empty($resource) && isset($resource[0]['cpu-load'])) {
                    $location['cpu_usage'] = (int)$resource[0]['cpu-load'];
                    $total_cpu_load_sum += $location['cpu_usage'];
                    $cpu_load_count++;
                }

                $location['nodes'] = $nodes;
                $location['nodes_up'] = $nodes_up;
                $location['status'] = $nodes_up > 0 ? 'up' : 'down';

                $data['stats']['total_nodes'] += $nodes;
                $data['stats']['nodes_up'] += $nodes_up;
                $data['stats']['nodes_down'] += ($nodes - $nodes_up);

                if ($nodes_up > 0) {
                    $data['stats']['locations_up']++;
                    $data['stats']['live_count']++;
                } else {
                    $data['stats']['locations_down']++;
                    $data['stats']['offline_count']++;
                }

                if ($location['bandwidth_usage'] > 180 * 1024 * 1024) {
                    $data['alerts'][] = [
                        'location' => $name,
                        'message' => 'High bandwidth usage: '.round($location['bandwidth_usage']/(1024*1024), 2).'MB',
                        'type' => 'warning',
                        'time' => date('H:i:s')
                    ];
                    $data['stats']['warning_alerts']++;
                }
                
                $total_bandwidth_sum_bytes += $location['bandwidth_usage'];
                $bandwidth_data_points++;

                // Group categorization
                if (strpos($name, 'Hospital') !== false) {
                    $data['groups']['Hospital'][] = $name;
                } elseif (strpos($name, 'College') !== false || strpos($name, 'Education') !== false) {
                    $data['groups']['University'][] = $name;
                } else {
                    $data['groups']['Other'][] = $name;
                }

            } catch (Exception $e) {
                error_log("Error on $ip: " . $e->getMessage());
                $data['stats']['locations_down']++;
                $data['stats']['offline_count']++;
                $data['alerts'][] = [
                    'location' => $name,
                    'message' => 'Connection error',
                    'type' => 'danger',
                    'time' => date('H:i:s')
                ];
                $data['stats']['critical_alerts']++;
            }

            $API->disconnect();
        } else {
            error_log("Could not connect to $ip");
            $data['stats']['locations_down']++;
            $data['stats']['offline_count']++;
            $data['alerts'][] = [
                'location' => $name,
                'message' => 'Connection failed',
                'type' => 'danger',
                'time' => date('H:i:s')
            ];
            $data['stats']['critical_alerts']++;
        }

        $data['locations'][$name] = $location;
        $data['stats']['ping_data'][$name] = $location['ping'];
    }

    if ($ping_count > 0) {
        $data['stats']['avg_ping'] = round($total_ping / $ping_count, 2);
    }

    if (count($locationMap) > 0) {
        $data['stats']['uptime_percent'] = round($uptime_sum / count($locationMap), 2);
    }
    
    $data['stats']['active_alerts'] = count($data['alerts']);
    $data['stats']['stale_count'] = 5; // Placeholder

    if ($cpu_load_count > 0) {
        $data['stats']['average_cpu_usage'] = round($total_cpu_load_sum / $cpu_load_count) . '%';
    }

    if ($bandwidth_data_points > 0) {
        $data['stats']['average_bandwidth_mbps'] = round(($total_bandwidth_sum_bytes / $bandwidth_data_points) / (1024 * 1024));
    }

    return $data;
}

$liveData = getOptimizedMikrotikData();
$jsonData = json_encode($liveData);

// Calculate CPU float for display
$cpuRaw = $liveData['stats']['average_cpu_usage'] ?? '0%';
$cpuFloat = floatval(str_replace('%', '', $cpuRaw));

// Calculate total bandwidth
$totalBandwidth = 0;
$locationCount = 0;
foreach ($liveData['locations'] as $location) {
    $totalBandwidth += $location['bandwidth_usage'];
    $locationCount++;
}
$avgBandwidthMbps = 0;
if ($locationCount > 0) {
    $avgBandwidthMbps = ($totalBandwidth / $locationCount) / (1024 * 1024);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --dark-bg: #000;
            --card-bg: rgb(21, 24, 24);
            --primary: #3b82f6;
            --secondary: #6366f1;
            --warning: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --text: #f8fafc;
            --text-light: #94a3b8;
            --border: #334155;
            --highlight: #60a5fa;
        }
        
        body {
            background-color: var(--dark-bg);
            color: var(--text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
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
            background: conic-gradient(#e53e3e 0%, #dd6b20 50%, transparent 50%, transparent 100%);
            transition: background 0.3s ease-in-out;
        }
        
        .alert-circle-inner {
            background-color: #2a2a2a;
            width: 80%;
            height: 80%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            position: absolute;
        }
        
        .alert-circle-text {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .sidebar {
            background-color: var(--card-bg);
            border-right: 1px solid var(--border);
            min-height: 100vh;
            position: fixed;
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
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            padding: 20px;
            margin-left: 250px;
            width: calc(100% - 250px);
            background-color: var(--dark-bg);
        }
        
        .dashboard-header {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: transform 0.3s;
            height: auto;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        
        .stat-card .card-title {
            color: var(--text-light);
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
        
        .stat-card.live .value {
            color: var(--primary);
        }
        
        .stat-card.down .value {
            color: var(--danger);
        }
        
        .stat-card.warning .value {
            color: var(--warning);
        }
        
        .stat-card.info .value {
            color: var(--highlight);
        }
        
        .stat-card.success .value {
            color: var(--success);
        }
        
        .main-dashboard-box {
            background-color: rgb(21, 24, 24);
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
        }
        
        .inset-box {
            background-color: #3a3a3a;
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
        
        .status-bar-live { background-color: #2d8a5f; }
        .status-bar-down { background-color: #c53030; }
        .status-bar-stale { background-color: #718096; }
        
        .text-netdata-light { color: #e0e0e0; }
        .text-netdata-dark-gray { color: #a0aec0; }
        .bg-netdata-dark { background-color: #1d1d1d; }
        .bg-netdata-main-box { background-color: #2a2a2a; }
        .bg-netdata-inner-box { background-color: #3a3a3a; }
        .bg-netdata-tooltip { background-color: #2c2c2c; }
        .border-netdata-dark { border-color: #4a4a4a !important; }
        
        .text-netdata-blue { color: #63b3ed; }
        .text-netdata-purple { color: #805ad5; }
        .text-netdata-orange { color: #f6ad55; }
        .text-netdata-red { color: #e53e3e; }
        .text-netdata-green { color: #2d8a5f; }
        
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
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .location-card.up {
            border-left-color: var(--success);
        }
        
        .location-card.down {
            border-left-color: var(--danger);
        }
        
        .location-card h3 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 600;
        }
        
        .location-card .public-ip {
            font-size: 12px;
            background: rgba(59, 130, 246, 0.1);
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
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .location-card .status-badge.down {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .alert-item {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .alert-item:hover {
            transform: translateX(3px);
        }
        
        .alert-item.warning {
            background: rgba(245, 158, 11, 0.1);
            border-left: 3px solid var(--warning);
        }
        
        .alert-item.danger {
            background: rgba(239, 68, 68, 0.1);
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
        
        .group-card h4 i {
            margin-right: 10px;
        }
        
        .gauge-container {
            height: 120px;
            width: 100%;
        }
        
        .ping-value {
            font-size: 24px;
            font-weight: 700;
        }
        
        .ping-value.good {
            color: var(--success);
        }
        
        .ping-value.medium {
            color: var(--warning);
        }
        
        .ping-value.bad {
            color: var(--danger);
        }
        
        .ping-box {
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .ping-box h2 {
            margin-top: 0;
            text-align: center;
            color: #333;
        }
        
        .ping-status {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        
        .ping-label {
            font-weight: bold;
            color: #555;
        }
        
        .ping-value {
            color: #10b981;
        }
        
        .ping-down {
            color: red;
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
            background: #2563eb;
            color: white;
        }
        
        .logout-btn {
            background: var(--danger);
            color: white;
        }
        
        .logout-btn:hover {
            background: #dc2626;
            color: white;
        }
        
        .view-all-btn {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
            border: 1px solid var(--primary);
            text-decoration: none;
        }
        
        .view-all-btn:hover {
            background: rgba(59, 130, 246, 0.2);
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
            color: var(--text-light);
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
        }
        
        .node-stats .up {
            color: var(--success);
        }
        
        .node-stats .down {
            color: var(--danger);
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        .last-updated {
            font-size: 12px;
            color: var(--text-light);
            text-align: right;
            margin-top: 10px;
        }
        
        .nav-tabs {
            border-bottom: 1px solid var(--border);
        }
        
        .nav-tabs .nav-link {
            color: var(--text-light);
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
            border-color: var(--border) var(--border) var(--dark-bg);
            border-bottom: none;
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        .metric-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid var(--border);
            margin-bottom: 15px;
        }
        
        .metric-card h5 {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-change {
            font-size: 12px;
            display: flex;
            align-items: center;
        }
        
        .metric-change.up {
            color: var(--success);
        }
        
        .metric-change.down {
            color: var(--danger);
        }
        
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
        
        .health-indicator.good {
            background: var(--success);
        }
        
        .health-indicator.warning {
            background: var(--warning);
        }
        
        .health-indicator.critical {
            background: var(--danger);
        }
        
        .health-label {
            font-size: 12px;
            color: var(--text-light);
        }
        
        /* NetData-like grid layout */
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .grid-item {
            background: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border);
            padding: 15px;
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
        
        .grid-item-actions {
            display: flex;
            gap: 5px;
        }
        
        .grid-item-action {
            background: rgba(255,255,255,0.1);
            border: none;
            color: var(--text-light);
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .grid-item-action:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .grid-item-chart {
            height: 150px;
            width: 100%;
        }
        
        .grid-item:fullscreen {
            background-color: #1e293b;
            padding: 2rem;
            z-index: 9999;
            height: 100vh;
        }
        
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: -250px;
                top: 0;
                bottom: 0;
                z-index: 1000;
                transition: left 0.3s;
            }
            
            .sidebar.show {
                left: 0;
            }
            
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
        
        .sidebar-toggle {
            display: none;
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
            <a href="#" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
                <span class="fs-4">Ziauddin</span>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="#" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        Overview
                    </a>
                </li>
                <li>
                    <a href="locations.php" class="nav-link">
                        <i class="fas fa-network-wired"></i>
                        All Locations
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main content -->
        <div class="main-content">
            <div class="dashboard-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1><i class="fas fa-network-wired"></i> Network Overview</h1>
                        <p class="mb-0 text-light">Monitoring all locations</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="d-flex justify-content-md-end gap-3">
                            <button class="btn-custom refresh-btn" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <a href="logout.php" class="btn-custom logout-btn">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Dashboard Content -->
            <div class="row mx-auto row-cols-1 row-cols-md-4 g-4 mb-4">
                <!-- Left Section: Location Dots and Filters -->
                <div class="col-12 col-lg-8 d-flex flex-column align-items-start justify-content-start border-end border-netdata-dark stat-card">
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <!-- Group By -->
                        <div class="flex-grow-1" style="min-width: 200px;">
                            <label for="group-by" class="form-label text-muted small mb-1">Group By</label>
                            <select id="group-by" class="form-select form-select-sm shadow-sm" style="border-radius: 8px; border: 1px solid #e0e0e0;">
                                <option value="None">-- Select Group --</option>
                                <option value="Hospital">Hospital</option>
                                <option value="University">University</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Color By -->
                        <div class="flex-grow-1" style="min-width: 200px;">
                            <label for="color-by" class="form-label text-muted small mb-1">Color By</label>
                            <select id="color-by" class="form-select form-select-sm shadow-sm" style="border-radius: 8px; border: 1px solid #e0e0e0;">
                                <option value="Status">Status</option>
                                <option value="Live">Live Only</option>
                                <option value="Down">Down Only</option>
                            </select>
                        </div>
                    </div>

                    <!-- Status Legends -->
                    <div class="d-flex align-items-center flex-wrap gap-3">
                        <span class="d-flex align-items-center">
                            <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: #2d8a5f;"></span>Live
                        </span>
                        <span class="d-flex align-items-center">
                            <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: #c53030;"></span>Down
                        </span>
                        <span class="d-flex align-items-center">
                            <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: #718096;"></span>Stale
                        </span>
                        <span class="d-flex align-items-center">
                            <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: #1d1d1d;"></span>Unseen
                        </span>
                    </div>

                    <!-- Location Dots Section -->
                    <div class="p-4 rounded-3 shadow-sm min-h-120px d-flex justify-content-start align-items-start position-relative mt-4 w-100">
                        <div id="location-dots-container" class="d-flex flex-wrap gap-3 justify-content-center">
                            <!-- Location dots will be populated by JavaScript -->
                        </div>
                        <div id="details-box" class="position-absolute bg-netdata-tooltip p-3 rounded-3 shadow-lg border border-netdata-dark z-1 d-none mt-5">
                            <h3 id="details-location-name" class="fs-5 fw-bold text-white mb-2"></h3>
                            <p class="text-netdata-light fs-6 mb-1">IP: <span id="details-ip" class="font-monospace"></span></p>
                            <p class="text-netdata-light fs-6 mb-1">Status: <span id="details-status" class="fw-semibold"></span></p>
                            <p class="text-netdata-light fs-6 mb-1">CPU Usage: <span id="details-cpu" class="fw-semibold"></span></p>
                            <p class="text-netdata-light fs-6 mb-1">Ping: <span id="details-ping" class="fw-semibold"></span></p>
                            <p class="text-netdata-light fs-6 mb-1">Bandwidth: <span id="details-bandwidth" class="fw-semibold"></span></p>
                            <p class="text-netdata-light fs-6 mb-0">Uptime: <span id="details-uptime" class="fw-semibold"></span></p>
                        </div>
                    </div>
                </div>

                <!-- Right Section: Ping Status -->
                <div class="col-lg-4">
                    <div class="ping-box stat-card">
                        <h2 class="text-light">Average Ping Status</h2>
                        <?php
                        $ch = curl_init("http://localhost/ziauddin/examples/ping_gateway.php");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $pingData = curl_exec($ch);
                        curl_close($ch);

                        $pingResults = json_decode($pingData, true);

                        if (is_array($pingResults)) {
                            // Set logo image paths
                            $labels = [
                                'G' => ['label' => 'Google DNS', 'icon' => '../assets/images/g.png'],
                                'C' => ['label' => 'Cloudflare DNS', 'icon' => '../assets/images/c.png'],
                            ];

                            foreach ($labels as $key => $info) {
                                if (isset($pingResults[$key])) {
                                    $entry = $pingResults[$key];
                                    echo '<div class="ping-status d-flex justify-content-between align-items-center mb-3">
                                            <div class="d-flex align-items-center ping-label text-light">
                                                <img src="' . $info['icon'] . '" alt="' . $info['label'] . '" width="50" height="50" class="me-2" />
                                                ' . $info['label'] . '
                                            </div>
                                            <div class="ping-value ' . ($entry['status'] === 'down' ? 'ping-down' : '') . '">' .
                                                ($entry['latency'] ?? 'N/A') .
                                            '</div>
                                          </div>';
                                }
                            }
                        } else {
                            echo "<p style='color:red; text-align:center;'>Error: Unable to fetch ping data.</p>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row mx-auto row-cols-1 row-cols-md-4 g-4 mb-4">
                <!-- Total Locations Card -->
                <div class="col d-flex flex-column align-items-center border-netdata-dark stat-card">
                    <h2 class="text-netdata-dark-gray fs-6 fw-semibold">Total Locations</h2> 
                    <p id="total-locations-count" class="fs-1 fw-bold text-white"><?php echo $liveData['stats']['total_locations']; ?></p>

                    <div class="d-flex align-items-center space-x-4 fs-6 mt-2">
                        <div class="me-3">
                            <span class="text-netdata-dark-gray">Live</span>
                            <span id="live-count-inline" class="fw-bold text-white ms-1"><?php echo $liveData['stats']['live_count']; ?></span>
                        </div>
                        <div class="me-3">
                            <span class="text-netdata-dark-gray">Down</span>
                            <span id="offline-count-inline" class="fw-bold text-white ms-1"><?php echo $liveData['stats']['offline_count']; ?></span>
                        </div>
                        <div>
                            <span class="text-netdata-dark-gray">Stale</span>
                            <span id="stale-count-inline" class="fw-bold text-white ms-1"><?php echo $liveData['stats']['stale_count']; ?></span>
                        </div>
                    </div>

                    <div class="d-flex w-100 mt-2" style="max-width: 150px;">
                        <div class="status-bar status-bar-live" style="width: <?php echo ($liveData['stats']['total_locations'] > 0) ? ($liveData['stats']['live_count'] / $liveData['stats']['total_locations'] * 100) : 0; ?>%;"></div>
                        <div class="status-bar status-bar-down" style="width: <?php echo ($liveData['stats']['total_locations'] > 0) ? ($liveData['stats']['offline_count'] / $liveData['stats']['total_locations'] * 100) : 0; ?>%;"></div>
                        <div class="status-bar status-bar-stale" style="width: <?php echo ($liveData['stats']['total_locations'] > 0) ? ($liveData['stats']['stale_count'] / $liveData['stats']['total_locations'] * 100) : 0; ?>%;"></div>
                    </div>

                    <!-- Alert Section -->
                    <div class="d-flex align-items-center justify-content-between w-100 mt-4 mb-2">
                        <h2 class="text-netdata-dark-gray fs-6 fw-semibold mb-0">Active alerts</h2>
                        <a href="alert.php" class="text-decoration-none text-netdata-blue d-flex align-items-center">
                            <span class="fs-6">View All</span>
                            <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>

                    <div class="d-flex align-items-center">
                        <div class="alert-circle me-3">
                            <div class="alert-circle-inner">
                                <span class="alert-circle-text"><?php echo $liveData['stats']['active_alerts']; ?></span>
                            </div>
                        </div>
                        <div class="d-flex flex-column fs-6">
                            <p class="mb-1"><span class="fw-bold text-netdata-red"><?php echo $liveData['stats']['critical_alerts']; ?></span> Critical</p>
                            <p class="mb-0"><span class="fw-bold text-netdata-orange"><?php echo $liveData['stats']['warning_alerts']; ?></span> Warning</p>
                        </div>
                    </div>
                </div>

                <!-- Ping Status Card -->
                <div class="col">
                    <div class="grid-item stat-card">
                        <div class="grid-item-header">
                            <div class="grid-item-title">Ping Status</div>
                            <div class="grid-item-actions">
                                <button class="grid-item-action" onclick="toggleFullscreen(this)">
                                    <i class="fas fa-expand"></i>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="ping-value <?= $liveData['stats']['avg_ping'] < 50 ? 'good' : ($liveData['stats']['avg_ping'] < 100 ? 'medium' : 'bad') ?>">
                                    <?= $liveData['stats']['avg_ping'] ?>ms
                                </div>
                                <div class="text-light">Average Ping</div>
                            </div>
                            <div class="gauge-container">
                                <canvas id="pingGauge"></canvas>
                            </div>
                        </div>
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="value"><?= min($liveData['stats']['ping_data']) ?>ms</div>
                                <div class="text-light">Best</div>
                            </div>
                            <div class="col-4">
                                <div class="value"><?= max($liveData['stats']['ping_data']) ?>ms</div>
                                <div class="text-light">Worst</div>
                            </div>
                            <div class="col-4">
                                <div class="value"><?= count($liveData['stats']['ping_data']) ?></div>
                                <div class="text-light">Locations</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Uptime Overview Card -->
                <div class="col">
                    <div class="grid-item stat-card">
                        <div class="grid-item-header">
                            <div class="grid-item-title">Uptime Overview</div>
                            <div class="grid-item-actions">
                                <button class="grid-item-action" onclick="toggleFullscreen(this)">
                                    <i class="fas fa-expand"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="uptimeChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Network Health Card -->
                <div class="col">
                    <div class="stat-card">
                        <div class="card-title">Network Health</div>
                        <div class="value <?= $liveData['stats']['uptime_percent'] > 95 ? 'text-success' : ($liveData['stats']['uptime_percent'] > 90 ? 'text-warning' : 'text-danger') ?>">
                            <?= $liveData['stats']['uptime_percent'] ?>%
                        </div>
                        <div class="text-light">Average uptime</div>
                        <div class="metric-change <?= $liveData['stats']['uptime_percent'] > 95 ? 'up' : 'down' ?> mt-2">
                            <i class="fas fa-<?= $liveData['stats']['uptime_percent'] > 95 ? 'arrow-up' : 'arrow-down' ?>"></i>
                            <?= $liveData['stats']['uptime_percent'] > 95 ? 'Good' : 'Needs attention' ?>
                        </div>
                    </div> 
                    <br />
                    <div class="stat-card">
                        <h2 class="text-netdata-dark-gray fs-6 fw-semibold mt-4">Average CPU Usage</h2>
                        <p id="avg-cpu-metric" class="fs-1 fw-bold text-netdata-purple">
                            <?php echo number_format($cpuFloat, 1); ?>%
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Main Grid Layout -->
            <div class="grid-container">
                <!-- Alerts Card -->
                <div class="grid-item">
                    <div class="grid-item-header">
                        <div class="grid-item-title">Recent Alerts</div>
                        <div class="grid-item-actions">
                            <button class="grid-item-action" onclick="toggleFullscreen(this)">
                                <i class="fas fa-expand"></i>
                            </button>
                        </div>
                    </div>
                    <?php if (empty($liveData['alerts'])): ?>
                        <div class="alert alert-success">No recent alerts</div>
                    <?php else: ?>
                        <div style="max-height: 200px; overflow-y: auto;">
                            <?php foreach (array_slice($liveData['alerts'], 0, 5) as $alert): ?>
                                <div class="alert-item <?= $alert['type'] ?>">
                                    <i class="fas fa-<?= $alert['type'] === 'danger' ? 'exclamation-triangle' : 'exclamation-circle' ?>"></i>
                                    <div>
                                        <strong><?= htmlspecialchars($alert['location']) ?></strong>
                                        <div><?= htmlspecialchars($alert['message']) ?></div>
                                        <small class="text-light"><?= $alert['time'] ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Locations Card -->
                <div class="grid-item">
                    <div class="grid-item-header">
                        <div class="grid-item-title">Locations Status</div>
                        <div class="grid-item-actions">
                            <button class="grid-item-action" onclick="toggleFullscreen(this)">
                                <i class="fas fa-expand"></i>
                            </button>
                        </div>
                    </div>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($liveData['locations'] as $name => $location): ?>
                            <div class="location-card <?= $location['status'] ?> mb-2">
                                <h3>
                                    <span><?= $name ?></span>
                                    <span class="status-badge <?= $location['status'] ?>">
                                        <i class="fas fa-<?= $location['status'] === 'up' ? 'check-circle' : 'times-circle' ?>"></i>
                                        <?= ucfirst($location['status']) ?>
                                    </span>
                                </h3>
                                <div class="d-flex justify-content-between">
                                    <span class="public-ip"><?= $location['public_ip'] ?></span>
                                    <span class="<?= $location['ping'] < 50 ? 'text-success' : ($location['ping'] < 100 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $location['ping'] ?>ms
                                    </span>
                                </div>
                            </div> 
                            <br />
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Bandwidth Usage Card -->
                <div class="grid-item">
                    <div class="grid-item-header">
                        <div class="grid-item-title">Bandwidth Usage</div>
                        <div class="grid-item-actions">
                            <button class="grid-item-action" onclick="toggleFullscreen(this)">
                                <i class="fas fa-expand"></i>
                            </button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="bandwidthChart"></canvas>
                    </div>
                    <div class="text-center mt-2">
                        <span class="text-light">Average: </span>
                        <span class="fw-bold"><?= $avgBandwidthMbps ?> Mbps</span>
                    </div>
                </div>
            </div>
            
            <div class="last-updated" id="lastUpdateTime">
                Last updated: <?= date('H:i:s') ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Current location data from PHP
        const currentLocationData = <?php echo $jsonData; ?>;
        
        // Initialize charts
        let pingGauge, uptimeChart, bandwidthChart;
        
        // Sidebar toggle for mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
        
        // Ping Gauge
        function initPingGauge() {
            pingGauge = new Chart(document.getElementById('pingGauge'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [currentLocationData.stats.avg_ping, 100 - currentLocationData.stats.avg_ping],
                        backgroundColor: [
                            currentLocationData.stats.avg_ping < 50 ? '#10b981' : 
                            (currentLocationData.stats.avg_ping < 100 ? '#f59e0b' : '#ef4444'),
                            '#334155'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    circumference: 180,
                    rotation: 270,
                    cutout: '80%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: false
                        }
                    }
                }
            });
        }
        
        // Uptime Chart
        function initUptimeChart() {
            const uptimeCtx = document.getElementById('uptimeChart').getContext('2d');
            uptimeChart = new Chart(uptimeCtx, {
                type: 'bar',
                data: {
                    labels: ['Hospital', 'University', 'Other'],
                    datasets: [{
                        label: 'Uptime %',
                        data: [
                            calculateGroupAverage(currentLocationData, 'Hospital'),
                            calculateGroupAverage(currentLocationData, 'University'),
                            calculateGroupAverage(currentLocationData, 'Other')
                        ],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.7)',
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(245, 158, 11, 0.7)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(59, 130, 246, 1)',
                            'rgba(245, 158, 11, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                color: '#94a3b8',
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                color: '#334155'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#94a3b8'
                            },
                            grid: {
                                color: '#334155'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toFixed(2) + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Bandwidth Chart
        function initBandwidthChart() {
            const bandwidthCtx = document.getElementById('bandwidthChart').getContext('2d');
            bandwidthChart = new Chart(bandwidthCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
                    datasets: [{
                        label: 'Bandwidth Usage (MB)',
                        data: [120, 190, 150, 220, 250, 280, 310],
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#94a3b8'
                            },
                            grid: {
                                color: '#334155'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#94a3b8'
                            },
                            grid: {
                                color: '#334155'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#f8fafc'
                            }
                        }
                    }
                }
            });
        }
        
        // Location dots functionality
        function getStatusColor(status) {
            switch (status) {
                case 'up': return 'bg-success';
                case 'down': return 'bg-danger';
                case 'stale': return 'bg-secondary';
                case 'unseen': return 'bg-dark';
                default: return 'bg-info';
            }
        }

        function renderLocationDots(locationsToRender) {
            const container = document.getElementById('location-dots-container');
            container.innerHTML = '';

            for (const locationName in locationsToRender) {
                const location = locationsToRender[locationName];
                const dot = document.createElement('div');
                dot.className = `location-dot cursor-pointer transition-all duration-200 ease-in-out ${getStatusColor(location.status)}`;
                dot.title = locationName;
                dot.dataset.locationName = locationName;

                dot.addEventListener('mouseenter', (event) => {
                    const hoveredLoc = currentLocationData.locations[event.target.dataset.locationName];
                    const detailsBox = document.getElementById('details-box');
                    
                    document.getElementById('details-location-name').textContent = event.target.dataset.locationName;
                    document.getElementById('details-ip').textContent = hoveredLoc.public_ip;
                    const statusEl = document.getElementById('details-status');
                    statusEl.textContent = hoveredLoc.status.charAt(0).toUpperCase() + hoveredLoc.status.slice(1);
                    statusEl.className = `fw-semibold ${getStatusColor(hoveredLoc.status).replace('bg-', 'text-')}`;
                    document.getElementById('details-cpu').textContent = `${hoveredLoc.cpu_usage}%`;
                    document.getElementById('details-ping').textContent = `${hoveredLoc.ping}ms`;
                    document.getElementById('details-bandwidth').textContent = `${(hoveredLoc.bandwidth_usage / (1024*1024)).toFixed(2)} MB`;
                    document.getElementById('details-uptime').textContent = `${hoveredLoc.uptime}`;

                    const dotRect = event.target.getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();

                    let left = dotRect.left - containerRect.left + dotRect.width / 2;
                    let top = dotRect.top - containerRect.top + dotRect.height / 2;

                    const tooltipWidth = detailsBox.offsetWidth;
                    const tooltipHeight = detailsBox.offsetHeight;

                    if (left + tooltipWidth > containerRect.width) {
                        left = dotRect.left - containerRect.left - tooltipWidth - 15;
                    }
                    if (top + tooltipHeight > containerRect.height) {
                        top = dotRect.top - containerRect.top - tooltipHeight - 15;
                    }

                    detailsBox.style.left = `${left}px`;
                    detailsBox.style.top = `${top}px`;
                    detailsBox.classList.remove('d-none');
                });

                dot.addEventListener('mouseleave', () => {
                    document.getElementById('details-box').classList.add('d-none');
                });

                container.appendChild(dot);
            }
        }

        function filterAndRender() {
            const selectedGroup = document.getElementById('group-by').value;
            const selectedColor = document.getElementById('color-by').value;
            
            let locationsToRender = {};

            for (const locationName in currentLocationData.locations) {
                const location = currentLocationData.locations[locationName];
                
                // Group filter
                if (selectedGroup !== 'None') {
                    const group = currentLocationData.groups[selectedGroup];
                    if (!group || !group.includes(locationName)) {
                        continue;
                    }
                }

                // Color filter
                if (selectedColor === 'Live' && location.status !== 'up') {
                    continue;
                }
                if (selectedColor === 'Down' && location.status !== 'down') {
                    continue;
                }

                locationsToRender[locationName] = location;
            }
            renderLocationDots(locationsToRender);
        }
        
        // Calculate group average
        function calculateGroupAverage(data, groupName) {
            const groupLocations = data.groups[groupName];
            if (!groupLocations || groupLocations.length === 0) return 0;
            
            let total = 0;
            let count = 0;
            
            groupLocations.forEach(locationName => {
                if (data.locations[locationName] && data.locations[locationName].uptime) {
                    total += data.locations[locationName].uptime;
                    count++;
                }
            });
            
            return count > 0 ? Math.round(total / count) : 0;
        }
        
        // Toggle fullscreen
        function toggleFullscreen(button) {
            const card = button.closest('.grid-item');
            if (!document.fullscreenElement) {
                if (card.requestFullscreen) {
                    card.requestFullscreen();
                } else if (card.webkitRequestFullscreen) {
                    card.webkitRequestFullscreen();
                } else if (card.msRequestFullscreen) {
                    card.msRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }
        
        // Initialize charts when they become visible
        function initChartsWhenVisible() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const chartId = entry.target.id;
                        if (chartId === 'pingGauge' && !window.pingGaugeInitialized) {
                            initPingGauge();
                            window.pingGaugeInitialized = true;
                        }
                        if (chartId === 'uptimeChart' && !window.uptimeChartInitialized) {
                            initUptimeChart();
                            window.uptimeChartInitialized = true;
                        }
                        if (chartId === 'bandwidthChart' && !window.bandwidthChartInitialized) {
                            initBandwidthChart();
                            window.bandwidthChartInitialized = true;
                        }
                        observer.unobserve(entry.target);
                    }
                });
            });

            document.querySelectorAll('.chart-container').forEach(el => {
                observer.observe(el);
            });
        }
        
        // Refresh data function
        function refreshData() {
            fetch('get_dashboard_data.php')
                .then(response => response.json())
                .then(data => {
                    // Update stats
                    document.getElementById('total-locations-count').textContent = data.stats.total_locations;
                    document.getElementById('live-count-inline').textContent = data.stats.live_count;
                    document.getElementById('offline-count-inline').textContent = data.stats.offline_count;
                    document.getElementById('stale-count-inline').textContent = data.stats.stale_count;
                    
                    // Update status bars
                    document.querySelector('.status-bar-live').style.width = 
                        (data.stats.total_locations > 0 ? (data.stats.live_count / data.stats.total_locations * 100) : 0) + '%';
                    document.querySelector('.status-bar-down').style.width = 
                        (data.stats.total_locations > 0 ? (data.stats.offline_count / data.stats.total_locations * 100) : 0) + '%';
                    document.querySelector('.status-bar-stale').style.width = 
                        (data.stats.total_locations > 0 ? (data.stats.stale_count / data.stats.total_locations * 100) : 0) + '%';
                    
                    // Update alert counts
                    document.querySelector('.alert-circle-text').textContent = data.stats.active_alerts;
                    document.querySelector('.fw-bold.text-netdata-red').textContent = data.stats.critical_alerts;
                    document.querySelector('.fw-bold.text-netdata-orange').textContent = data.stats.warning_alerts;
                    
                    // Update CPU metric
                    document.getElementById('avg-cpu-metric').textContent = data.stats.average_cpu_usage;
                    
                    // Update ping gauge if it exists
                    if (window.pingGauge) {
                        pingGauge.data.datasets[0].data = [data.stats.avg_ping, 100 - data.stats.avg_ping];
                        pingGauge.update();
                    }
                    
                    // Update uptime chart if it exists
                    if (window.uptimeChart) {
                        const hospitalAvg = calculateGroupAverage(data, 'Hospital');
                        const universityAvg = calculateGroupAverage(data, 'University');
                        const otherAvg = calculateGroupAverage(data, 'Other');
                        
                        uptimeChart.data.datasets[0].data = [hospitalAvg, universityAvg, otherAvg];
                        uptimeChart.update();
                    }
                    
                    // Update last updated time
                    const now = new Date();
                    document.getElementById('lastUpdateTime').textContent = `Last updated: ${now.toLocaleTimeString()}`;
                })
                .catch(error => {
                    console.error('Error refreshing dashboard:', error);
                    document.getElementById('lastUpdateTime').textContent = 'Last update failed';
                });
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
            });
            
            // Initialize location dots
            filterAndRender();
            
            // Initialize chart event listeners
            document.getElementById('group-by').addEventListener('change', filterAndRender);
            document.getElementById('color-by').addEventListener('change', filterAndRender);
            
            // Initialize charts when visible
            initChartsWhenVisible();
            
            // Set up auto-refresh every 30 seconds
            setInterval(refreshData, 30000);
        });
    </script>
</body>
</html>