<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$ip = '103.246.109.2';
$port = '4444';
$username = 'spotcomm';
$password = '@dmin$#@!1234';
$base_url = "https://$ip:$port/api/v1/";
$status_url = $base_url . 'system/status'; // Adjust if different

$ch = curl_init($status_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
$start_time = microtime(true);
$response = curl_exec($ch);
$ping_time = round((microtime(true) - $start_time) * 1000, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode<br>";
echo "Error: $error<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre><br>";
echo "API Latency: $ping_time ms<br>";

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    echo "Parsed Status: " . ($data['status'] ?? 'unknown') . "<br>";
    echo "CPU: " . ($data['cpu'] ?? $data['cpu_usage'] ?? 0) . "%<br>";
    echo "Uptime: " . ($data['uptime'] ?? $data['sys_uptime'] ?? 0) . "%<br>";
    echo "Bandwidth: " . ($data['bandwidth'] ?? $data['traffic'] ?? 0) . " Mbps<br>";
    echo "Ping: " . ($data['ping_time'] ?? $ping_time) . " ms<br>";
} else {
    // Fallback ping test
    if (function_exists('shell_exec')) {
        $ping_output = shell_exec("ping -c 2 -W 2 $ip | grep 'time=' | awk '{print $7}' | cut -d'=' -f2 2>/dev/null");
        $times = array_filter(array_map('floatval', explode("\n", $ping_output)));
        $ping = count($times) > 0 ? round(array_sum($times) / count($times), 2) : 0;
        echo "Fallback Ping: $ping ms (Status: " . ($ping > 0 ? 'up' : 'down') . ")<br>";
    } else {
        echo "shell_exec disabled<br>";
    }
}
?>