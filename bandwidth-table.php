<?php
require('routeros_api.class.php');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$API = new RouterosAPI();
$interface = $_GET['interface'] ?? 'ether3-WAN-Sec';

if ($API->connect('103.246.108.50', 'spotcomm', 'admin@1234')) {

    // Monitor traffic
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=' . $interface, false);
    $API->write('=once=');
    $traffic = $API->read();

    $rx = isset($traffic[0]['rx-bits-per-second']) ? round($traffic[0]['rx-bits-per-second'] / 1024 / 1024, 2) : 0;
    $tx = isset($traffic[0]['tx-bits-per-second']) ? round($traffic[0]['tx-bits-per-second'] / 1024 / 1024, 2) : 0;

    // Interface details
    $API->write('/interface/print', false);
    $API->write('=?.name=' . $interface);
    $ifaceDetails = $API->read();

    // System resource (uptime, memory, CPU load)
    $API->write('/system/resource/print');
    $resources = $API->read();

    // Health (temperature, voltage)
    $API->write('/system/health/print');
    $health = $API->read();

    echo "<table>
        <tr><th colspan='2'>📡 Interface: <b>$interface</b></th></tr>
        <tr><td>RX Rate</td><td style='color:green; font-weight:bold;'>{$rx} Mbps</td></tr>
        <tr><td>TX Rate</td><td style='color:red; font-weight:bold;'>{$tx} Mbps</td></tr>";

    if (!empty($ifaceDetails[0])) {
        foreach ($ifaceDetails[0] as $key => $val) {
            if (!is_array($val)) {
                echo "<tr><td>$key</td><td>$val</td></tr>";
            }
        }
    }

    echo "<tr><th colspan='2'>🖥️ System Resources</th></tr>";
    if (!empty($resources[0])) {
        foreach ($resources[0] as $key => $val) {
            if (!is_array($val)) {
                echo "<tr><td>$key</td><td>$val</td></tr>";
            }
        }
    }

    echo "<tr><th colspan='2'>🌡️ Health Info</th></tr>";
    if (!empty($health[0])) {
        foreach ($health[0] as $key => $val) {
            if (!is_array($val)) {
                echo "<tr><td>$key</td><td>$val</td></tr>";
            }
        }
    }

    echo "</table>";
    $API->disconnect();

} else {
    echo "<p style='color:red; text-align:center;'>❌ Failed to connect to MikroTik.</p>";
}
?>
