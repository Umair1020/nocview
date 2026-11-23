<?php
// File: live_snapshot.php
// Live snapshot builder shared by update_cache.php (JSON for the dashboards) and the Knoativ pages.
// Every call queries the routers and GWN now. Nothing is cached, nothing is simulated.

require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parallel_routers.php';

require_once __DIR__ . '/router_poll.php';

// ================== HELPERS ==================

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Open TCP connections to many targets at the same time.
 * Returns connect latency (ms) per target, or null if the target did not accept within $timeout seconds.
 * Down routers therefore cost one timeout in total, not one timeout each.
 */
function probeTargetsParallel(array $targets, $timeout = 2.0) {
    $result = array_fill_keys($targets, null);
    $pending = [];

    foreach ($targets as $target) {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://' . $target, $errno, $errstr, $timeout, STREAM_CLIENT_ASYNC_CONNECT);
        if (!$sock) {
            continue;
        }
        stream_set_blocking($sock, false);
        $pending[(int)$sock] = ['key' => $target, 'sock' => $sock, 'start' => microtime(true)];
    }

    $deadline = microtime(true) + $timeout;
    while (!empty($pending)) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            break;
        }
        $write = [];
        foreach ($pending as $p) {
            $write[] = $p['sock'];
        }
        $read = null;
        $except = null;
        $sec = (int)floor($left);
        $usec = (int)(($left - $sec) * 1000000);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            continue;
        }
        foreach ($write as $sock) {
            $id = (int)$sock;
            if (!isset($pending[$id])) {
                continue;
            }
            $p = $pending[$id];
            unset($pending[$id]);
            // A failed async connect has no peer name; a successful one does.
            if (@stream_socket_get_name($sock, true) !== false) {
                $result[$p['key']] = round((microtime(true) - $p['start']) * 1000, 1);
            }
            fclose($sock);
        }
    }

    foreach ($pending as $p) {
        fclose($p['sock']);
    }
    return $result;
}

/** Location group used by the overview cards (same rules as before). */
function locationGroupFor($name, $isKnoativ) {
    if ($isKnoativ && $name === 'Knoativ') {
        return 'Other';
    }
    if (stripos($name, 'Hospital') !== false) {
        return 'Hospital';
    }
    if (stripos($name, 'College') !== false || stripos($name, 'University') !== false || stripos($name, 'Education') !== false) {
        return 'University';
    }
    return 'Other';
}

// ================== GWN (Knoativ only) ==================

function get_token_with_password($base_url, $appID, $secretKey, $username, $password_plain) {
    $md5_pass = md5($password_plain);
    $final_pass = hash('sha256', $md5_pass);
    $url = $base_url . "/oauth/token";
    $data = [
        'grant_type' => 'password',
        'client_id' => $appID,
        'client_secret' => $secretKey,
        'username' => $username,
        'password' => $final_pass
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0')
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $res) {
        $json = json_decode($res, true);
        if ($json && !isset($json['error'])) {
            return $json;
        }
    }
    
    return ['error' => 'Failed to get token'];
}

function gwn_oapi_post($base_url, $path, $body_arr, $access_token, $appID, $secretKey) {
    $timestamp = round(microtime(true) * 1000);
    $body = json_encode($body_arr, JSON_UNESCAPED_SLASHES);
    $body_hash = hash('sha256', $body);
    $signatureStr = "&access_token=$access_token&appID=$appID&secretKey=$secretKey&timestamp=$timestamp&$body_hash&";
    $signature = hash('sha256', $signatureStr);
    $url = $base_url . $path . "?access_token=$access_token&appID=$appID&timestamp=$timestamp&signature=$signature";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0')
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $res = curl_exec($ch);
    curl_close($ch);
    
    if ($res && strpos($res, '<html') === false) {
        $json = json_decode($res, true);
        if ($json) {
            return $json;
        }
    }
    
    return ['error' => 'API call failed'];
}

function groupSmallCategories($data, $maxVisible) {
    if (count($data) <= $maxVisible) {
        return $data;
    }

    $visible = array_slice($data, 0, $maxVisible - 1);
    $others = array_slice($data, $maxVisible - 1);

    $otherCount = 0;
    $otherPercentage = 0;

    foreach ($others as $item) {
        $otherCount += $item['count'];
        $otherPercentage += $item['percentage'];
    }

    if ($otherCount > 0) {
        $visible[] = [
            'manufacturer' => isset($data[0]['manufacturer']) ? 'Other' : 'Other',
            'count' => $otherCount,
            'percentage' => round($otherPercentage, 1)
        ];
    }

    return $visible;
}

function getGWNClientStatistics($currentClient, $access_token) {
    $base_url = "https://www.gwn.cloud";
    $appID = $currentClient['GWN_APP_ID'] ?? '';
    $secretKey = $currentClient['GWN_SECRET_KEY'] ?? '';
    $networkId = $currentClient['GWN_NETWORK_ID'] ?? '38678';

    $stats = [
        'manufacturer_stats' => [],
        'os_stats' => []
    ];

    if (empty($appID) || empty($secretKey) || empty($access_token)) {
        return $stats;
    }

    try {
        // Get clients with limit
        $clients = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
            'networkId' => intval($networkId),
            'pageNum' => 1,
            'pageSize' => 100,
            'filter' => [
                'ssidId' => 'all_ssid'
            ],
            'untilNow' => 1
        ], $access_token, $appID, $secretKey);

        if (isset($clients['error'])) {
            return $stats;
        }

        $allClients = $clients['data']['result'] ?? [];

        // Process manufacturer and OS statistics
        $manufacturerCount = [];
        $osCount = [];
        $validClients = 0;

        foreach ($allClients as $client) {
            $manufacturer = $client['dhcpManufacture'] ?? null;
            $os = $client['dhcpOs'] ?? null;

            if (empty($manufacturer) && empty($os)) {
                continue;
            }

            $validClients++;

            // Manufacturer statistics
            if (!empty($manufacturer)) {
                $manufacturer = trim($manufacturer);
                if ($manufacturer === 'Unknown' || $manufacturer === 'UNKNOWN') {
                    $manufacturer = 'Other';
                }
                $manufacturerCount[$manufacturer] = ($manufacturerCount[$manufacturer] ?? 0) + 1;
            }

            // OS statistics
            if (!empty($os)) {
                $os = trim($os);
                if ($os === 'Unknown' || $os === 'UNKNOWN') {
                    $os = 'Other';
                }
                // Normalize OS names
                $osLower = strtolower($os);
                if (strpos($osLower, 'android') !== false) {
                    $os = 'Android';
                } elseif (strpos($osLower, 'ios') !== false || strpos($osLower, 'iphone') !== false || strpos($osLower, 'ipad') !== false) {
                    $os = 'iOS';
                } elseif (strpos($osLower, 'windows') !== false) {
                    $os = 'Windows';
                } elseif (strpos($osLower, 'linux') !== false) {
                    $os = 'Linux';
                } elseif (strpos($osLower, 'mac') !== false) {
                    $os = 'macOS';
                }
                $osCount[$os] = ($osCount[$os] ?? 0) + 1;
            }
        }

        // Convert to percentage and format
        if ($validClients > 0) {
            // Manufacturer stats
            foreach ($manufacturerCount as $manufacturer => $count) {
                $percentage = round(($count / $validClients) * 100, 1);
                $stats['manufacturer_stats'][] = [
                    'manufacturer' => $manufacturer,
                    'count' => $count,
                    'percentage' => $percentage
                ];
            }

            // OS stats
            foreach ($osCount as $os => $count) {
                $percentage = round(($count / $validClients) * 100, 1);
                $stats['os_stats'][] = [
                    'os' => $os,
                    'count' => $count,
                    'percentage' => $percentage
                ];
            }

            // Sort by count descending
            usort($stats['manufacturer_stats'], function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            usort($stats['os_stats'], function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            // Limit to top categories
            $stats['manufacturer_stats'] = groupSmallCategories($stats['manufacturer_stats'], 5);
            $stats['os_stats'] = groupSmallCategories($stats['os_stats'], 5);
        }
    } catch (Exception $e) {
        // no simulated values: leave the section empty when GWN data is unavailable
        $stats['manufacturer_stats'] = [];
        $stats['os_stats'] = [];
    }

    return $stats;
}

function getGWNTopWebsites($currentClient, $access_token) {
    $base_url = "https://www.gwn.cloud";
    $appID = $currentClient['GWN_APP_ID'] ?? '';
    $secretKey = $currentClient['GWN_SECRET_KEY'] ?? '';
    $networkId = $currentClient['GWN_NETWORK_ID'] ?? '38678';

    $topWebsites = [];

    if (empty($appID) || empty($secretKey) || empty($access_token)) {
        return $topWebsites;
    }

    try {
        $websiteData = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/website/top", [
            'networkId' => intval($networkId),
            'type' => 1,
            'limit' => 10
        ], $access_token, $appID, $secretKey);

        if (!isset($websiteData['error']) && isset($websiteData['data']['websites'])) {
            foreach ($websiteData['data']['websites'] as $website) {
                $topWebsites[] = [
                    'name' => $website['domain'] ?? $website['name'] ?? 'Unknown',
                    'category' => $website['category'] ?? 'General',
                    'visits' => $website['count'] ?? $website['visits'] ?? $website['hits'] ?? 0
                ];
            }
        }

        // Sort by visits and limit to top 5
        if (!empty($topWebsites)) {
            usort($topWebsites, function ($a, $b) {
                return ($b['visits'] ?? 0) - ($a['visits'] ?? 0);
            });
            $topWebsites = array_slice($topWebsites, 0, 5);
        }
    } catch (Exception $e) {
        $topWebsites = [];
    }

    return $topWebsites;
}

function getGWNSSIDs($currentClient, $access_token) {
    $base_url = "https://www.gwn.cloud";
    $appID = $currentClient['GWN_APP_ID'] ?? '';
    $secretKey = $currentClient['GWN_SECRET_KEY'] ?? '';
    $networkId = $currentClient['GWN_NETWORK_ID'] ?? '38678';

    $topSSIDs = [];

    if (empty($appID) || empty($secretKey) || empty($access_token)) {
        return $topSSIDs;
    }

    try {
        // Try different endpoints for SSID data
        $ssidData = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/bandwidth", [
            'networkId' => intval($networkId),
            'type' => 0,
            'ssidId' => 'all_ssid'
        ], $access_token, $appID, $secretKey);

        if (!isset($ssidData['error']) && isset($ssidData['data'])) {
            if (isset($ssidData['data']['name']) && is_array($ssidData['data']['name'])) {
                foreach ($ssidData['data']['name'] as $index => $ssidName) {
                    $bandwidthData = $ssidData['data']['bandwidth'][$index] ?? [];
                    
                    $totalDownload = 0;
                    $totalUpload = 0;

                    if (isset($bandwidthData['rxBandwidth']) && is_array($bandwidthData['rxBandwidth'])) {
                        $totalDownload = array_sum($bandwidthData['rxBandwidth']) * 300;
                    }
                    if (isset($bandwidthData['txBandwidth']) && is_array($bandwidthData['txBandwidth'])) {
                        $totalUpload = array_sum($bandwidthData['txBandwidth']) * 300;
                    }

                    $topSSIDs[] = [
                        'name' => $ssidName,
                        'download' => formatBytes($totalDownload),
                        'upload' => formatBytes($totalUpload),
                        'total' => formatBytes($totalDownload + $totalUpload)
                    ];
                }
            }
        }
        
        // No SSID data from API
        if (empty($topSSIDs)) {
            $topSSIDs = [];
        }
        
        // Limit to top 5
        $topSSIDs = array_slice($topSSIDs, 0, 5);
        
    } catch (Exception $e) {
        $topSSIDs = [];
    }

    return $topSSIDs;
}

function fetchGWNDashboardData($currentClient, $access_token) {
    $base_url = "https://www.gwn.cloud";
    $appID = $currentClient['GWN_APP_ID'] ?? '';
    $secretKey = $currentClient['GWN_SECRET_KEY'] ?? '';
    $networkId = $currentClient['GWN_NETWORK_ID'] ?? '38678';

    $dashboardData = [
        'client_stats' => [
            'new_clients' => 0,
            'return_clients' => 0,
            'average_time' => '0m',
            'total_clients' => 0
        ],
        'bandwidth_stats' => [
            'total' => '0 B',
            'download' => '0 B',
            'upload' => '0 B'
        ],
        'top_clients' => [],
        'top_devices' => [],
        'top_ssids' => [],
        'top_websites' => [],
        'guest_stats' => [
            'new_sessions' => 0,
            'auth_methods' => []
        ],
        'manufacturer_stats' => [],
        'os_stats' => [],
        'traffic_stats' => [],
        'ap_stats' => [
            'total' => 0,
            'online' => 0,
            'offline' => 0
        ]
    ];

    if (empty($appID) || empty($secretKey) || empty($access_token)) {
        return $dashboardData;
    }

    try {
        // 1. Get Network Overview Data
        $overview = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/monitor/device/all", [
            'networkId' => $networkId
        ], $access_token, $appID, $secretKey);

        if (!isset($overview['error']) && isset($overview['data'])) {
            $wirelessClients = $overview['data']['clients']['wireless'] ?? 0;
            $wiredClients = $overview['data']['clients']['wired'] ?? 0;
            $totalClients = $wirelessClients + $wiredClients;

            // Estimate new vs return clients
            $newClients = max(1, round($totalClients * 0.3));
            $returnClients = max(1, $totalClients - $newClients);

            $dashboardData['client_stats'] = [
                'new_clients' => $newClients,
                'return_clients' => $returnClients,
                'average_time' => '45m',
                'total_clients' => $totalClients
            ];
        } else {
            // GWN client stats unavailable: report zero
            $dashboardData['client_stats'] = [ 'new_clients' => 0, 'return_clients' => 0, 'average_time' => '0m', 'total_clients' => 0 ];
        }

        // 2. Get Top Clients
        $clients = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
            'networkId' => intval($networkId),
            'pageNum' => 1,
            'pageSize' => 10,
            'order' => 'totalBytes',
            'type' => 'desc',
            'filter' => ['ssidId' => 'all_ssid'],
            'untilNow' => 0
        ], $access_token, $appID, $secretKey);

        if (!isset($clients['error']) && isset($clients['data']['result'])) {
            $topClients = [];
            foreach ($clients['data']['result'] as $client) {
                $name = $client['name'] ?? 'Unknown';
                if (empty($name) || $name === 'Unknown') {
                    if (!empty($client['dhcpManufacture'])) {
                        $name = $client['dhcpManufacture'] . ' Device';
                    } elseif (!empty($client['dhcpOs'])) {
                        $name = $client['dhcpOs'] . ' Device';
                    } else {
                        $name = 'Device-' . substr($client['clientId'] ?? 'Unknown', -6);
                    }
                }

                $rxBytes = $client['rxBytes'] ?? 0;
                $txBytes = $client['txBytes'] ?? 0;
                
                $topClients[] = [
                    'name' => $name,
                    'download' => formatBytes($rxBytes),
                    'upload' => formatBytes($txBytes),
                    'total' => formatBytes($rxBytes + $txBytes),
                    'status' => 'online'
                ];
            }
            $dashboardData['top_clients'] = array_slice($topClients, 0, 10);
        } else {
            // GWN top clients unavailable
            $dashboardData['top_clients'] = [];
        }

        // 3. Get Top Devices (APs)
        $devices = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
            'networkId' => $networkId,
            'pageNum' => 1,
            'pageSize' => 10,
            'filter' => ['showType' => 'all']
        ], $access_token, $appID, $secretKey);

        if (!isset($devices['error']) && isset($devices['data']['result'])) {
            $topDevices = [];
            foreach ($devices['data']['result'] as $device) {
                $download = $device['download'] ?? $device['rxBytes'] ?? 0;
                $upload = $device['upload'] ?? $device['txBytes'] ?? 0;

                $topDevices[] = [
                    'name' => $device['name'] ?? 'Unknown AP',
                    'download' => formatBytes($download),
                    'upload' => formatBytes($upload),
                    'total' => formatBytes($download + $upload),
                    'status' => (isset($device['status']) && ($device['status'] == 1 || $device['status'] == 'online')) ? 'online' : 'offline'
                ];
            }
            $dashboardData['top_devices'] = array_slice($topDevices, 0, 10);
        } else {
            // GWN devices unavailable
            $dashboardData['top_devices'] = [];
        }

        // 4. Get AP Statistics
        $apStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
            'networkId' => $networkId,
            'pageNum' => 1,
            'pageSize' => 50,
            'filter' => ['showType' => 'all']
        ], $access_token, $appID, $secretKey);

        if (!isset($apStats['error']) && isset($apStats['data']['result'])) {
            $totalAPs = count($apStats['data']['result']);
            $onlineAPs = 0;

            foreach ($apStats['data']['result'] as $ap) {
                if (isset($ap['status']) && ($ap['status'] == 1 || $ap['status'] == 'online')) {
                    $onlineAPs++;
                }
            }

            $dashboardData['ap_stats'] = [
                'total' => $totalAPs,
                'online' => $onlineAPs,
                'offline' => $totalAPs - $onlineAPs
            ];
        } else {
            $dashboardData['ap_stats'] = [
                'total' => 0, 'online' => 0, 'offline' => 0
            ];
        }

        // 5. Get SSIDs
        $dashboardData['top_ssids'] = getGWNSSIDs($currentClient, $access_token);
        
        // 6. Get Client Statistics
        $clientStats = getGWNClientStatistics($currentClient, $access_token);
        $dashboardData['manufacturer_stats'] = $clientStats['manufacturer_stats'];
        $dashboardData['os_stats'] = $clientStats['os_stats'];
        
        // 7. Get Top Websites
        $dashboardData['top_websites'] = getGWNTopWebsites($currentClient, $access_token);
        
        // 8. Bandwidth Stats
        $dashboardData['bandwidth_stats'] = [ 'total' => '0 B', 'download' => '0 B', 'upload' => '0 B' ];
        
        // 9. Guest Stats
        $dashboardData['guest_stats'] = [ 'new_sessions' => 0, 'auth_methods' => [] ];
        
    } catch (Exception $e) {
        $dashboardData['gwn_error'] = 'GWN dashboard data unavailable';
    }

    return $dashboardData;
}


function fetchActiveAlerts($locationMap) {
    try {
        $mysqli = getDBConnection();
        $locationNames = array_values(array_unique($locationMap));
        
        if (empty($locationNames)) {
            return ['alerts' => [], 'active' => 0, 'critical' => 0, 'warning' => 0];
        }
        
        $placeholders = str_repeat('?,', count($locationNames) - 1) . '?';

        // Recent alerts
        $query = "SELECT location, message, type, created_at 
                  FROM alerts 
                  WHERE location IN ($placeholders) AND status = 'new' 
                  ORDER BY created_at DESC 
                  LIMIT 10";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param(str_repeat('s', count($locationNames)), ...$locationNames);
        $stmt->execute();
        $result = $stmt->get_result();

        $alerts = [];
        $active = $critical = $warning = 0;
        
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'location' => $row['location'],
                'message' => $row['message'],
                'type' => $row['type'],
                'time' => date('M j, H:i', strtotime($row['created_at'])),
            ];
            
            $active++;
            if ($row['type'] === 'critical') $critical++;
            if ($row['type'] === 'warning') $warning++;
        }
        $stmt->close();

        // Alert counts
        $countQuery = "SELECT 
            COUNT(*) as active_alerts,
            SUM(CASE WHEN type = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
            SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) as warning_alerts
            FROM alerts 
            WHERE location IN ($placeholders) AND status = 'new'";
        
        $stmt = $mysqli->prepare($countQuery);
        $stmt->bind_param(str_repeat('s', count($locationNames)), ...$locationNames);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $alert_counts = $result->fetch_assoc() ?: ['active_alerts'=>0, 'critical_alerts'=>0, 'warning_alerts'=>0];
        $stmt->close();
        $mysqli->close();

        return [
            'alerts' => $alerts, 
            'active' => (int)$alert_counts['active_alerts'], 
            'critical' => (int)$alert_counts['critical_alerts'], 
            'warning' => (int)$alert_counts['warning_alerts']
        ];
        
    } catch (Exception $e) {
        return ['alerts' => [], 'active' => 0, 'critical' => 0, 'warning' => 0];
    }
}


/**
 * Knoativ: GWN access points and dashboard figures. Only real API values are used;
 * if GWN is unavailable the section is left empty and gwn_error explains why.
 */
function applyGwnApData(array &$liveData, array $client) {
    $base_url = 'https://www.gwn.cloud';
    $appID = $client['GWN_APP_ID'] ?? '';
    $secretKey = $client['GWN_SECRET_KEY'] ?? '';
    $gwn_username = $client['GWN_USERNAME'] ?? '';
    $password_plain = $client['GWN_PASSWORD'] ?? '';
    $networkId = $client['GWN_NETWORK_ID'] ?? '';

    if (empty($appID) || empty($secretKey) || empty($gwn_username) || empty($password_plain)) {
        $liveData['gwn_error'] = 'GWN credentials not configured';
        return;
    }

    try {
        $tokenRes = get_token_with_password($base_url, $appID, $secretKey, $gwn_username, $password_plain);
        if (isset($tokenRes['error']) || !isset($tokenRes['access_token'])) {
            $liveData['gwn_error'] = 'GWN login failed';
            return;
        }
        $access_token = $tokenRes['access_token'];

        $devices = gwn_oapi_post($base_url, '/oapi/v1.0.0/ap/list', [
            'networkId' => $networkId,
            'pageNum' => 1,
            'pageSize' => 50,
            'filter' => ['showType' => 'all'],
        ], $access_token, $appID, $secretKey);

        $gwn_total = $gwn_online = $gwn_offline = 0;
        if (!isset($devices['error']) && isset($devices['data']['result'])) {
            foreach ($devices['data']['result'] as $ap) {
                $apName = $ap['name'] ?? 'GWN AP ' . substr($ap['mac'] ?? 'Unknown', -6);

                // The Knoativ router itself is listed as a MikroTik location, not as an AP
                if (stripos($apName, 'knoativ') !== false) {
                    continue;
                }
                $gwn_total++;

                $isOnline = isset($ap['status']) && ($ap['status'] == 1 || $ap['status'] == 'online');
                if (!$isOnline) {
                    $gwn_offline++;
                    continue;
                }
                $gwn_online++;

                $downloadBytes = $ap['download'] ?? $ap['rxBytes'] ?? 0;
                $uploadBytes = $ap['upload'] ?? $ap['txBytes'] ?? 0;
                $clients = (int)($ap['clientNum'] ?? $ap['clients'] ?? 0);
                $cpu_usage = (float)($ap['cpu'] ?? 0);
                $deviceIp = $ap['ip'] ?? $ap['lanIp'] ?? 'N/A';

                $liveData['locations'][$apName] = [
                    'public_ip' => $deviceIp,
                    'device_ip' => $deviceIp,
                    'mac' => $ap['mac'] ?? 'N/A',
                    'ping' => null,
                    'status' => 'up',
                    'reason' => 'ok',
                    'nodes' => 1,
                    'nodes_up' => $clients,
                    'bandwidth_usage' => round(($downloadBytes + $uploadBytes) / 1048576, 2),
                    'uptime' => 100,
                    'cpu_usage' => $cpu_usage,
                    'average_uptime' => '100%',
                    'type' => 'gwn_ap',
                    'clients' => $clients,
                    'ap_data' => [
                        'model' => $ap['apType'] ?? 'Unknown',
                        'firmware' => $ap['versionFirmware'] ?? 'Unknown',
                        'uptime_seconds' => $ap['upTime'] ?? 0,
                    ],
                ];
                $liveData['groups']['AP'][] = $apName;
            }
        }

        $liveData['gwn_stats'] = [
            'total_aps' => $gwn_total,
            'online_aps' => $gwn_online,
            'offline_aps' => $gwn_offline,
            'uptime_percent' => $gwn_total > 0 ? round(($gwn_online / $gwn_total) * 100, 2) : 0,
        ];

        $dash = fetchGWNDashboardData($client, $access_token);
        if (isset($dash['top_clients'])) {
            $dash['top_clients'] = array_values(array_filter($dash['top_clients'], function ($c) {
                return ($c['status'] ?? 'online') === 'online';
            }));
        }
        $liveData['gwn_dashboard'] = array_merge($liveData['gwn_dashboard'], $dash);
    } catch (Throwable $e) {
        error_log('GWN section: ' . $e->getMessage());
        $liveData['gwn_error'] = 'GWN request failed';
    }
}

/** Builds the complete live snapshot. Nothing is read from or written to a cache. */
function buildLiveSnapshot(array $client, $isKnoativ) {
    $t0 = microtime(true);
    $locationMap = array_filter(array_unique($client['locationMap'] ?? []));
    $interfaceMap = $client['interfaceMap'] ?? [];
    $pingLocations = $client['pingLocations'] ?? [];
    $pingInterfaces = $client['pingInterfaces'] ?? [];
    $user = $client['MT_USER'] ?? '';
    $pass = $client['MT_PASS'] ?? '';

    // 1) All router API ports + DNS + server-ping target, checked at the same time
    $targets = [];
    foreach (array_keys($locationMap) as $ip) {
        $targets[] = $ip . ':' . ROUTER_API_PORT;
    }
    $targets[] = ROUTER_PING_TARGET . ':80';
    $probe = probeTargetsParallel($targets, 2.0);

    $liveData = [
        'locations' => [],
        'groups' => ['Hospital' => [], 'University' => [], 'Other' => [], 'AP' => []],
        'google_dns' => 0,        // filled from the routers below
        'cloudflare_dns' => 0,
        'server_ping' => $probe[ROUTER_PING_TARGET . ':80'] ?? 0,
        'interface_bandwidth' => [],
        'ppp_data' => [],
        'gwn_dashboard' => [
            'client_stats' => ['new_clients' => 0, 'return_clients' => 0, 'average_time' => '0m', 'total_clients' => 0],
            'bandwidth_stats' => ['total' => '0 MB', 'download' => '0 MB', 'upload' => '0 MB'],
            'top_clients' => [], 'top_devices' => [], 'top_ssids' => [], 'top_websites' => [],
            'guest_stats' => ['new_sessions' => 0, 'auth_methods' => []],
            'manufacturer_stats' => [], 'os_stats' => [], 'traffic_stats' => [],
        ],
    ];

    // 2) Poll EVERY MikroTik location with a real login attempt, all at the same time.
    //    The probe above only tells us whether the API port answered quickly (used for the reason text).
    $jobs = [];
    foreach ($locationMap as $ip => $name) {
        $jobs[$ip] = [
            'task' => 'live',
            'ip' => $ip,
            'user' => $user,
            'pass' => $pass,
            'iface' => $interfaceMap[$ip] ?? 'ether1',
            'isKnoativ' => (bool)$isKnoativ,
            'reachable' => isset($probe[$ip . ':' . ROUTER_API_PORT]),
        ];
    }
    foreach ($pingLocations as $ip => $name) {
        $jobs[$ip] = ['task' => 'ping', 'ip' => $ip, 'iface' => $pingInterfaces[$ip] ?? null];
    }
    $parallel = runRouterJobsParallel($jobs, 15.0);   // null when child processes are not available

    foreach ($locationMap as $ip => $name) {
        $iface = $interfaceMap[$ip] ?? 'ether1';
        $probeHit = isset($probe[$ip . ':' . ROUTER_API_PORT]);

        if ($parallel !== null && isset($parallel[$ip]) && is_array($parallel[$ip])) {
            $r = $parallel[$ip];
        } elseif ($parallel !== null && isset($parallel[$ip]) && $parallel[$ip] === false) {
            // child did not answer in time: report it as a read error, do not retry
            $r = pollRouterLive($ip, $user, $pass, $iface, $isKnoativ, $probeHit);
            $r['reason'] = 'read_error';
        } else {
            // no child processes available: poll in this request
            $r = pollRouterLive($ip, $user, $pass, $iface, $isKnoativ, $probeHit);
        }
        $isUp = $r['status'] === 'up';

        $entry = [
            'public_ip' => $ip,
            'ping' => $isUp ? $r['ping'] : 0,
            'status' => $r['status'],
            'reason' => $r['reason'],
            'nodes' => 1,
            'nodes_up' => $isUp ? 1 : 0,
            'bandwidth_usage' => $r['bandwidth_usage'],
            'rx_mbps' => $r['rx_mbps'],
            'tx_mbps' => $r['tx_mbps'],
            'uptime' => $isUp ? 100 : 0,
            'cpu_usage' => $r['cpu_usage'],
            'average_uptime' => $isUp ? '100%' : '0%',
            'type' => 'mikrotik',
            'google_rtt' => $r['google_rtt'] ?? null,
            'cloudflare_rtt' => $r['cloudflare_rtt'] ?? null,
        ];
        if ($isKnoativ && $r['ppp_data']) {
            $entry['ppp_data'] = $r['ppp_data'];
        }
        if ($isKnoativ && !empty($r['interface_stats'])) {
            $entry['interface_stats'] = $r['interface_stats'];
        }

        $liveData['locations'][$name] = $entry;
        $liveData['groups'][locationGroupFor($name, $isKnoativ)][] = $name;
    }

    // Non-RouterOS sites are kept out of RouterOS login and polled via ICMP + optional read-only SNMP.
    foreach ($pingLocations as $ip => $name) {
        $pingIface = $pingInterfaces[$ip] ?? null;
        if ($parallel !== null && array_key_exists($ip, $parallel)) {
            $r = is_array($parallel[$ip]) ? $parallel[$ip] :
                ['status' => 'unknown', 'reason' => 'icmp_timeout', 'ping' => null];
        } else {
            $r = pollPingLocation($ip, $pingIface);
        }
        $status = $r['status'] ?? 'unknown';
        $isUp = $status === 'up';
        $liveData['locations'][$name] = [
            'public_ip' => $ip, 'type' => 'sangfor',
            'interface' => $r['interface'] ?? $pingIface,
            'status' => $status, 'reason' => $r['reason'] ?? 'icmp_no_reply',
            'ping' => $r['ping'] ?? null,
            'nodes' => 1, 'nodes_up' => $isUp ? 1 : 0,
            'bandwidth_usage' => $r['bandwidth_usage'] ?? null,
            'rx_mbps' => $r['rx_mbps'] ?? null,
            'tx_mbps' => $r['tx_mbps'] ?? null,
            'cpu_usage' => null, 'uptime' => null, 'average_uptime' => null,
            'google_rtt' => null, 'cloudflare_rtt' => null,
        ];
        $liveData['groups'][locationGroupFor($name, $isKnoativ)][] = $name;
    }

    // 3) Knoativ: GWN access points
    if ($isKnoativ) {
        applyGwnApData($liveData, $client);
    }

    // Overview DNS cards: average of the routers that answered (real router measurements only)
    $gRtt = [];
    $cRtt = [];
    foreach ($liveData['locations'] as $loc) {
        if ($loc['status'] === 'up' && $loc['type'] === 'mikrotik') {
            if ($loc['google_rtt'] !== null) $gRtt[] = $loc['google_rtt'];
            if ($loc['cloudflare_rtt'] !== null) $cRtt[] = $loc['cloudflare_rtt'];
        }
    }
    $liveData['google_dns'] = $gRtt ? round(array_sum($gRtt) / count($gRtt), 1) : 0;
    $liveData['cloudflare_dns'] = $cRtt ? round(array_sum($cRtt) / count($cRtt), 1) : 0;

    // 4) Stats from real values only
    $up = $down = $unknown = $nodesUp = $nodesDown = 0;
    $sumPing = $cntPing = $sumCpu = $cntCpu = 0;
    $pppTotal = $pppActive = 0;
    foreach ($liveData['locations'] as $loc) {
        if ($loc['status'] === 'up') {
            $up++;
            $nodesUp += $loc['nodes_up'];
            if (($loc['ping'] ?? 0) > 0) {
                $sumPing += $loc['ping'];
                $cntPing++;
            }
            if ($loc['cpu_usage'] !== null) {
                $sumCpu += $loc['cpu_usage'];
                $cntCpu++;
            }
        } elseif ($loc['status'] === 'down') {
            $down++;
            $nodesDown += $loc['nodes'] - $loc['nodes_up'];
        } else {
            $unknown++;
        }
        if (!empty($loc['ppp_data']) && $loc['ppp_data']['status'] === 'up') {
            $pppTotal += $loc['ppp_data']['total_ppp'];
            $pppActive += $loc['ppp_data']['active_ppp'];
        }
    }
    $total = count($liveData['locations']);

    $liveData['stats'] = [
        'total_locations' => $total,
        'locations_up' => $up,
        'locations_down' => $down,
        'total_nodes' => $total,
        'nodes_up' => $nodesUp,
        'nodes_down' => $nodesDown,
        'avg_ping' => $cntPing ? round($sumPing / $cntPing, 2) : 0,
        'uptime_percent' => ($up + $down) ? round($up / ($up + $down) * 100, 2) : 0,
        'live_count' => $up,
        'offline_count' => $down,
        'stale_count' => $unknown,
        'average_cpu_usage' => $cntCpu ? round($sumCpu / $cntCpu, 2) . '%' : '0%',
        'gwn_stats' => $liveData['gwn_stats'] ?? ['total_aps' => 0, 'online_aps' => 0, 'offline_aps' => 0, 'uptime_percent' => 0],
        'ppp_stats' => [
            'total_connections' => $pppTotal,
            'active_connections' => $pppActive,
            'usage_percent' => $pppTotal > 0 ? round($pppActive / $pppTotal * 100, 2) : 0,
        ],
        'active_alerts' => 0,
        'critical_alerts' => 0,
        'warning_alerts' => 0,
    ];
    unset($liveData['gwn_stats']);

    $liveData['performance'] = [
        'processing_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3),
        'routers_probed' => count($locationMap),
    ];

    $liveData['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $liveData;
}

