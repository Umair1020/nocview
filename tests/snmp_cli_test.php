<?php
// Run with: php tests/snmp_cli_test.php
// Covers the net-snmp binary fallback used when PHP's snmp extension is missing
// (GitHub Actions runners, slim VPS images): command building, output parsing and
// a full two-sample rate calculation driven by realistic `snmpwalk -On` text.

require_once __DIR__ . '/../router_poll.php';

function assertCli($ok, $message) {
    if (!$ok) {
        $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($err, "FAIL: $message\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- command
$cmd = snmpCliCommand('/usr/bin/snmpget', '103.246.109.194', 'my community', '.1.3.6.1.2.1.31.1.1.1.6.2', 1500000, 1);
assertCli(strpos($cmd, "'/usr/bin/snmpget'") === 0, 'Binary is quoted');
assertCli(strpos($cmd, '-v2c') !== false && strpos($cmd, "-c 'my community'") !== false, 'Community escaped as one argument');
assertCli(strpos($cmd, '-t 2') !== false && strpos($cmd, '-r 1') !== false, 'Timeout rounded up, retries passed');
assertCli(strpos($cmd, '-On') !== false, 'Numeric OIDs requested');
assertCli(strpos($cmd, "'103.246.109.194' '.1.3.6.1.2.1.31.1.1.1.6.2'") !== false, 'Host and OID passed as separate arguments');

$inject = snmpCliCommand('snmpget', '1.1.1.1; rm -rf /', "x' ; whoami ; '", '.1.2.3', 1000000, 0);
assertCli(strpos($inject, "'1.1.1.1; rm -rf /'") !== false, 'Host with shell metacharacters stays a single quoted argument');
assertCli(substr_count($inject, "'") % 2 === 0, 'Quotes are balanced (every value escaped by escapeshellarg)');
assertCli(strpos($inject, "'x'\\'' ; whoami ; '\\'''") !== false, 'Quote inside the community is escaped, not closed');

// ---------------------------------------------------------------- parsing
$walk = <<<TXT
.1.3.6.1.2.1.31.1.1.1.1.1 = STRING: "mgmt0"
.1.3.6.1.2.1.31.1.1.1.1.2 = STRING: "eth4"
.1.3.6.1.2.1.2.2.1.2.2 = STRING: eth4
.1.3.6.1.2.1.31.1.1.1.15.2 = Gauge32: 1000
TXT;
$map = parseSnmpCliWalkOutput($walk);
assertCli(count($map) === 4, 'Every walk line parsed');
assertCli(parseSnmpStringValue($map['.1.3.6.1.2.1.31.1.1.1.1.2']) === 'eth4', 'Quoted STRING parsed');
assertCli(parseSnmpStringValue($map['.1.3.6.1.2.1.2.2.1.2.2']) === 'eth4', 'Unquoted STRING parsed');
assertCli(findSnmpInterfaceIndex($map, 'eth4') === '2', 'ifIndex found from CLI walk output');
assertCli(parseSnmpUintValue($map['.1.3.6.1.2.1.31.1.1.1.15.2']) === '1000', 'Gauge32 parsed');

assertCli(parseSnmpCliWalkOutput('') === [], 'Empty walk output is an empty map');
assertCli(parseSnmpCliWalkOutput("Timeout: No Response from 103.246.109.194\n") === [], 'Timeout text ignored');

assertCli(snmpCliValue('.1.3.6.1.2.1.31.1.1.1.6.2 = Counter64: 1125000000') === 'Counter64: 1125000000', 'Get value extracted');
assertCli(snmpCliValue('.1.3.6.1.2.1.2.2.1.8.2 = INTEGER: up(1)') === 'INTEGER: up(1)', 'Enum value extracted');
assertCli(parseSnmpUintValue(snmpCliValue('.1.3.6.1.2.1.2.2.1.8.2 = INTEGER: up(1)')) === '1', 'Oper status parsed');
assertCli(parseSnmpUintValue(snmpCliValue('.1.3.6.1.2.1.31.1.1.1.6.9 = No Such Object available on this agent at this OID')) === null, 'Missing OID stays null');
assertCli(snmpCliValue('No Such Instance currently exists at this OID') === 'No Such Instance currently exists at this OID', 'Bare message passes through');

// ---------------------------------------------------------------- no binaries
putenv('NOCVIEW_SNMP_CLI=0');
assertCli(!nocviewSnmpCliAvailable(), 'CLI detection can be disabled');
assertCli(nocviewSnmpCliFns() === null, 'No closures without binaries');
putenv('NOCVIEW_SNMP_CLI');

// ---------------------------------------------------------------- integration
// Two samples whose counters move, exactly as net-snmp would report them.
$tmpDir = sys_get_temp_dir() . '/nocview_cli_test_' . uniqid('', true);
mkdir($tmpDir, 0700, true);

try {
    $ip = '103.246.109.194';
    $cfgFile = $tmpDir . '/snmp_config.local.php';
    file_put_contents($cfgFile, "<?php\nreturn ['$ip' => ['community' => 'unit-test-ro']];\n");

    $walkFn = function ($host, $community, $oid) {
        if ($community !== 'unit-test-ro') {
            return false;
        }
        if ($oid === '.1.3.6.1.2.1.31.1.1.1.1') {
            return parseSnmpCliWalkOutput(
                ".1.3.6.1.2.1.31.1.1.1.1.1 = STRING: \"mgmt0\"\n"
                . ".1.3.6.1.2.1.31.1.1.1.1.2 = STRING: \"eth5\"\n"
            );
        }
        return false;
    };
    $counters = ['in' => 1000000000, 'out' => 500000000];
    $getFn = function ($host, $community, $oid) use (&$counters) {
        if ($community !== 'unit-test-ro') {
            return false;
        }
        $map = [
            '.1.3.6.1.2.1.2.2.1.8.2' => 'INTEGER: up(1)',
            '.1.3.6.1.2.1.31.1.1.1.15.2' => 'Gauge32: 1000',
            '.1.3.6.1.2.1.1.3.0' => 'Timeticks: (100000) 0:16:40.00',
        ];
        if ($oid === '.1.3.6.1.2.1.31.1.1.1.6.2') {
            return 'Counter64: ' . $counters['in'];
        }
        if ($oid === '.1.3.6.1.2.1.31.1.1.1.10.2') {
            return 'Counter64: ' . $counters['out'];
        }
        return $map[$oid] ?? 'No Such Object available on this agent at this OID';
    };

    $opts = ['config_file' => $cfgFile, 'state_dir' => $tmpDir, 'walk_fn' => $walkFn, 'get_fn' => $getFn];

    $first = pollSnmpInterfaceTraffic($ip, 'eth5', $opts + ['now' => 1000.0]);
    assertCli($first['reason'] === 'snmp_baseline' && $first['rx_mbps'] === null, 'First CLI-shaped sample primes the baseline');

    $counters['in'] += 93750000;   // +93.75 MB in 60 s  = 12.5 Mbps
    $counters['out'] += 18750000;  // +18.75 MB in 60 s  =  2.5 Mbps
    $second = pollSnmpInterfaceTraffic($ip, 'eth5', $opts + ['now' => 1060.0]);
    assertCli(
        $second['status'] === 'up' && $second['rx_mbps'] === 12.5 && $second['tx_mbps'] === 2.5,
        'Two CLI-shaped samples produce exact Mbps'
    );

    // A timeout (no walk answer at all) is unreachable, never a zero.
    $silentWalk = function () {
        return false;
    };
    $deadDir = $tmpDir . '/dead';
    @mkdir($deadDir, 0700, true);
    $dead = pollSnmpInterfaceTraffic($ip, 'eth5', ['config_file' => $cfgFile, 'state_dir' => $deadDir, 'walk_fn' => $silentWalk, 'get_fn' => $getFn]);
    assertCli($dead['status'] === 'unavailable' && $dead['reason'] === 'snmp_unreachable' && $dead['rx_mbps'] === null, 'No answer is unreachable, not zero');

    echo "SNMP CLI fallback tests passed\n";
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
