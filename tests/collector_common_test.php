<?php
// Run with: php tests/collector_common_test.php
// Covers the shared plumbing every always-on collector relies on: argument
// parsing, heartbeat files, single-instance locks and log rotation.

require_once __DIR__ . '/../collector_common.php';

function assertCommon($ok, $message) {
    if (!$ok) {
        $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($err, "FAIL: $message\n");
        exit(1);
    }
}

$tmpDir = sys_get_temp_dir() . '/nocview_common_test_' . uniqid('', true);
mkdir($tmpDir, 0700, true);

try {
    // ---------------------------------------------------------------- args
    $args = collectorParseArgs(['script.php', '--loop', '--interval=60', '--log', '/tmp/x.log', '--dry-run'], ['interval', 'log']);
    assertCommon(isset($args['loop']), 'Boolean flag parsed');
    assertCommon($args['interval'] === '60', 'Equals value parsed');
    assertCommon($args['log'] === '/tmp/x.log', 'Separate value parsed');
    assertCommon(isset($args['dry-run']), 'Dashed flag parsed');
    assertCommon($args['_rest'] === [], 'No stray arguments');

    assertCommon(collectorIntOption($args, 'interval', 15) === 60, 'Integer option read');
    assertCommon(collectorIntOption($args, 'ticks', 4) === 4, 'Missing option falls back to default');
    assertCommon(collectorIntOption(['interval' => 'abc'], 'interval', 15) === 15, 'Garbage falls back to default');
    assertCommon(collectorIntOption(['interval' => '2'], 'interval', 15, 5) === 5, 'Lower bound enforced');
    assertCommon(collectorIntOption(['interval' => '99999'], 'interval', 15, 5, 3600) === 3600, 'Upper bound enforced');
    assertCommon(collectorParseArgs(['x.php', '-h'])['help'] === true, 'Short help flag');

    // ---------------------------------------------------------------- CLI detect
    putenv('NOCVIEW_ALLOW_NON_CLI=1');
    assertCommon(collectorIsCli(), 'Explicit non-CLI override is honoured');
    putenv('NOCVIEW_ALLOW_NON_CLI');
    if (PHP_SAPI !== 'cli') {
        assertCommon(!collectorIsCli(), 'Non-CLI SAPI rejected without the override');
    }

    // ---------------------------------------------------------------- heartbeat
    $hbPath = $tmpDir . '/poller_heartbeat.json';
    $hb = collectorBaseHeartbeat('snmp_pusher', 'loop', ['interval_sec' => 60]);
    $hb['sites'] = ['103.246.109.194' => ['status' => 'up', 'rx_mbps' => 79.16]];
    assertCommon(collectorWriteHeartbeat($hbPath, $hb), 'Heartbeat written');
    $read = collectorReadHeartbeat($hbPath);
    assertCommon(is_array($read) && $read['name'] === 'snmp_pusher', 'Heartbeat round-trips');
    assertCommon($read['interval_sec'] === 60 && isset($read['updated_epoch']), 'Heartbeat keeps state and timestamps');
    assertCommon(collectorReadHeartbeat($tmpDir . '/missing.json') === null, 'Missing heartbeat is null');

    putenv('NOCVIEW_HEARTBEAT_SNMP_PUSHER=' . $tmpDir . '/via_env.json');
    assertCommon(collectorHeartbeatPath('snmp_pusher') === $tmpDir . '/via_env.json', 'Heartbeat path from environment');
    putenv('NOCVIEW_HEARTBEAT_SNMP_PUSHER');
    assertCommon(collectorHeartbeatPath('snmp_pusher', $tmpDir . '/explicit.json') === $tmpDir . '/explicit.json', 'Explicit heartbeat path wins');

    // ---------------------------------------------------------------- lock
    $lockA = collectorAcquireLock('common_test');
    assertCommon($lockA !== null, 'First lock acquired');
    $lockB = collectorAcquireLock('common_test');
    assertCommon($lockB === null, 'Second concurrent lock refused');
    collectorReleaseLock($lockA);
    $lockC = collectorAcquireLock('common_test');
    assertCommon($lockC !== null, 'Lock can be re-acquired after release');
    collectorReleaseLock($lockC);

    // ---------------------------------------------------------------- log rotation
    $log = $tmpDir . '/collector.log';
    ob_start();
    collectorLog('first line', $log);
    collectorLog('second line', $log);
    ob_end_clean();
    $content = (string)file_get_contents($log);
    assertCommon(substr_count($content, 'line') === 2, 'Both log lines appended');

    $big = fopen($log, 'c');
    ftruncate($big, COLLECTOR_LOG_MAX_BYTES + 1);
    fclose($big);
    ob_start();
    collectorLog('after rotation', $log);
    ob_end_clean();
    assertCommon(is_file($log . '.1'), 'Oversized log rotated to .1');
    assertCommon(strpos((string)file_get_contents($log), 'after rotation') !== false, 'New log starts clean');

    // ---------------------------------------------------------------- misc
    assertCommon(collectorAge(time() - 10) >= 9, 'Age computed');
    assertCommon(collectorAge(null) === null, 'Missing age stays null');

    echo "Collector common tests passed\n";
} finally {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($tmpDir);
}
