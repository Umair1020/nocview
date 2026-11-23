<?php
// File: /public_html/gwn_dashboard.php
// Complete GWN Dashboard Integration
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add CORS headers and proper JSON response
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow Knoativ user to access GWN data
session_start();
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'Knoativ') {
    echo json_encode(['success' => false, 'error' => 'Access denied. Knoativ user only.']);
    exit;
}

require_once(__DIR__ . '/config.php');
$currentClient = getCurrentClient();

$base_url = $currentClient['GWN_BASE_URL'] ?? "https://www.gwn.cloud";
$appID = $currentClient['GWN_APP_ID'] ?? "667380";
$secretKey = $currentClient['GWN_SECRET_KEY'] ?? "IkSX55UXZpx4eDEbDtbn64dUYx0ubNs4";
$username = $currentClient['GWN_USERNAME'] ?? "ccwificloud";
$password_plain = $currentClient['GWN_PASSWORD'] ?? "compaq123_";
$networkId = $currentClient['GWN_NETWORK_ID'] ?? "38678";

// Helper function to get authentication token
function get_token_with_password($base_url, $appID, $secretKey, $username, $password_plain)
{
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
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'cURL error', 'details' => $err, 'http_code' => $http_code];
    }

    $json = json_decode($res, true);
    if (!$json) {
        return [
            'error' => 'Invalid JSON response',
            'raw_response' => substr($res, 0, 500),
            'http_code' => $http_code
        ];
    }

    if (isset($json['error'])) {
        return ['error' => 'API error', 'details' => $json, 'http_code' => $http_code];
    }

    return $json;
}

// OAPI POST with signature
function gwn_oapi_post($base_url, $path, $body_arr, $access_token, $appID, $secretKey)
{
    $timestamp = round(microtime(true) * 1000);
    $body = json_encode($body_arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'cURL error', 'details' => $err, 'http_code' => $http_code];
    }

    // Check if response is HTML (error page)
    if (strpos($res, '<!DOCTYPE') !== false || strpos($res, '<html') !== false) {
        return [
            'error' => 'HTML response received (likely error page)',
            'http_code' => $http_code,
            'raw_response' => substr($res, 0, 500)
        ];
    }

    $json = json_decode($res, true);
    if (!$json) {
        return [
            'error' => 'Invalid JSON response',
            'raw_response' => substr($res, 0, 500),
            'http_code' => $http_code
        ];
    }

    return $json;
}

// OAPI GET with signature
function gwn_oapi_get($base_url, $path, $access_token, $appID, $secretKey)
{
    $timestamp = round(microtime(true) * 1000);
    $signatureStr = "&access_token=$access_token&appID=$appID&secretKey=$secretKey&timestamp=$timestamp&&";
    $signature = hash('sha256', $signatureStr);

    $url = $base_url . $path . "?access_token=$access_token&appID=$appID&timestamp=$timestamp&signature=$signature";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'cURL error', 'details' => $err, 'http_code' => $http_code];
    }

    if (strpos($res, '<!DOCTYPE') !== false || strpos($res, '<html') !== false) {
        return [
            'error' => 'HTML response received (likely error page)',
            'http_code' => $http_code,
            'raw_response' => substr($res, 0, 500)
        ];
    }

    $json = json_decode($res, true);
    if (!$json) {
        return [
            'error' => 'Invalid JSON response',
            'raw_response' => substr($res, 0, 500),
            'http_code' => $http_code
        ];
    }

    return $json;
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = [];

    try {
        // Get token
        $tokenRes = get_token_with_password($base_url, $appID, $secretKey, $username, $password_plain);

        if (!isset($tokenRes['access_token'])) {
            $response = [
                'success' => false,
                'error' => 'Failed to get authentication token',
                'token_response' => $tokenRes
            ];
            echo json_encode($response);
            exit;
        }

        $access_token = $tokenRes['access_token'];

        switch ($action) {
            case 'getNetworkList':
                $netRes = gwn_oapi_post($base_url, "/oapi/v1.0.0/network/list", [], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $netRes];
                break;

            case 'getNetworkOverview':
                $networkId = $_POST['networkId'] ?? $networkId;
                $overview = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/monitor/device/all", ['networkId' => $networkId], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $overview];
                break;


// Add this case to your existing switch statement in gwn_dashboard.php
case 'getClientDetailedInfo':
    $clientMac = $_POST['clientMac'] ?? '';
    $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');
    
    $clientInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/info", [
        'networkId' => intval($networkId),
        'clientId' => $clientMac
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $clientInfo];
    break;

            case 'getBandwidthStatistics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $ssidId = $_POST['ssidId'] ?? 'all_ssid';

                // Use the correct endpoint for bandwidth statistics
                $bandwidthStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type),
                    'ssidId' => $ssidId
                ], $access_token, $appID, $secretKey);

                // If that fails, try alternative endpoint
                if (isset($bandwidthStats['error'])) {
                    $bandwidthStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/bandwidth", [
                        'networkId' => intval($networkId),
                        'type' => intval($type),
                        'ssidId' => $ssidId
                    ], $access_token, $appID, $secretKey);
                }

                $response = ['success' => true, 'data' => $bandwidthStats];
                break;

            case 'getAPClientStatistics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $apClientStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/client", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $apClientStats];
                break;
            // File: /public_html/gwn_dashboard.php میں یہ function شامل کریں

            case 'getClientInfo':
                $clientMac = $_POST['clientMac'] ?? '';
                $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');
                $clientInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/info", [
                    'networkId' => intval($networkId),
                    'clientId' => $clientMac
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $clientInfo];
                break;

            case 'getClientHistory':
                $clientMac = $_POST['clientMac'] ?? '';
                $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');
                $pageNum = $_POST['pageNum'] ?? 1;
                $pageSize = $_POST['pageSize'] ?? 50;

                // Try multiple endpoints for client history
                $endpoints = [
                    '/oapi/v1.0.0/client/history/list',
                    '/oapi/v1.0.0/client/history',
                    '/oapi/v1.0.0/statistics/client/history'
                ];

                $clientHistory = null;
                foreach ($endpoints as $endpoint) {
                    $clientHistory = gwn_oapi_post($base_url, $endpoint, [
                        'networkId' => intval($networkId),
                        'clientId' => $clientMac,
                        'pageNum' => intval($pageNum),
                        'pageSize' => intval($pageSize)
                    ], $access_token, $appID, $secretKey);

                    if (!isset($clientHistory['error'])) {
                        break;
                    }
                }

                $response = ['success' => true, 'data' => $clientHistory];
                break;
         // موجودہ switch statement میں یہ case اپ ڈیٹ کریں
case 'getSSIDClientStatistics':
    $networkId = $_POST['networkId'] ?? $networkId;
    $type = $_POST['type'] ?? 1;
    $ssidId = $_POST['ssidId'] ?? 'all_ssid';
    
    $ssidClientStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/client", [
        'networkId' => intval($networkId),
        'type' => intval($type),
        'ssidId' => $ssidId
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $ssidClientStats];
    break;


 // gwn_dashboard.php mein ye case add kar de (kisi bhi switch ke andar)

case 'getTopWebsitesReal':
    $networkId = $_POST['networkId'] ?? $networkId;
    $type      = $_POST['type'] ?? 1;        // 0=2h, 1=24h, 2=week, 3=month
    $limit     = $_POST['limit'] ?? 20;

    // Yeh endpoint hai asli top websites ka (DPI se data)
    $topApps = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/client/application", [
        'networkId' => intval($networkId),
        'type'      => intval($type),
        'top'       => 100                     // max 100 allowed
    ], $access_token, $appID, $secretKey);

    $websites = [];

    if (!isset($topApps['error']) && isset($topApps['data']['application']) && is_array($topApps['data']['application'])) {
        
        foreach ($topApps['data']['application'] as $index => $app) {
            if ($index >= $limit) break;

            $domain = $app['name'] ?? 'Unknown Domain';
            $totalBytes = $app['totalBytes'] ?? ($app['rxBytes'] ?? 0) + ($app['txBytes'] ?? 0);
            $rxBytes = $app['rxBytes'] ?? 0;
            $txBytes = $app['txBytes'] ?? 0;

            $websites[] = [
                'rank'           => $index + 1,
                'domain'         => $domain,
                'total_traffic_mb' => round($totalBytes / 1048576, 2),   // MB mein
                'download_mb'    => round($rxBytes / 1048576, 2),
                'upload_mb'      => round($txBytes / 1048576, 2),
                'sessions'       => $app['count'] ?? 'N/A'             // kuch cases mein count bhi aata hai
            ];
        }
    }

    $response = [
        'success' => true,
        'data'    => [
            'top_websites' => $websites,
            'total_found'  => count($websites),
            'time_range'   => $type,
            'time_label'   => $type == 0 ? 'Last 2 Hours' : 
                              ($type == 1 ? 'Last 24 Hours' : 
                              ($type == 2 ? 'Last Week' : 'Last Month')),
            'raw_response' => $topApps // debug ke liye (baad mein hata dena)
        ]
    ];
    break;

            case 'getSSIDBandwidthStatistics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $ssidBandwidthStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $ssidBandwidthStats];
                break;

            case 'getAPBandwidthStatistics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $apBandwidthStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $apBandwidthStats];
                break;

            case 'getDeviceMonitor':
                $networkId = $_POST['networkId'] ?? $networkId;
                $deviceMonitor = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/monitor/device/all", [
                    'networkId' => intval($networkId)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $deviceMonitor];
                break;

            case 'getBandwidthList':
                $networkId = $_POST['networkId'] ?? $networkId;
                $bandwidthList = gwn_oapi_post($base_url, "/oapi/v1.0.0/bandwidth/list", [
                    'networkId' => intval($networkId)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $bandwidthList];
                break;

            case 'getBandwidthInfo':
                $networkId = $_POST['networkId'] ?? $networkId;
                $bandwidthId = $_POST['bandwidthId'] ?? '';
                $bandwidthInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/bandwidth/info", [
                    'networkId' => intval($networkId),
                    'bandwidthId' => $bandwidthId
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $bandwidthInfo];
                break;


            case 'getClientList':
                $networkId = $_POST['networkId'] ?? $networkId;
                $pageNum = $_POST['pageNum'] ?? 1;
                $pageSize = $_POST['pageSize'] ?? 100;
                $order = $_POST['order'] ?? 'ip';
                $type = $_POST['sortType'] ?? 'desc';
                $search = $_POST['search'] ?? '';
                $filter = $_POST['filter'] ?? ['ssidId' => 'all_ssid'];
                $startDate = $_POST['startDate'] ?? null;
                $endDate = $_POST['endDate'] ?? null;
                $untilNow = $_POST['untilNow'] ?? 0;

                $clientParams = [
                    'networkId' => intval($networkId),
                    'pageNum' => intval($pageNum),
                    'pageSize' => intval($pageSize),
                    'order' => $order,
                    'type' => $type,
                    'search' => $search,
                    'filter' => $filter,
                    'untilNow' => intval($untilNow)
                ];

                if ($startDate) $clientParams['startDate'] = $startDate;
                if ($endDate) $clientParams['endDate'] = $endDate;

                $clients = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", $clientParams, $access_token, $appID, $secretKey);

                // Debug logging
                error_log("Client List API Response: " . json_encode($clients));

                $response = ['success' => true, 'data' => $clients];
                break;
// devices.php کے لیے ڈیوائس کی معلومات حاصل کریں
case 'getDeviceInfo':
    $deviceMac = $_POST['deviceMac'] ?? '';
    $networkId = $_POST['networkId'] ?? $networkId;
    
    error_log("Fetching device info for MAC: $deviceMac, Network: $networkId");
    
    // Step 1: Get device from AP list (contains basic info)
    $apList = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
        'networkId' => $networkId,
        'pageNum' => 1,
        'pageSize' => 100,
        'filter' => ['showType' => 'all']
    ], $access_token, $appID, $secretKey);
    
    $deviceInfo = null;
    $clientsCount = 0;
    
    if (!isset($apList['error']) && isset($apList['data']['result'])) {
        foreach ($apList['data']['result'] as $device) {
            if ($device['mac'] === $deviceMac) {
                $deviceInfo = $device;
                $clientsCount = $device['clients'] ?? $device['clientNum'] ?? 0;
                break;
            }
        }
    }
    
    // Step 2: Get detailed device information using the correct endpoint
    $detailedInfo = ['data' => null];
    
    // Try different endpoints for detailed device info
    $endpoints = [
        "/oapi/v2.0.0/device/info",
        "/oapi/v1.0.0/ap/info",
        "/oapi/v1.0.0/statistics/monitor/device/all"
    ];
    
    foreach ($endpoints as $endpoint) {
        $params = ($endpoint === "/oapi/v2.0.0/device/info" || $endpoint === "/oapi/v1.0.0/ap/info") 
            ? ['mac' => [$deviceMac]]
            : ['networkId' => intval($networkId)];
        
        $detailedInfo = gwn_oapi_post($base_url, $endpoint, $params, $access_token, $appID, $secretKey);
        
        if (!isset($detailedInfo['error'])) {
            error_log("Success with endpoint: $endpoint");
            break;
        }
    }
    
    // Step 3: Get connected clients for this device
    $connectedClients = [];
    $onlineClientsCount = 0;
    
    $clientsResponse = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
        'networkId' => intval($networkId),
        'pageNum' => 1,
        'pageSize' => 200,
        'filter' => [
            'ssidId' => 'all_ssid',
            'apMac' => $deviceMac  // Filter by AP MAC
        ],
        'untilNow' => 1  // Get current connected clients
    ], $access_token, $appID, $secretKey);
    
    if (!isset($clientsResponse['error']) && isset($clientsResponse['data']['result'])) {
        $connectedClients = $clientsResponse['data']['result'];
        $onlineClientsCount = count(array_filter($connectedClients, function($client) {
            // Filter only online/connected clients
            return isset($client['status']) && $client['status'] == 1;
        }));
        
        error_log("Connected clients found: " . count($connectedClients) . ", Online: " . $onlineClientsCount);
    }
    
    // Step 4: Get radio information for this device
    $radioInfo = null;
    $radioResponse = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/client", [
        'networkId' => intval($networkId),
        'type' => 1,  // 24 hours
        'mac' => $deviceMac
    ], $access_token, $appID, $secretKey);
    
    if (!isset($radioResponse['error'])) {
        $radioInfo = $radioResponse['data'] ?? null;
    }
    
    // Step 5: Prepare final response
    $finalClientsCount = max($onlineClientsCount, $clientsCount);
    
    $responseData = [
        'deviceInfo' => $deviceInfo,
        'detailedInfo' => $detailedInfo['data'] ?? null,
        'radioInfo' => $radioInfo,
        'connectedClients' => $connectedClients,
        'clientsCount' => $finalClientsCount,
        'onlineClientsCount' => $onlineClientsCount,
        'deviceMac' => $deviceMac
    ];
    
    // Log for debugging
    error_log("Device info prepared - Clients: $finalClientsCount, Online: $onlineClientsCount");
    
    $response = [
        'success' => true,
        'data' => $responseData,
        'debug' => [
            'deviceFound' => !is_null($deviceInfo),
            'detailedInfoAvailable' => !empty($detailedInfo['data']),
            'connectedClientsCount' => count($connectedClients)
        ]
    ];
    break;
            case 'getClientStatistics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $ssidId = $_POST['ssidId'] ?? 'all_ssid';

                // Use the correct endpoint for client statistics
                $clientStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/client", [
                    'networkId' => intval($networkId),
                    'type' => intval($type),
                    'ssidId' => $ssidId
                ], $access_token, $appID, $secretKey);

                // If that fails, try alternative endpoint
                if (isset($clientStats['error'])) {
                    $clientStats = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/client", [
                        'networkId' => intval($networkId),
                        'type' => intval($type),
                        'ssidId' => $ssidId
                    ], $access_token, $appID, $secretKey);
                }

                $response = ['success' => true, 'data' => $clientStats];
                break;

            case 'getClientInfo':
                $clientMac = $_POST['clientMac'] ?? '';
                $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');

                // First try to get client from the client list
                $clients = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
                    'networkId' => intval($networkId),
                    'pageNum' => 1,
                    'pageSize' => 100,
                    'search' => $clientMac,
                    'filter' => ['ssidId' => 'all_ssid']
                ], $access_token, $appID, $secretKey);

                $clientInfo = null;
                if (!isset($clients['error']) && isset($clients['data']['result'])) {
                    foreach ($clients['data']['result'] as $client) {
                        if (($client['clientId'] ?? '') === $clientMac || ($client['mac'] ?? '') === $clientMac) {
                            $clientInfo = $client;
                            break;
                        }
                    }
                }

                // If not found in list, try direct info endpoint
                if (!$clientInfo) {
                    $clientInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/info", [
                        'networkId' => intval($networkId),
                        'clientId' => $clientMac
                    ], $access_token, $appID, $secretKey);
                }

                $response = ['success' => true, 'data' => $clientInfo];
                break;

            case 'getClientHistory':
                $clientMac = $_POST['clientMac'] ?? '';
                $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');
                $pageNum = $_POST['pageNum'] ?? 1;
                $pageSize = $_POST['pageSize'] ?? 50;

                $clientHistory = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/history", [
                    'networkId' => intval($networkId),
                    'clientId' => $clientMac,
                    'pageNum' => intval($pageNum),
                    'pageSize' => intval($pageSize)
                ], $access_token, $appID, $secretKey);

                $response = ['success' => true, 'data' => $clientHistory];
                break;

            case 'getClientGeoAnalytics':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;
                $geoAnalytics = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/geoanalytics", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $geoAnalytics];
                break;

            case 'setGlobalBypass':
                $clientMac = $_POST['clientMac'] ?? '';
                $bypassResult = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/send/global-bypass", [
                    'clientId' => $clientMac
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $bypassResult];
                break;

            case 'getSSIDList':
                $networkId = $_POST['networkId'] ?? $networkId;
                $ssids = gwn_oapi_post($base_url, "/oapi/v1.0.0/ssid/list", [
                    'networkId' => intval($networkId)
                ], $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $ssids];
                break;

            case 'getAPList':
               $networkId = $_POST['networkId'] ?? '9581';  // default safe ID

    $apList = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
        'networkId' => $networkId,
        'pageNum' => 1,
        'pageSize' => 100,
        'filter' => ['showType' => 'all']
    ], $access_token, $appID, $secretKey);

    $devices = [];
    if (isset($apList['data']['result']) && is_array($apList['data']['result'])) {
        foreach ($apList['data']['result'] as $ap) {
            $devices[] = [
                'mac' => $ap['mac'] ?? '',
                'name' => !empty($ap['name']) ? $ap['name'] : ('AP ' . substr($ap['mac'], -8)),
                'model' => $ap['apType'] ?? 'Unknown',
                'ip' => $ap['ip'] ?? '—',
                'firmware' => $ap['versionFirmware'] ?? '—',
                'uptime' => isset($ap['upTime']) ? formatUptime($ap['upTime']) : '—',
                'download' => formatBytes($ap['download'] ?? 0),
                'upload' => formatBytes($ap['upload'] ?? 0),
                'total' => formatBytes(($ap['download'] ?? 0) + ($ap['upload'] ?? 0)),
                'status' => ($ap['status'] == 1) ? 'Online' : 'Offline',
                'clients' => $ap['clients'] ?? 0
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'devices' => $devices,
        'raw' => $apList  // debug ke liye
    ]);
    exit;

// gwn_dashboard.php میں یہ case اپ ڈیٹ کریں
case 'getClientHistory':
    $clientMac = $_POST['clientMac'] ?? '';
    $networkId = $_POST['networkId'] ?? ($currentClient['GWN_NETWORK_ID'] ?? '38678');
    $pageNum = $_POST['pageNum'] ?? 1;
    $pageSize = $_POST['pageSize'] ?? 50;

    // MAC کو صاف کریں
    $clientMac = strtoupper(str_replace(':', '', $clientMac));
    
    // صحیح endpoint استعمال کریں
    $clientHistory = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/history/list", [
        'networkId' => intval($networkId),
        'clientId' => $clientMac,
        'pageNum' => intval($pageNum),
        'pageSize' => intval($pageSize),
        'search' => $clientMac,
        'order' => 'lastSeen',
        'type' => 'desc'
    ], $access_token, $appID, $secretKey);

    $response = ['success' => true, 'data' => $clientHistory];
    break;
  // یہ function آپ کے existing switch statement میں شامل کریں
case 'getDeviceList':
    $networkId = $_POST['networkId'] ?? $networkId;
    $pageNum = $_POST['pageNum'] ?? 1;
    $pageSize = $_POST['pageSize'] ?? 100;
    $filter = $_POST['filter'] ?? ['showType' => 'all'];
    
    $devices = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
        'networkId' => $networkId,
        'pageNum' => intval($pageNum),
        'pageSize' => intval($pageSize),
        'filter' => $filter
    ], $access_token, $appID, $secretKey);
    
    // Debug logging
    error_log("Device List API Response: " . json_encode($devices));
    
    $response = ['success' => true, 'data' => $devices];
    break;

case 'getDeviceInfo':
    $deviceMac = $_POST['deviceMac'] ?? '';
    $networkId = $_POST['networkId'] ?? $networkId;
    
    // پہلے v1.0.0/device/info endpoint try کریں (complete info کے لیے)
    $deviceInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/device/info", [
        'networkId' => intval($networkId),
        'mac' => $deviceMac
    ], $access_token, $appID, $secretKey);
    
    // اگر device/info کام نہ کرے تو ap/info try کریں
    if (isset($deviceInfo['error'])) {
        $deviceInfo = gwn_oapi_post($base_url, "/oapi/v2.0.0/ap/info", [
            'mac' => [$deviceMac]
        ], $access_token, $appID, $secretKey);
    }
    
    // اگر پھر بھی کام نہ کرے تو v1.0.0/ap/info try کریں
    if (isset($deviceInfo['error'])) {
        $deviceInfo = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/info", [
            'mac' => [$deviceMac]
        ], $access_token, $appID, $secretKey);
    }
    
    error_log("Device Info API Response for $deviceMac: " . json_encode($deviceInfo));
    
    $response = ['success' => true, 'data' => $deviceInfo];
    break;

case 'getDeviceClients':
    $networkId = $_POST['networkId'] ?? $networkId;
    $deviceMac = $_POST['deviceMac'] ?? '';
    
    // Get clients connected to specific device
    $clients = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
        'networkId' => intval($networkId),
        'pageNum' => 1,
        'pageSize' => 100,
        'filter' => ['apMac' => $deviceMac, 'ssidId' => 'all_ssid']
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $clients];
    break;

case 'getDeviceBandwidth':
    $networkId = $_POST['networkId'] ?? $networkId;
    $deviceMac = $_POST['deviceMac'] ?? '';
    $type = $_POST['type'] ?? 1;
    
    $bandwidth = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/bandwidth", [
        'networkId' => intval($networkId),
        'type' => intval($type),
        'mac' => $deviceMac
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $bandwidth];
    break;

case 'getDeviceBandwidthHistory':
    $networkId = $_POST['networkId'] ?? $networkId;
    $deviceMac = $_POST['deviceMac'] ?? '';
    $timeRange = $_POST['timeRange'] ?? 1; // 1=24 hours
    
    $bandwidthHistory = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/bandwidth", [
        'networkId' => intval($networkId),
        'type' => intval($timeRange),
        'mac' => $deviceMac
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $bandwidthHistory];
    break;

case 'getDeviceClientHistory':
    $networkId = $_POST['networkId'] ?? $networkId;
    $deviceMac = $_POST['deviceMac'] ?? '';
    $timeRange = $_POST['timeRange'] ?? 1; // 1=24 hours
    
    $clientHistory = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/client", [
        'networkId' => intval($networkId),
        'type' => intval($timeRange),
        'mac' => $deviceMac
    ], $access_token, $appID, $secretKey);
    
    $response = ['success' => true, 'data' => $clientHistory];
    break;

          
            case 'getNetworkDetail':
                $networkId = $_POST['networkId'] ?? $networkId;
                $networkDetail = gwn_oapi_get($base_url, "/oapi/v1.0.0/network/detail", $access_token, $appID, $secretKey);
                $response = ['success' => true, 'data' => $networkDetail];
                break;

                case 'getOnlineGuestsBySSID':
    $networkId = $_POST['networkId'] ?? $networkId;
    
    // Get online guests
    $onlineGuests = gwn_oapi_post($base_url, "/oapi/v1.0.0/portal/monitor/list", [
        'networkId' => intval($networkId),
        'pageNum' => 1,
        'pageSize' => 1000
    ], $access_token, $appID, $secretKey);

    $ssidCount = [];

    if (!isset($onlineGuests['error']) && isset($onlineGuests['data']['result'])) {
        foreach ($onlineGuests['data']['result'] as $guest) {
            $ssid = $guest['ssid'] ?? 'Unknown SSID';
            $ssidCount[$ssid] = ($ssidCount[$ssid] ?? 0) + 1;
        }
    }

    // Sort by count (descending)
    arsort($ssidCount);

    $response = [
        'success' => true,
        'data' => [
            'labels' => array_keys($ssidCount),
            'counts' => array_values($ssidCount),
            'total' => array_sum($ssidCount)
        ]
    ];
    break;

            case 'getCompleteDashboard':
                $networkId = $_POST['networkId'] ?? $networkId;
                $type = $_POST['type'] ?? 1;

                $dashboardData = [];

                // Get all dashboard data
                $dashboardData['overview'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/monitor/device/all", ['networkId' => $networkId], $access_token, $appID, $secretKey);
                $dashboardData['clientStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/client", [
                    'networkId' => intval($networkId),
                    'type' => intval($type),
                    'ssidId' => 'all_ssid'
                ], $access_token, $appID, $secretKey);
                $dashboardData['bandwidthStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type),
                    'ssidId' => 'all_ssid'
                ], $access_token, $appID, $secretKey);
                $dashboardData['apClientStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/client", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $dashboardData['ssidClientStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/client", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $dashboardData['ssidBandwidthStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ssid/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $dashboardData['apBandwidthStats'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/statistics/ap/bandwidth", [
                    'networkId' => intval($networkId),
                    'type' => intval($type)
                ], $access_token, $appID, $secretKey);
                $dashboardData['devices'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/ap/list", [
                    'networkId' => $networkId,
                    'pageNum' => 1,
                    'pageSize' => 50,
                    'filter' => ['showType' => 'all']
                ], $access_token, $appID, $secretKey);
                $dashboardData['clients'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/client/list", [
                    'networkId' => intval($networkId),
                    'pageNum' => 1,
                    'pageSize' => 100
                ], $access_token, $appID, $secretKey);
                $dashboardData['ssids'] = gwn_oapi_post($base_url, "/oapi/v1.0.0/ssid/list", [
                    'networkId' => intval($networkId)
                ], $access_token, $appID, $secretKey);

                $response = ['success' => true, 'data' => $dashboardData];
                break;

            default:
                $response = ['success' => false, 'error' => 'Unknown action'];
        }
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'error' => 'Exception occurred',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
    }

    echo json_encode($response);
    exit;
}

// If no action specified, return error
echo json_encode(['success' => false, 'error' => 'No action specified']);
exit;