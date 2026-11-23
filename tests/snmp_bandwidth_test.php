<?php
// Run with: php tests/snmp_bandwidth_test.php
require_once __DIR__ . '/../router_poll.php';

function assertSnmp($ok, $message) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$tmpDir = sys_get_temp_dir() . '/nocview_snmp_test_' . uniqid('', true);
mkdir($tmpDir, 0700, true);

try {
    $ip = '103.246.109.194';
    $missingCfg = $tmpDir . '/missing.php';

    // 1. Unconfigured SNMP returns unavailable with null rates (never fake zero).
    putenv('NOCVIEW_SNMP_COMMUNITY');
    putenv('NOCVIEW_SNMP_COMMUNITY_103_246_109_194');
    $r = pollSnmpInterfaceTraffic($ip, 'eth4', ['config_file' => $missingCfg, 'state_dir' => $tmpDir]);
    assertSnmp(
        $r['status'] === 'unavailable'
        && $r['reason'] === 'snmp_not_configured'
        && $r['interface'] === 'eth4'
        && $r['rx_mbps'] === null
        && $r['tx_mbps'] === null
        && $r['bandwidth_usage'] === null
        && $r['snmp_reachable'] === false,
        'Unconfigured SNMP returns null bandwidth'
    );

    // 2. SNMP parsing helpers cover library and plain output modes + 64-bit math.
    assertSnmp(parseSnmpStringValue('STRING: "eth4"') === 'eth4', 'Parse quoted SNMP string');
    assertSnmp(parseSnmpStringValue('STRING: eth4') === 'eth4', 'Parse unquoted SNMP string');
    assertSnmp(parseSnmpStringValue('No Such Object available on this agent at this OID') === null, 'Reject No Such Object');
    assertSnmp(parseSnmpUintValue('Counter64: 18446744073709551615') === '18446744073709551615', 'Parse max Counter64');
    assertSnmp(parseSnmpUintValue('INTEGER: up(1)') === '1', 'Parse ifOperStatus up(1)');
    assertSnmp(parseSnmpUintValue('Timeticks: (987654) 2:44:36.54') === '987654', 'Parse Timeticks');
    assertSnmp(snmpUnsignedDelta('18446744073709551615', '18446744073709550615') === '1000', 'Exact 64-bit subtraction');
    assertSnmp(snmpUnsignedDelta('500', '600') === null, 'Counter reset yields null');
    assertSnmp(findSnmpInterfaceIndex([
        '.1.3.6.1.2.1.31.1.1.1.1.1' => 'STRING: "lo"',
        '.1.3.6.1.2.1.31.1.1.1.1.2' => 'STRING: "eth4"',
    ], 'eth4') === '2', 'Match eth4 ifIndex');
    assertSnmp(findSnmpInterfaceIndex([
        '.1.3.6.1.2.1.31.1.1.1.1.1' => 'STRING: "eth0"',
        '.1.3.6.1.2.1.31.1.1.1.1.2' => 'STRING: "eth1"',
    ], 'eth4') === null, 'Do not guess eth1 when eth4 is configured');

    // 3. Simulated SNMPv2c IF-MIB agent for eth4 (baseline -> rate -> reset -> down).
    $cfgFile = $tmpDir . '/snmp_config.local.php';
    file_put_contents($cfgFile, "<?php\nreturn ['103.246.109.194' => ['community' => 'unit-test-ro-placeholder']];\n");

    $agent = [
        'ifName' => [
            '.1.3.6.1.2.1.31.1.1.1.1.1' => 'STRING: "mgmt0"',
            '.1.3.6.1.2.1.31.1.1.1.1.2' => 'STRING: "eth4"',
        ],
        'oper' => 'INTEGER: up(1)',
        'hcIn' => 'Counter64: 1000000000',
        'hcOut' => 'Counter64: 500000000',
        'in32' => 'Counter32: 1000',
        'out32' => 'Counter32: 500',
        'uptime' => 'Timeticks: (100000) 0:16:40.00',
        'highSpeed' => 'Gauge32: 1000',
    ];
    $walkFn = function ($host, $community, $oid) use (&$agent) {
        if ($community !== 'unit-test-ro-placeholder') return false;
        if ($oid === '.1.3.6.1.2.1.31.1.1.1.1') return $agent['ifName'];
        if ($oid === '.1.3.6.1.2.1.2.2.1.2') return [];
        return false;
    };
    $getFn = function ($host, $community, $oid) use (&$agent) {
        if ($community !== 'unit-test-ro-placeholder') return false;
        $map = [
            '.1.3.6.1.2.1.31.1.1.1.1.2' => $agent['ifName']['.1.3.6.1.2.1.31.1.1.1.1.2'] ?? false,
            '.1.3.6.1.2.1.2.2.1.8.2' => $agent['oper'],
            '.1.3.6.1.2.1.31.1.1.1.6.2' => $agent['hcIn'],
            '.1.3.6.1.2.1.31.1.1.1.10.2' => $agent['hcOut'],
            '.1.3.6.1.2.1.2.2.1.10.2' => $agent['in32'],
            '.1.3.6.1.2.1.2.2.1.16.2' => $agent['out32'],
            '.1.3.6.1.2.1.1.3.0' => $agent['uptime'],
            '.1.3.6.1.2.1.31.1.1.1.15.2' => $agent['highSpeed'],
        ];
        return $map[$oid] ?? false;
    };

    // First sample primes baseline, returns null rather than fake zero.
    $p1 = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1000.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($p1['status'] === 'unavailable' && $p1['reason'] === 'snmp_baseline' && $p1['rx_mbps'] === null, 'First poll stores baseline');

    // Too-soon sample preserves baseline.
    $pShort = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1000.3,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($pShort['status'] === 'unavailable' && $pShort['reason'] === 'snmp_interval_short', 'Short interval skipped safely');

    // 10s later: +125,000,000 bytes RX (= 100.0 Mbps) and +31,250,000 bytes TX (= 25.0 Mbps).
    $agent['hcIn'] = 'Counter64: 1125000000';
    $agent['hcOut'] = 'Counter64: 531250000';
    $agent['uptime'] = 'Timeticks: (101000) 0:16:50.00';
    $p2 = bw_poll_snmp_live($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1010.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp(
        $p2['status'] === 'up' && $p2['rx_mbps'] === 100.0 && $p2['tx_mbps'] === 25.0,
        'Second poll computes exact RX/TX Mbps'
    );

    // Reboot / sysUpTime decrease resets baseline without producing a fake spike.
    $agent['hcIn'] = 'Counter64: 5000';
    $agent['hcOut'] = 'Counter64: 2000';
    $agent['uptime'] = 'Timeticks: (500) 0:00:05.00';
    $pReset = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1020.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($pReset['status'] === 'unavailable' && $pReset['reason'] === 'snmp_counter_reset' && $pReset['bandwidth_usage'] === null, 'Reboot resets baseline');

    // Interface operationally down returns null bandwidth.
    $agent['oper'] = 'INTEGER: down(2)';
    $pDown = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1030.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($pDown['status'] === 'unavailable' && $pDown['reason'] === 'interface_down' && $pDown['rx_mbps'] === null, 'Down interface returns null');

    // 32-bit fallback works when 64-bit counters are unsupported.
    $agent['oper'] = 'INTEGER: up(1)';
    $agent['hcIn'] = 'No Such Object available on this agent at this OID';
    $agent['hcOut'] = 'No Such Object available on this agent at this OID';
    $agent['in32'] = 'Counter32: 10000000';
    $agent['out32'] = 'Counter32: 5000000';
    $agent['uptime'] = 'Timeticks: (2000) 0:00:20.00';
    $p32a = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1040.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($p32a['reason'] === 'snmp_baseline', '32-bit baseline established');
    $agent['in32'] = 'Counter32: 22500000';  // +12.5 MB over 10s = 10.0 Mbps
    $agent['out32'] = 'Counter32: 11250000'; // +6.25 MB over 10s = 5.0 Mbps
    $agent['uptime'] = 'Timeticks: (3000) 0:00:30.00';
    $p32b = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1050.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp(
        $p32b['status'] === 'up' && $p32b['rx_mbps'] === 10.0 && $p32b['tx_mbps'] === 5.0 && $p32b['bandwidth_usage'] === 15.0,
        '32-bit counter fallback computes exact Mbps'
    );

    // Missing interface name reports interface_not_found with null rates.
    $agent['ifName'] = ['.1.3.6.1.2.1.31.1.1.1.1.1' => 'STRING: "eth1"'];
    $pMiss = pollSnmpInterfaceTraffic($ip, 'eth4', [
        'config_file' => $cfgFile, 'state_dir' => $tmpDir, 'now' => 1060.0,
        'walk_fn' => $walkFn, 'get_fn' => $getFn,
    ]);
    assertSnmp($pMiss['status'] === 'unavailable' && $pMiss['reason'] === 'interface_not_found' && $pMiss['bandwidth_usage'] === null, 'Missing eth4 returns null');

    // 4. UI files must not contain the removed technical labels.
    $forbidden = [
        'ping reply',
        'SNMP/WAN',
        'WAN pending',
        'ICMP only',
        'ICMP reachability only',
        'Sangfor (ICMP)',
        'Sangfor ·',
        'ICMP is blocked',
        'ping is blocked',
    ];
    foreach (['index.php', 'locations.php', 'heatmap.php', 'bandwidth.php'] as $uiFile) {
        $content = (string)file_get_contents(__DIR__ . '/../' . $uiFile);
        foreach ($forbidden as $needle) {
            assertSnmp(stripos($content, $needle) === false, "$uiFile must not contain '$needle'");
        }
    }

    echo "SNMP bandwidth and UI label tests passed\n";
} finally {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
