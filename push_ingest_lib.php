<?php
// File: push_ingest_lib.php
// Validation and ingest rules for results pushed by snmp_pusher.php.
// Pure functions + one orchestrator with injected side effects, so the whole
// receive path can be tested without a web server or a database.
//
// Rules that keep 24/7 history trustworthy:
//   * only IPs configured as Sangfor sites in config.php are accepted,
//   * a non-numeric / implausible rate is stored as null, never as 0,
//   * history is written only for a real measurement (status up + rates present),
//   * at most one history sample per site per NOCVIEW_PUSH_HISTORY_GAP seconds.

require_once __DIR__ . '/push_lib.php';

const PUSH_MAX_BODY_BYTES = 65536;
const PUSH_MAX_RESULTS = 64;
const PUSH_MAX_RATE_MBPS = 100000.0;   // 100 Gbps ceiling for a plausibility check
const PUSH_MAX_PING_MS = 60000.0;

/** IP => ['name' => ..., 'iface' => ...] for every non-RouterOS (Sangfor) site. */
function pushAllowedTargets(array $clientConfig) {
    $targets = [];
    foreach ($clientConfig as $client) {
        if (!is_array($client)) {
            continue;
        }
        foreach (($client['pingLocations'] ?? []) as $ip => $name) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            if (isset($targets[$ip])) {
                continue;
            }
            $targets[$ip] = [
                'name' => (string)$name,
                'iface' => isset($client['pingInterfaces'][$ip]) ? (string)$client['pingInterfaces'][$ip] : null,
            ];
        }
    }
    return $targets;
}

/** Poller self-report (host, mode, interval, failures) from an untrusted payload. */
function pushSanitizePollerMeta(array $data) {
    $raw = $data['poller'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    if (isset($raw['host']) && is_string($raw['host'])) {
        $out['host'] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', $raw['host']), 0, 64);
    }
    if (isset($raw['mode']) && in_array($raw['mode'], ['loop', 'once', 'actions'], true)) {
        $out['mode'] = $raw['mode'];
    }
    foreach (['interval_sec', 'ticks', 'consecutive_failures'] as $key) {
        if (isset($raw[$key]) && is_numeric($raw[$key])) {
            $out[$key] = max(0, (int)$raw[$key]);
        }
    }
    if (isset($raw['php']) && is_string($raw['php'])) {
        $out['php'] = substr(preg_replace('/[^0-9.]/', '', $raw['php']), 0, 16);
    }
    return $out;
}

/** Decode a pushed JSON body into ['ok' => bool, 'error' => ?, 'results' => [ip => array], 'poller' => []]. */
function pushDecodeBody($raw) {
    $fail = function ($error) {
        return ['ok' => false, 'error' => $error, 'results' => [], 'poller' => []];
    };
    if (!is_string($raw) || trim($raw) === '') {
        return $fail('empty_body');
    }
    if (strlen($raw) > PUSH_MAX_BODY_BYTES) {
        return $fail('body_too_large');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $fail('invalid_json');
    }
    $results = $data['results'] ?? null;
    if (!is_array($results) || $results === []) {
        return $fail('missing_results');
    }
    if (count($results) > PUSH_MAX_RESULTS) {
        return $fail('too_many_results');
    }
    $clean = [];
    foreach ($results as $ip => $entry) {
        $ip = (string)$ip;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !is_array($entry)) {
            continue;
        }
        $clean[$ip] = $entry;
    }
    if ($clean === []) {
        return $fail('no_valid_results');
    }
    return ['ok' => true, 'error' => null, 'results' => $clean, 'poller' => pushSanitizePollerMeta($data)];
}

/** Short, printable reason code from an untrusted payload. */
function pushSanitizeReason($value, $fallback = 'pushed') {
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim($value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,48}$/', $value)) {
        return $fallback;
    }
    return $value;
}

function pushSanitizeInterface($value) {
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9._:\/ -]{1,64}$/', $value) ? $value : null;
}

/** Rate in Mbps: a real number in [0, cap], or null. Never invents a zero. */
function pushRate($value) {
    if (!is_numeric($value)) {
        return null;
    }
    $value = (float)$value;
    if (!is_finite($value) || $value < 0 || $value > PUSH_MAX_RATE_MBPS) {
        return null;
    }
    return round($value, 3);
}

/** Latency in ms: a real number in [0, cap], or null. */
function pushPing($value) {
    if (!is_numeric($value)) {
        return null;
    }
    $value = (float)$value;
    if (!is_finite($value) || $value < 0 || $value > PUSH_MAX_PING_MS) {
        return null;
    }
    return round($value, 1);
}

/**
 * Normalize one pushed site result into the cache entry shape.
 * The website's clock ($now) is authoritative: a poller with a wrong clock or
 * timezone cannot make data look fresh.
 */
function pushNormalizeEntry($ip, array $raw, $now) {
    $status = isset($raw['status']) ? strtolower((string)$raw['status']) : 'unknown';
    if (!in_array($status, ['up', 'down', 'unknown'], true)) {
        $status = 'unknown';
    }
    $rx = pushRate($raw['rx_mbps'] ?? null);
    $tx = pushRate($raw['tx_mbps'] ?? null);
    $usage = ($rx === null || $tx === null) ? null : round($rx + $tx, 2);
    $sentAt = isset($raw['sent_at']) && is_string($raw['sent_at']) ? substr($raw['sent_at'], 0, 40) : null;

    return [
        'ip' => $ip,
        'status' => $status,
        'reason' => pushSanitizeReason($raw['reason'] ?? null, 'pushed'),
        'ping' => pushPing($raw['ping'] ?? null),
        'rx_mbps' => $rx,
        'tx_mbps' => $tx,
        'bandwidth_usage' => $usage,
        'bw_reason' => pushSanitizeReason($raw['bw_reason'] ?? null, $rx === null ? 'no_bandwidth' : 'snmp_ok'),
        'interface' => pushSanitizeInterface($raw['interface'] ?? null),
        'snmp_reachable' => !empty($raw['snmp_reachable']),
        'sent_at' => $sentAt,
        'received_at' => (int)$now,
    ];
}

/** True only for a real measurement: up, with both rates present. */
function pushHistoryEligible(array $entry) {
    return ($entry['status'] ?? '') === 'up'
        && isset($entry['rx_mbps'], $entry['tx_mbps'])
        && is_numeric($entry['rx_mbps'])
        && is_numeric($entry['tx_mbps']);
}

/**
 * Apply a batch of pushed results.
 *
 * @param array    $results       ip => raw entry (already decoded/validated)
 * @param array    $targets       ip => ['name', 'iface'] from pushAllowedTargets()
 * @param int      $now           server epoch seconds
 * @param callable $lastEntryFor  fn(string $ip): ?array  - cached entry, for the throttle
 * @param callable $storeEntries  fn(array $entries): bool - persist the live results
 * @param callable $logSample     fn(string $name, string $iface, array $entry): bool - append history
 * @return array ['stored' => int, 'logged' => int, 'skipped' => array, 'entries' => array]
 */
function pushIngestResults(
    array $results,
    array $targets,
    $now,
    callable $lastEntryFor,
    callable $storeEntries,
    callable $logSample,
    $minAcceptGap = NOCVIEW_PUSH_MIN_ACCEPT_GAP,
    $historyGap = NOCVIEW_PUSH_HISTORY_GAP
) {
    $out = ['stored' => 0, 'logged' => 0, 'skipped' => [], 'entries' => []];
    $minAcceptGap = max(0, (int)$minAcceptGap);
    $historyGap = max(1, (int)$historyGap);

    foreach ($results as $ip => $raw) {
        if (!isset($targets[$ip]) || !is_array($raw)) {
            $out['skipped'][] = ['ip' => (string)$ip, 'why' => 'unknown_target'];
            continue;
        }
        $previous = $lastEntryFor((string)$ip);
        if (is_array($previous) && !empty($previous['received_at'])
            && $now - (int)$previous['received_at'] < $minAcceptGap) {
            $out['skipped'][] = ['ip' => (string)$ip, 'why' => 'throttled'];
            continue;
        }

        $entry = pushNormalizeEntry((string)$ip, $raw, $now);
        $out['entries'][(string)$ip] = $entry;
        $out['stored']++;

        if (!pushHistoryEligible($entry)) {
            continue;
        }
        $iface = $targets[$ip]['iface'] ?: ($entry['interface'] ?? null);
        if (!$iface) {
            continue;
        }
        // $logSample is responsible for the DB-side gap check as well, so a
        // restarted receiver or a second poller cannot create duplicate rows.
        if ($logSample($targets[$ip]['name'], $iface, $entry)) {
            $out['logged']++;
        }
    }

    if ($out['entries'] !== []) {
        $out['store_ok'] = (bool)$storeEntries($out['entries']);
    } else {
        $out['store_ok'] = true;
    }
    return $out;
}
