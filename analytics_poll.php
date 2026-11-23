<?php
// File: analytics_poll.php
// Location Analytics metrics for ONE router, with the ping from the central router.
// Used in-process and by router_worker.php. No cache, no simulated values.

require_once __DIR__ . '/routeros_api.class.php';

function doPingFromCentral($target_ip, $user, $pass, $central_ip = '115.42.66.56') {
    $API = new RouterosAPI();

    $latency = 'N/A';
    $status = false;

    error_log("Attempting ping from $central_ip to $target_ip");

    if ($API->connect($central_ip, $user, $pass)) {
        error_log("Successfully connected to central router");
        
        try {
            $API->write('/ping', false);
            $API->write('=address=' . $target_ip, false);
            // 3 echo requests, 200 ms apart: a real average, and about one second per router
            $API->write('=count=3', false);
            $API->write('=interval=200ms');
            $res = $API->read();

            error_log("Ping response for $target_ip: " . print_r($res, true));

            $times = [];
            foreach ($res as $r) {
                if (isset($r['time']) && $r['time'] !== 'timeout') {
                    $t = $r['time'];
                    if (strpos($t, '<') === 0) {
                        $times[] = 0.5;
                    } else {
                        $times[] = floatval(str_replace('ms', '', $t));
                    }
                }
            }

            if (!empty($times)) {
                $avg = array_sum($times) / count($times);

                // اگر بہت کم ہو تو IP-based variation (1-4 ms)
                $latency = round($avg, 1); // measured value only
                $status = true;
                error_log("Ping successful to $target_ip: $latency ms");
            } else {
                error_log("No ping times received for $target_ip");
            }
        } catch (Exception $e) {
            error_log("Ping failed for $target_ip: " . $e->getMessage());
        }
        $API->disconnect();
    } else {
        error_log("Failed to connect to central router $central_ip");
    }

    return ['status' => $status, 'latency' => $latency];
}

function parseUptimeSeconds($u) {
    preg_match_all('/(\d+)([wdhms])/i', $u, $m, PREG_SET_ORDER);
    $s = 0;
    foreach ($m as $p) {
        $n = (int)$p[1];
        $unit = strtolower($p[2]);
        if ($unit == 'w') $s += $n * 7 * 24 * 3600;
        if ($unit == 'd') $s += $n * 24 * 3600;
        if ($unit == 'h') $s += $n * 3600;
        if ($unit == 'm') $s += $n * 60;
        if ($unit == 's') $s += $n;
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


/** All analytics fields for one router. Each call opens its own connections. */
function analyticsForRouter($ip, $mt_user, $mt_pass, $interface, $locationName, $centralIp) {
    $m = [
        'ip' => $ip,
        'location' => $locationName,
        'cpu' => 0, 'memory' => 0, 'rx' => 0, 'tx' => 0,
        'total_memory' => 0, 'free_memory' => 0,
        'total_hdd' => 0, 'free_hdd' => 0,
        'packet_loss' => 0,
        'ping_status' => false, 'ping_ms' => 'N/A',
        'uptime' => '0s', 'cpu_temp' => 'N/A',
    ];

        $directAPI = new RouterosAPI();
        $directAPI->attempts = 1;
        $directAPI->delay = 0;
        $directAPI->timeout = 3;
        if ($directAPI->connect($ip, $mt_user, $mt_pass)) {
            try {
                // CPU, Memory, Uptime
                $directAPI->write('/system/resource/print');
                $res = $directAPI->read();
                if (!empty($res[0])) {
                    $m['cpu'] = round($res[0]['cpu-load'] ?? 0, 2);
                    $total_mem = max(1, $res[0]['total-memory'] ?? 1);
                    $free_mem = $res[0]['free-memory'] ?? 0;
                    $m['total_memory'] = round($total_mem / 1024 / 1024, 2);
                    $m['free_memory'] = round($free_mem / 1024 / 1024, 2);
                    $m['memory'] = round((1 - ($free_mem / $total_mem)) * 100, 2);

                    $total_hdd = $res[0]['total-hdd-space'] ?? 1;
                    $free_hdd = $res[0]['free-hdd-space'] ?? 0;
                    $m['total_hdd'] = round($total_hdd / 1024 / 1024, 2);
                    $m['free_hdd'] = round($free_hdd / 1024 / 1024, 2);

                    $m['uptime'] = formatUptime(parseUptimeSeconds($res[0]['uptime'] ?? '0s'));
                }

                // Bandwidth
                $interface = $interface;
                $directAPI->write('/interface/monitor-traffic', false);
                $directAPI->write("=interface={$interface}", false);
                $directAPI->write('=once=');
                $ifaces = $directAPI->read();
                if (!empty($ifaces[0])) {
                    $m['rx'] = round($ifaces[0]['rx-bits-per-second'] / 1000000, 3);
                    $m['tx'] = round($ifaces[0]['tx-bits-per-second'] / 1000000, 3);
                }

                // CPU Temperature
                $directAPI->write('/system/health/print');
                $health = $directAPI->read();
                if (!empty($health)) {
                    foreach ($health as $item) {
                        foreach ($item as $key => $value) {
                            if (stripos($key, 'temperature') !== false && is_numeric($value)) {
                                $m['cpu_temp'] = round($value, 1) . '°C';
                                break 2;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Metrics error for $ip: " . $e->getMessage());
            }
            $directAPI->disconnect();
        }

    // Ping from the central router. Measured value only; 'N/A' when it fails.
    $ping = doPingFromCentral($ip, $mt_user, $mt_pass, $centralIp);
    $m['ping_status'] = $ping['status'];
    $m['ping_ms'] = $ping['status'] ? $ping['latency'] : 'N/A';

    return $m;
}
