<?php
// Run with: php tests/sangfor_config_test.php
// Sangfor sites are polled via ICMP + read-only SNMP, never via RouterOS login.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../router_poll.php';

$zia = $clientConfig['ziauddin'];
$expected = [
    '103.246.109.194' => ['Ziauddin Hospital Clifton & University', 'eth5'],
    '103.246.109.2'   => ['Ziauddin North Nazimabad', 'eth4'],
    '103.246.109.74'  => ['ZCR Boat Basin (Clifton)', 'eth4'],
    '103.246.109.82'  => ['Ziauddin Education Board (ZEB)', 'eth3'],
];
$targets = buildBandwidthTargets($clientConfig, 'ziauddin');
$fail = function ($m) { fwrite(STDERR, "FAIL: $m\n"); exit(1); };

foreach ($expected as $ip => [$name, $iface]) {
    if (($zia['pingLocations'][$ip] ?? null) !== $name) $fail("$ip name");
    if (($zia['pingInterfaces'][$ip] ?? null) !== $iface) $fail("$ip interface $iface");
    if (isset($zia['locationMap'][$ip]) || isset($zia['interfaceMap'][$ip])) $fail("$ip must not be a RouterOS target");
    $t = array_values(array_filter($targets, function ($t) use ($ip) { return $t['ip'] === $ip; }));
    if (count($t) !== 1 || $t[0]['type'] !== 'snmp' || $t[0]['iface'] !== $iface || $t[0]['pass'] !== '') {
        $fail("$ip bandwidth target must be one SNMP target on $iface");
    }
}
if (array_intersect_key($expected, $clientConfig['Knoativ']['pingLocations'] ?? [])) $fail('Sangfor sites leaked into Knoativ');
echo "Sangfor configuration tests passed\n";
