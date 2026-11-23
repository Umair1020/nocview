<?php
// One-time check for Hostinger: can this server reach the Sangfor firewalls over SNMP?
// Log in to NocView first, then open /nocview/hostinger_snmp_check.php. DELETE this file afterwards.
session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    exit('Login to NocView first.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/router_poll.php';

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$fn = function ($f) use ($disabled) {
    return function_exists($f) && !in_array($f, $disabled, true) ? 'yes' : 'NO';
};

echo "PHP version     : " . PHP_VERSION . "\n";
echo "PHP SAPI        : " . PHP_SAPI . (PHP_ZTS ? ' (ZTS)' : ' (NTS)') . "\n";
echo "SNMP extension  : " . (function_exists('snmp2_get') ? 'yes' : 'NO  -> hPanel > Advanced > PHP Configuration > PHP Extensions > tick snmp') . "\n";
echo "exec()          : " . $fn('exec') . "   (ping)\n";
echo "proc_open()     : " . $fn('proc_open') . "   (parallel workers)\n";

$ip = false;
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $ip = curl_exec($ch);
    curl_close($ch);
}
echo "Outbound IP     : " . ($ip ?: 'unknown') . "   <- client must allow THIS IP in Sangfor System > SNMP\n\n";

if (!function_exists('snmp2_get')) {
    exit("Enable the snmp extension first, then reload this page.\n");
}

$targets = [
    '103.246.109.194' => 'eth5',
    '103.246.109.2'   => 'eth4',
    '103.246.109.74'  => 'eth4',
    '103.246.109.82'  => 'eth3',
];
foreach ($targets as $target => $iface) {
    $cfg = loadSnmpTargetConfig($target);
    if ($cfg === null) {
        echo str_pad($target, 17) . "| no community in snmp_config.local.php\n";
        continue;
    }
    $host = !empty($cfg['host']) ? $cfg['host'] : $target;
    $t = microtime(true);
    $name = @snmp2_get($host, $cfg['community'], '.1.3.6.1.2.1.1.5.0', $cfg['timeout_us'], $cfg['retries']);
    printf("%s| %s | %.1fs\n", str_pad($target, 17),
        $name !== false ? trim($name) : 'NO RESPONSE (UDP 161 blocked or IP not allowed)', microtime(true) - $t);
}
echo "\nDelete this file after the check.\n";
