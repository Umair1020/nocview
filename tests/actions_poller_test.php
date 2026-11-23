<?php
// Run with: php tests/actions_poller_test.php
// End-to-end logic of the free GitHub Actions poller: two SNMP samples (CLI-shaped,
// i.e. what net-snmp binaries return) -> exact Mbps -> push payload with poller meta.

require_once __DIR__ . '/../tools/actions_lib.php';

function assertActions($ok, $message) {
    if (!$ok) {
        $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($err, "FAIL: $message\n");
        exit(1);
    }
}

$tmpDir = sys_get_temp_dir() . '/nocview_actions_test_' . uniqid('', true);
mkdir($tmpDir, 0700, true);

try {
    $sites = [
        '103.246.109.194' => ['name' => 'Ziauddin Hospital Clifton & University', 'iface' => 'eth5'],
        '103.246.109.2' => ['name' => 'Ziauddin North Nazimabad', 'iface' => 'eth4'],
        '103.246.109.74' => ['name' => 'ZCR Boat Basin (Clifton)', 'iface' => 'eth4'],
        '103.246.109.82' => ['name' => 'Ziauddin Education Board (ZEB)', 'iface' => 'eth3'],
    ];

    $cfgFile = $tmpDir . '/snmp_config.local.php';
    $entries = [];
    foreach ($sites as $ip => $site) {
        $entries[$ip] = ['community' => 'unit-test-ro'];
    }
    file_put_contents($cfgFile, "<?php\nreturn " . var_export($entries, true) . ";\n");

    // Fake net-snmp: counters per IP/interface, advanced between the two samples.
    $counters = [
        '103.246.109.194' => ['in' => 1000000000, 'out' => 500000000, 'ifindex' => '5'],
        '103.246.109.2' => ['in' => 2000000000, 'out' => 900000000, 'ifindex' => '4'],
        '103.246.109.74' => ['in' => 3000000000, 'out' => 1000000000, 'ifindex' => '4'],
        '103.246.109.82' => ['in' => 4000000000, 'out' => 1100000000, 'ifindex' => '3'],
    ];
    $ifaceToIp = [];
    foreach ($sites as $ip => $site) {
        $ifaceToIp[$site['iface'] . '@' . $ip] = $ip;
    }

    $currentIp = null;
    $walkFn = function ($host, $community, $oid) use (&$counters, &$currentIp) {
        if ($community !== 'unit-test-ro' || !isset($counters[$host])) {
            return false;
        }
        $currentIp = $host;
        $idx = $counters[$host]['ifindex'];
        $iface = [
            '103.246.109.194' => 'eth5',
            '103.246.109.2' => 'eth4',
            '103.246.109.74' => 'eth4',
            '103.246.109.82' => 'eth3',
        ][$host];
        if ($oid === '.1.3.6.1.2.1.31.1.1.1.1') {
            return parseSnmpCliWalkOutput(
                ".1.3.6.1.2.1.31.1.1.1.1.1 = STRING: \"mgmt0\"\n"
                . ".1.3.6.1.2.1.31.1.1.1.1.$idx = STRING: \"$iface\"\n"
            );
        }
        return false;
    };
    $getFn = function ($host, $community, $oid) use (&$counters) {
        if ($community !== 'unit-test-ro' || !isset($counters[$host])) {
            return false;
        }
        $row = $counters[$host];
        $idx = $row['ifindex'];
        if ($oid === ".1.3.6.1.2.1.31.1.1.1.6.$idx") {
            return 'Counter64: ' . $row['in'];
        }
        if ($oid === ".1.3.6.1.2.1.31.1.1.1.10.$idx") {
            return 'Counter64: ' . $row['out'];
        }
        if ($oid === ".1.3.6.1.2.1.2.2.1.8.$idx") {
            return 'INTEGER: up(1)';
        }
        if ($oid === ".1.3.6.1.2.1.31.1.1.1.15.$idx") {
            return 'Gauge32: 1000';
        }
        if ($oid === '.1.3.6.1.2.1.1.3.0') {
            return 'Timeticks: (100000) 0:16:40.00';
        }
        return 'No Such Object available on this agent at this OID';
    };

    $options = [
        'config_file' => $cfgFile,
        'state_dir' => $tmpDir,
        'walk_fn' => $walkFn,
        'get_fn' => $getFn,
        'snmp_only' => true,   // CI runners: no ICMP, SNMP only
    ];

    $clock = 1000.0;
    $sleeperCalls = 0;
    $sleeper = function ($seconds) use (&$clock, &$counters, &$sleeperCalls) {
        $sleeperCalls++;
        $clock += $seconds;
        // 60 s of traffic per site: 93.75 MB in / 18.75 MB out = 12.5 / 2.5 Mbps
        $counters['103.246.109.194']['in'] += 93750000;
        $counters['103.246.109.194']['out'] += 18750000;
        // North Nazimabad is twice as busy: 25.0 / 5.0 Mbps
        $counters['103.246.109.2']['in'] += 187500000;
        $counters['103.246.109.2']['out'] += 37500000;
    };
    $clockFn = function () use (&$clock) {
        return $clock;
    };

    $collected = actionsCollect($sites, $options, 60, $sleeper, $clockFn);

    assertActions($sleeperCalls === 1, 'Exactly one wait between the two samples');
    assertActions(count($collected['results']) === 4, 'All four sites reported');
    assertActions(
        $collected['results']['103.246.109.194']['rx_mbps'] === 12.5
        && $collected['results']['103.246.109.194']['tx_mbps'] === 2.5,
        'Clifton: 12.5 / 2.5 Mbps over the 60 s window'
    );
    assertActions($collected['results']['103.246.109.2']['rx_mbps'] === 25.0, 'North Nazimabad: 25 Mbps');
    // Unchanged counters in the first window mean "agent has not refreshed yet":
    // the site stays up, bandwidth is N/A (never a fake 0.0) and no history is written.
    $quiet = $collected['results']['103.246.109.74'];
    assertActions($quiet['status'] === 'up' && $quiet['bw_reason'] === 'snmp_waiting_refresh', 'Quiet site stays up with a reason');
    assertActions($quiet['rx_mbps'] === null, 'Quiet site never reports a false 0.0 Mbps');
    assertActions(pushHistoryEligible($quiet) === false, 'Unmeasured quiet window is not stored');
    assertActions($collected['summary']['103.246.109.194']['name'] === 'Ziauddin Hospital Clifton & University', 'Summary keeps the display name');
    assertActions($collected['summary']['103.246.109.194']['interface'] === 'eth5', 'Summary keeps the interface');

    // An unreachable firewall never turns into 0 Mbps.
    $silent = function () {
        return false;
    };
    $deadDir = $tmpDir . '/dead';
    @mkdir($deadDir, 0700, true);
    $dead = actionsCollect(
        ['103.246.109.82' => $sites['103.246.109.82']],
        ['config_file' => $cfgFile, 'state_dir' => $deadDir, 'walk_fn' => $silent, 'get_fn' => $getFn, 'snmp_only' => true],
        5,
        function () {
        },
        $clockFn
    );
    assertActions($dead['results']['103.246.109.82']['rx_mbps'] === null, 'Unreachable site has null bandwidth');
    assertActions(pushHistoryEligible($dead['results']['103.246.109.82']) === false, 'Unreachable site is never stored as history');

    // ---------------------------------------------------------------- payload
    $meta = actionsPollerMeta(300, 42);
    assertActions($meta['mode'] === 'actions' && $meta['interval_sec'] === 300 && $meta['ticks'] === 42, 'Poller meta for the status page');
    assertActions($meta['host'] === 'github-actions', 'Poller host identifies the CI runner');

    $payload = actionsPayload($collected['results'], $meta, '2026-11-23T10:00:00+05:00');
    $decoded = pushDecodeBody($payload);
    assertActions($decoded['ok'] === true, 'Payload is accepted by the receiver parser');
    assertActions(count($decoded['results']) === 4, 'Receiver sees four results');
    assertActions($decoded['poller']['mode'] === 'actions', 'Receiver accepts the actions mode');
    assertActions($decoded['poller']['interval_sec'] === 300, 'Receiver keeps the interval for freshness thresholds');

    $entry = pushNormalizeEntry('103.246.109.194', $decoded['results']['103.246.109.194'], 1700000000);
    assertActions($entry['rx_mbps'] === 12.5 && pushHistoryEligible($entry), 'Accepted payload is history-eligible');

    // POST failure is reported, never silent (returns code 0 with an error string).
    [$code, $body] = actionsSend('http://127.0.0.1:9/snmp_push.php', 'token', $payload, 1);
    assertActions($code === 0 && $body !== '', 'Unreachable receiver reports a failure');

    echo "Actions poller tests passed\n";
} finally {
    foreach (glob($tmpDir . '/dead/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir . '/dead');
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
