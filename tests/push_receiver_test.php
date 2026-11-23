<?php
// Run with: php tests/push_receiver_test.php
// Covers the receive path that keeps Sangfor history filling 24/7:
// body validation, per-site throttling, "real measurement only" history rules,
// and the live/stale/expired freshness windows.

require_once __DIR__ . '/../push_lib.php';
require_once __DIR__ . '/../push_ingest_lib.php';

function pushTestErr() {
    static $fh = null;
    if ($fh === null) {
        $fh = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
    }
    return $fh;
}

function assertPush($ok, $message) {
    if (!$ok) {
        fwrite(pushTestErr(), "FAIL: $message\n");
        exit(1);
    }
}

$tmpDir = sys_get_temp_dir() . '/nocview_push_test_' . uniqid('', true);
mkdir($tmpDir, 0700, true);

try {
    // ---------------------------------------------------------------- token
    assertPush(nocviewPushTokenMatches('secret-token', 'secret-token'), 'Matching token accepted');
    assertPush(!nocviewPushTokenMatches('secret-tokenx', 'secret-token'), 'Longer token rejected');
    assertPush(!nocviewPushTokenMatches('', ''), 'Empty stored token never matches');
    assertPush(!nocviewPushTokenMatches(null, 'secret-token'), 'Null token rejected');

    // ---------------------------------------------------------------- body
    $ok = pushDecodeBody(json_encode(['results' => ['103.246.109.194' => ['status' => 'up']]]));
    assertPush($ok['ok'] === true && isset($ok['results']['103.246.109.194']), 'Valid body decoded');

    $rejects = [
        ['', 'empty_body'],
        ['not json', 'invalid_json'],
        [json_encode(['results' => []]), 'missing_results'],
        [json_encode(['results' => ['nonsense' => ['status' => 'up']]]), 'no_valid_results'],
    ];
    foreach ($rejects as [$body, $expected]) {
        $r = pushDecodeBody($body);
        assertPush($r['ok'] === false && $r['error'] === $expected, "Body rejected as $expected");
    }

    $tooMany = [];
    for ($i = 1; $i <= 65; $i++) {
        $tooMany['10.0.0.' . $i] = ['status' => 'up'];
    }
    $r = pushDecodeBody(json_encode(['results' => $tooMany]));
    assertPush($r['ok'] === false && $r['error'] === 'too_many_results', 'Oversized batch rejected');

    $r = pushDecodeBody(str_repeat('x', PUSH_MAX_BODY_BYTES + 1));
    assertPush($r['ok'] === false && $r['error'] === 'body_too_large', 'Oversized body rejected');

    // Poller self-report is sanitized, never trusted verbatim.
    $r = pushDecodeBody(json_encode([
        'results' => ['103.246.109.194' => ['status' => 'up', 'rx_mbps' => 1]],
        'poller' => ['host' => "office-pc<script>", 'mode' => 'loop', 'interval_sec' => '60',
                     'ticks' => '12', 'consecutive_failures' => 0, 'php' => '8.3.1-x'],
    ]));
    assertPush($r['ok'] === true, 'Body with poller meta decoded');
    assertPush($r['poller']['host'] === 'office-pcscript', 'Poller host sanitized (no markup survives)');
    assertPush($r['poller']['mode'] === 'loop' && $r['poller']['interval_sec'] === 60, 'Poller meta parsed');
    $bad = pushSanitizePollerMeta(['poller' => ['mode' => 'hacked', 'interval_sec' => 'abc']]);
    assertPush(!isset($bad['mode']) && !isset($bad['interval_sec']), 'Invalid poller meta dropped');

    // ---------------------------------------------------------------- entries
    $entry = pushNormalizeEntry('103.246.109.2', [
        'status' => 'UP', 'reason' => 'icmp_reply', 'ping' => '12.5',
        'rx_mbps' => '66.69', 'tx_mbps' => '52.02', 'bw_reason' => 'snmp_ok',
        'interface' => 'eth4', 'snmp_reachable' => true, 'sent_at' => '2026-11-23T10:00:00+05:00',
    ], 1700000000);
    assertPush($entry['status'] === 'up', 'Status normalized to lower case');
    assertPush($entry['rx_mbps'] === 66.69 && $entry['tx_mbps'] === 52.02, 'Numeric strings become floats');
    assertPush($entry['bandwidth_usage'] === 118.71, 'Usage is the sum of RX and TX');
    assertPush($entry['received_at'] === 1700000000, 'Server time is authoritative');

    $badEntry = pushNormalizeEntry('103.246.109.74', [
        'status' => 'exploded', 'rx_mbps' => 'N/A', 'tx_mbps' => -5, 'ping' => 'not-a-number',
        'bw_reason' => 'snmp_waiting_refresh', 'snmp_reachable' => 'yes',
    ], 1700000000);
    assertPush($badEntry['status'] === 'unknown', 'Unknown status falls back to unknown');
    assertPush($badEntry['rx_mbps'] === null && $badEntry['tx_mbps'] === null, 'Bad rates become null, never 0');
    assertPush($badEntry['bandwidth_usage'] === null, 'Usage stays null without both rates');
    assertPush($badEntry['ping'] === null, 'Bad latency becomes null');
    assertPush(pushNormalizeEntry('1.1.1.1', ['rx_mbps' => 999999], 1)['rx_mbps'] === null, 'Implausible rate rejected');

    assertPush(pushHistoryEligible($entry), 'Up with both rates may be stored');
    assertPush(!pushHistoryEligible($badEntry), 'No history row for unmeasured data');
    assertPush(!pushHistoryEligible(['status' => 'down', 'rx_mbps' => 0, 'tx_mbps' => 0]), 'Down is never stored as traffic');
    assertPush(!pushHistoryEligible(['status' => 'up', 'rx_mbps' => 5.0, 'tx_mbps' => null]), 'Missing TX is not stored');

    // ---------------------------------------------------------------- ingest
    $targets = [
        '103.246.109.194' => ['name' => 'Ziauddin Hospital Clifton & University', 'iface' => 'eth5'],
        '103.246.109.2' => ['name' => 'Ziauddin North Nazimabad', 'iface' => 'eth4'],
    ];
    $stored = [];
    $logged = [];
    $lastSeen = ['103.246.109.2' => ['received_at' => 1000]];
    $result = pushIngestResults(
        [
            '103.246.109.194' => ['status' => 'up', 'rx_mbps' => 79.16, 'tx_mbps' => 7.95, 'bw_reason' => 'snmp_ok'],
            '103.246.109.2' => ['status' => 'up', 'rx_mbps' => 66.69, 'tx_mbps' => 52.02, 'bw_reason' => 'snmp_ok'],
            '203.0.113.9' => ['status' => 'up', 'rx_mbps' => 1, 'tx_mbps' => 1],
        ],
        $targets,
        1001,
        function ($ip) use ($lastSeen) {
            return $lastSeen[$ip] ?? null;
        },
        function ($entries) use (&$stored) {
            $stored += $entries;
            return true;
        },
        function ($name, $iface, $entry) use (&$logged) {
            $logged[] = [$name, $iface, $entry['rx_mbps']];
            return true;
        }
    );
    assertPush($result['stored'] === 1, 'Only the fresh, configured site is stored');
    assertPush($result['logged'] === 1, 'One history row for one eligible site');
    assertPush($logged[0][1] === 'eth5', 'History uses the configured interface');
    assertPush(!isset($stored['103.246.109.2']), 'Throttled site not overwritten');
    assertPush($result['skipped'][0]['why'] === 'throttled', 'Throttle is reported');
    assertPush($result['store_ok'] === true, 'Store callback reported');

    // Unmeasured data must not reach the history callback at all.
    $logged2 = 0;
    $r2 = pushIngestResults(
        ['103.246.109.194' => ['status' => 'down', 'rx_mbps' => null, 'tx_mbps' => null]],
        $targets,
        2000,
        function () {
            return null;
        },
        function () {
            return true;
        },
        function () use (&$logged2) {
            $logged2++;
            return true;
        }
    );
    assertPush($logged2 === 0 && $r2['stored'] === 1, 'Live row stored, no history row for missing data');

    // ---------------------------------------------------------------- cache
    $cachePath = $tmpDir . '/snmp_push_cache.json';
    putenv('NOCVIEW_PUSH_CACHE_PATH=' . $cachePath);
    $now = time();
    $store = [
        '103.246.109.194' => pushNormalizeEntry('103.246.109.194', ['status' => 'up', 'rx_mbps' => 10, 'tx_mbps' => 2], $now),
        '103.246.109.74' => pushNormalizeEntry('103.246.109.74', ['status' => 'up', 'rx_mbps' => 5, 'tx_mbps' => 1], $now - 400),
        '103.246.109.82' => pushNormalizeEntry('103.246.109.82', ['status' => 'up', 'rx_mbps' => 3, 'tx_mbps' => 1], $now - 5000),
        '103.246.109.2' => pushNormalizeEntry('103.246.109.2', ['status' => 'up', 'rx_mbps' => 4, 'tx_mbps' => 1], $now - 30000),
    ];
    assertPush(nocviewPushStore($store, $now), 'Cache written');
    assertPush(is_file($cachePath), 'Cache file exists');
    $fresh = nocviewPushGet('103.246.109.194');
    assertPush($fresh !== null && $fresh['stale'] === false, 'Live entry served');
    assertPush(nocviewPushGet('103.246.109.74') === null, 'Stale entry hidden from the live view');
    $stale = nocviewPushGet('103.246.109.74', true);
    assertPush($stale !== null && $stale['stale'] === true && $stale['age'] >= 399, 'Stale entry available as last known good');
    assertPush(nocviewPushGet('103.246.109.82', true) === null, 'Expired entry never served');
    assertPush(nocviewPushGetAny('103.246.109.82') !== null, 'Expired entry still visible to the status page');

    nocviewPushStore([], $now);
    $all = nocviewPushReadAll();
    assertPush(!isset($all['103.246.109.2']), 'Entry beyond retention pruned');
    assertPush(isset($all['103.246.109.82']), 'Expired entry kept for the status page');

    putenv('NOCVIEW_PUSH_CACHE_PATH');
    echo "Push receiver tests passed\n";
} finally {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
