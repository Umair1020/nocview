<?php
header('Content-Type: application/json');
require('routeros_api.class.php');

$API = new RouterosAPI();
$API->debug = false;

// Mikrotik credentials
$ip = "115.42.66.56"; // apna Mikrotik ka IP
$user = "spotcomm"; 
$pass = "admin@1234"; 

$response = [
    "google_dns" => "N/A",
    "cloudflare_dns" => "N/A"
];

if ($API->connect($ip, $user, $pass)) {
    // Google DNS ping
    $google = $API->comm("/ping", [
        "address" => "8.8.8.8",
        "count"   => "2"
    ]);

    if (!empty($google)) {
        $last = end($google);
        if (isset($last['avg-rtt'])) {
            $response["google_dns"] = $last['avg-rtt'] . " ";
        }
    }

    // Cloudflare DNS ping
    $cloudflare = $API->comm("/ping", [
        "address" => "1.1.1.1",
        "count"   => "2"
    ]);

    if (!empty($cloudflare)) {
        $last = end($cloudflare);
        if (isset($last['avg-rtt'])) {
            $response["cloudflare_dns"] = $last['avg-rtt'] . " ";
        }
    }

    $API->disconnect();
}

echo json_encode($response);
