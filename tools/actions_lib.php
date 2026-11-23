<?php
// File: tools/actions_lib.php
// Logic behind the free GitHub Actions poller, kept free of side effects so it can
// be unit tested (tests/actions_poller_test.php):
//   * collect(): two SNMP samples $gap seconds apart -> RX/TX Mbps + ICMP status
//   * send():    POST the batch to snmp_push.php (curl, or the HTTP stream wrapper)
//
// Nothing here needs a database: the website receiver owns history storage.

require_once __DIR__ . '/../router_poll.php';
require_once __DIR__ . '/../push_ingest_lib.php';

/**
 * Poll every site twice and build the push payload entries.
 *
 * @param array    $sites       ip => ['name', 'iface'] (pushAllowedTargets())
 * @param array    $snmpOptions pollSnmpInterfaceTraffic() options (config_file, walk_fn, get_fn, state_dir)
 * @param int      $gap         seconds between the two counter samples
 * @param callable $sleeper     fn(int $seconds): void  (injected in tests)
 * @param callable $clock       fn(): float epoch seconds (injected in tests)
 * @return array ['results' => [ip => entry], 'summary' => [ip => entry], 'baseline' => [ip => sample]]
 */
function actionsCollect(array $sites, array $snmpOptions, $gap, callable $sleeper = null, callable $clock = null) {
    $gap = max(1, (int)$gap);
    $sleeper = $sleeper ?: function ($seconds) {
        sleep((int)$seconds);
    };
    $clock = $clock ?: function () {
        return microtime(true);
    };

    // 1) baseline counters (bandwidth only - one cheap SNMP read per site)
    $baseline = [];
    foreach ($sites as $ip => $site) {
        if (empty($site['iface'])) {
            continue;
        }
        $baseline[$ip] = pollSnmpInterfaceTraffic($ip, $site['iface'], array_merge($snmpOptions, ['now' => $clock()]));
    }

    // 2) wait, then read again: the delta over that window is the real rate
    $sleeper($gap);

    $results = [];
    $summary = [];
    foreach ($sites as $ip => $site) {
        $iface = $site['iface'];
        if (!$iface) {
            continue;
        }
        // $snmpOptions['snmp_only'] skips ICMP where ping is filtered (NOCVIEW_SKIP_ICMP=1).
        $live = pollPingLocation($ip, $iface, array_merge($snmpOptions, ['now' => $clock()]));
        $results[$ip] = [
            'status' => $live['status'],
            'reason' => $live['reason'],
            'ping' => $live['ping'],
            'rx_mbps' => $live['rx_mbps'],
            'tx_mbps' => $live['tx_mbps'],
            'bw_reason' => $live['bw_reason'],
            'interface' => $iface,
            'snmp_reachable' => !empty($live['snmp_reachable']),
        ];
        $summary[$ip] = [
            'name' => $site['name'],
            'interface' => $iface,
            'status' => $live['status'],
            'ping' => $live['ping'],
            'rx_mbps' => $live['rx_mbps'],
            'tx_mbps' => $live['tx_mbps'],
            'bw_reason' => $live['bw_reason'],
            'at' => time(),
        ];
    }

    return ['results' => $results, 'summary' => $summary, 'baseline' => $baseline];
}

/** Poller self-report stored by the receiver for the status page. */
function actionsPollerMeta($intervalSec = 300, $runNumber = 0, $host = 'github-actions') {
    return [
        'host' => $host,
        'mode' => 'actions',
        'interval_sec' => max(60, (int)$intervalSec),
        'ticks' => max(0, (int)$runNumber),
        'consecutive_failures' => 0,
        'php' => PHP_VERSION,
    ];
}

/** Full request body for snmp_push.php. */
function actionsPayload(array $results, array $meta, $sentAt = null) {
    return json_encode([
        'sent_at' => $sentAt ?: date('c'),
        'poller' => $meta,
        'results' => $results,
    ]);
}

/**
 * POST the payload. Returns [httpCode, body]; code 0 means the request itself failed.
 * Uses curl when available, otherwise the HTTP stream wrapper (minimal images).
 */
function actionsSend($url, $token, $payload, $timeoutSec = 25) {
    $timeoutSec = max(1, (int)$timeoutSec);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-NocView-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$code, $body === false ? $error : (string)$body];
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-NocView-Token: " . $token,
        'content' => $payload,
        'timeout' => $timeoutSec,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\b#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return [$code, $body === false ? 'request failed' : (string)$body];
}
