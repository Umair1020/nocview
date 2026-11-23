<?php
// File: router_poll.php
// Live status and traffic for ONE MikroTik router. Used in-process and by router_worker.php.
// Requires only the RouterOS API class: no DB, no session.

require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/push_lib.php';

if (!defined('ROUTER_API_PORT')) define('ROUTER_API_PORT', 8728);
if (!defined('ROUTER_PING_TARGET')) define('ROUTER_PING_TARGET', '115.42.66.56');

/** ICMP reachability (plus optional read-only SNMP traffic) for non-RouterOS sites.
 * A missing ping reply cannot distinguish a blocked ping from an offline firewall.
 * $options is forwarded to pollSnmpInterfaceTraffic (state_dir, walk_fn, get_fn, now).
 */
function pollPingLocation($ip, $iface = null, array $options = []) {
    $iface = is_string($iface) && trim($iface) !== '' ? trim($iface) : null;
    $out = [
        'status' => 'unknown',
        'reason' => 'icmp_no_reply',
        'ping' => null,
        'interface' => $iface,
        'rx_mbps' => null,
        'tx_mbps' => null,
        'bandwidth_usage' => null,
        'bw_reason' => null,
        'snmp_reachable' => false,
        'stale' => false,
        'age' => null,
    ];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $out['reason'] = 'invalid_ip';
        return $out;
    }

    // Shared hosting: use the result the poller pushed (see push_lib.php).
    if (nocviewPushMode() === 'receive' && !isset($options['walk_fn'])) {
        $stale = false;
        $e = nocviewPushGet($ip);
        if ($e === null) {
            // Inside the grace window the last real measurement is still shown
            // (flagged stale) instead of turning a working site into "down".
            $e = nocviewPushGet($ip, true);
            $stale = $e !== null;
        }
        if ($e === null) {
            $out['reason'] = 'push_stale';
            return $out;
        }
        $reason = (string)($e['reason'] ?? 'pushed');
        return [
            'status' => in_array($e['status'] ?? '', ['up', 'unknown'], true) ? $e['status'] : 'unknown',
            'reason' => $stale ? 'push_stale_last_known' : $reason,
            'ping' => nocviewPushNum($e['ping'] ?? null),
            'interface' => $iface,
            'rx_mbps' => nocviewPushNum($e['rx_mbps'] ?? null),
            'tx_mbps' => nocviewPushNum($e['tx_mbps'] ?? null),
            'bandwidth_usage' => nocviewPushNum($e['bandwidth_usage'] ?? null),
            'bw_reason' => $e['bw_reason'] ?? null,
            'snmp_reachable' => !empty($e['snmp_reachable']),
            'stale' => $stale,
            'age' => isset($e['age']) ? (int)$e['age'] : null,
        ];
    }

    $disabledFns = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $disabledFns, true)) {
        $windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $command = $windows
            ? 'ping -n 1 -w 2000 ' . escapeshellarg($ip)
            : 'LC_ALL=C ping -n -c 1 -W 2 ' . escapeshellarg($ip);
        $lines = [];
        $exitCode = 1;
        @exec($command . ' 2>&1', $lines, $exitCode);
        $pingResult = parsePingLocationResult($exitCode, implode("\n", $lines));
        $out['status'] = $pingResult['status'];
        $out['reason'] = $pingResult['reason'];
        $out['ping'] = $pingResult['ping'];
    } else {
        $out['reason'] = 'icmp_unavailable';
    }

    if ($iface !== null) {
        $bw = pollSnmpInterfaceTraffic($ip, $iface, $options);
        $out['interface'] = $bw['interface'];
        $out['bw_reason'] = $bw['reason'];
        $out['snmp_reachable'] = !empty($bw['snmp_reachable']);
        if ($bw['status'] === 'up') {
            $out['rx_mbps'] = $bw['rx_mbps'];
            $out['tx_mbps'] = $bw['tx_mbps'];
            $out['bandwidth_usage'] = $bw['bandwidth_usage'];
        }
        if ($out['status'] !== 'up' && !empty($bw['snmp_reachable'])) {
            $out['status'] = 'up';
            $out['reason'] = 'snmp_reply';
        }
    }

    return $out;
}

/** Interpret ping output without treating a timeout or command error as a confirmed outage. */
function parsePingLocationResult($exitCode, $text) {
    if ($exitCode === 0 && preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $text, $m)) {
        return ['status' => 'up', 'reason' => 'icmp_reply', 'ping' => (float)$m[1]];
    }
    return [
        'status' => 'unknown',
        'reason' => $exitCode === 0 || $exitCode === 1 ? 'icmp_no_reply' : 'icmp_unavailable',
        'ping' => null,
    ];
}

/**
 * Load read-only SNMPv2c settings for a target IP from an untracked local file
 * (snmp_config.local.php) or NOCVIEW_SNMP_* environment variables.
 * Never falls back to router passwords or hardcoded community strings.
 */
function loadSnmpTargetConfig($ip, $configFile = null) {
    $file = $configFile !== null ? $configFile : (__DIR__ . '/snmp_config.local.php');
    $merged = [];
    if (is_string($file) && $file !== '' && is_file($file)) {
        $raw = include $file;
        if (is_array($raw)) {
            if (isset($raw['community']) && is_string($raw['community'])) {
                $merged = $raw;
            }
            if (isset($raw['default']) && is_array($raw['default'])) {
                $merged = array_merge($merged, $raw['default']);
            }
            if (isset($raw[$ip]) && is_array($raw[$ip])) {
                $merged = array_merge($merged, $raw[$ip]);
            } elseif (isset($raw[$ip]) && is_string($raw[$ip])) {
                $merged['community'] = $raw[$ip];
            }
        }
    }

    $ipEnvKey = 'NOCVIEW_SNMP_COMMUNITY_' . str_replace('.', '_', (string)$ip);
    $community = $merged['community'] ?? null;
    if (!is_string($community) || trim($community) === '') {
        $envSpecific = getenv($ipEnvKey);
        $envDefault = getenv('NOCVIEW_SNMP_COMMUNITY');
        if (is_string($envSpecific) && trim($envSpecific) !== '') {
            $community = $envSpecific;
        } elseif (is_string($envDefault) && trim($envDefault) !== '') {
            $community = $envDefault;
        }
    }
    if (!is_string($community) || trim($community) === '') {
        return null;
    }

    $portRaw = $merged['port'] ?? getenv('NOCVIEW_SNMP_PORT');
    $port = (is_numeric($portRaw) && (int)$portRaw >= 1 && (int)$portRaw <= 65535)
        ? (int)$portRaw : 161;

    $timeoutRaw = $merged['timeout_ms'] ?? getenv('NOCVIEW_SNMP_TIMEOUT_MS');
    $timeoutMs = is_numeric($timeoutRaw) ? max(200, min(10000, (int)$timeoutRaw)) : 1500;

    $retriesRaw = $merged['retries'] ?? getenv('NOCVIEW_SNMP_RETRIES');
    $retries = is_numeric($retriesRaw) ? max(0, min(3, (int)$retriesRaw)) : 1;

    $ifaceOverride = null;
    if (isset($merged['interface']) && is_string($merged['interface']) && trim($merged['interface']) !== '') {
        $ifaceOverride = trim($merged['interface']);
    }

    $hostOverride = null;
    if (isset($merged['host']) && is_string($merged['host'])
        && filter_var(trim($merged['host']), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $hostOverride = trim($merged['host']);
    }

    return [
        'community' => trim($community),
        'host' => $hostOverride,
        'port' => $port,
        'timeout_us' => $timeoutMs * 1000,
        'retries' => $retries,
        'interface' => $ifaceOverride,
    ];
}

/** Normalize an SNMP OCTET STRING value across PHP SNMP output modes, or null when missing. */
function parseSnmpStringValue($raw) {
    if (!is_string($raw) && !is_int($raw)) {
        return null;
    }
    $s = trim(str_replace("\0", '', (string)$raw));
    if ($s === '' || preg_match('/^(No Such (Object|Instance)|End of MIB)/i', $s)) {
        return null;
    }
    if (preg_match('/^STRING:\s*(.*)$/is', $s, $m)) {
        $s = trim($m[1]);
    }
    if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
        $s = substr($s, 1, -1);
    }
    $s = trim($s);
    return $s === '' ? null : $s;
}

/** Extract a non-negative unsigned decimal integer string from an SNMP value, or null. */
function parseSnmpUintValue($raw) {
    if (is_int($raw)) {
        return $raw >= 0 ? (string)$raw : null;
    }
    if (!is_string($raw)) {
        return null;
    }
    $s = trim($raw);
    if ($s === '' || preg_match('/^(No Such (Object|Instance)|End of MIB)/i', $s)) {
        return null;
    }
    if (preg_match('/^(?:Counter64|Counter32|Gauge32|Unsigned32|INTEGER):\s*(\d+)$/i', $s, $m)) {
        $digits = $m[1];
    } elseif (preg_match('/^(?:Timeticks|INTEGER):.*\((\d+)\)/i', $s, $m)) {
        $digits = $m[1];
    } elseif (preg_match('/^\d+$/', $s)) {
        $digits = $s;
    } else {
        return null;
    }
    $norm = ltrim($digits, '0');
    return $norm === '' ? '0' : $norm;
}

/** Find the exact positive IF-MIB ifIndex matching $targetIface (case-insensitive). */
function findSnmpInterfaceIndex(array $walkMap, $targetIface) {
    $target = trim((string)$targetIface);
    if ($target === '') {
        return null;
    }
    foreach ($walkMap as $oid => $rawVal) {
        if (!preg_match('/\.(\d+)$/', (string)$oid, $m)) {
            continue;
        }
        $idx = ltrim($m[1], '0');
        if ($idx === '') {
            continue;
        }
        $name = parseSnmpStringValue($rawVal);
        if ($name !== null && strcasecmp($name, $target) === 0) {
            return $idx;
        }
    }
    return null;
}

/** Compare two canonical unsigned decimal integer strings (-1, 0, 1). */
function snmpCompareUint($a, $b) {
    $a = ltrim((string)$a, '0');
    $b = ltrim((string)$b, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    $la = strlen($a);
    $lb = strlen($b);
    if ($la !== $lb) {
        return $la < $lb ? -1 : 1;
    }
    $cmp = strcmp($a, $b);
    return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
}

/** Exact unsigned decimal subtraction ($current - $previous), or null on counter reset. */
function snmpUnsignedDelta($current, $previous) {
    if (!is_string($current) || !is_string($previous)
        || !preg_match('/^\d+$/', $current) || !preg_match('/^\d+$/', $previous)) {
        return null;
    }
    if (snmpCompareUint($current, $previous) < 0) {
        return null;
    }
    $a = ltrim($current, '0');
    $b = ltrim($previous, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    $b = str_pad($b, strlen($a), '0', STR_PAD_LEFT);
    $borrow = 0;
    $out = '';
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $d = ((int)$a[$i]) - ((int)$b[$i]) - $borrow;
        if ($d < 0) {
            $d += 10;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $out = (string)$d . $out;
    }
    $norm = ltrim($out, '0');
    return $norm === '' ? '0' : $norm;
}

/** Convert two consecutive IF-MIB counter samples into Mbps, or null when unavailable/reset. */
function computeSnmpTrafficRate(array $current, $previous = null, $minInterval = 1.0, $maxInterval = 300.0) {
    $unavailable = function ($reason, $saveBaseline) {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'rx_mbps' => null,
            'tx_mbps' => null,
            'bandwidth_usage' => null,
            'save_baseline' => $saveBaseline,
        ];
    };
    if (!is_array($previous)
        || ($previous['iface'] ?? null) !== ($current['iface'] ?? null)
        || ($previous['if_index'] ?? null) !== ($current['if_index'] ?? null)
        || ($previous['bits'] ?? null) !== ($current['bits'] ?? null)
        || !isset($previous['sampled_at'], $previous['in_octets'], $previous['out_octets'])) {
        return $unavailable('snmp_baseline', true);
    }

    $elapsed = (float)$current['sampled_at'] - (float)$previous['sampled_at'];
    // 64-bit counters cannot wrap between cron runs, so the cron collector can still
    // turn a sample taken up to an hour ago into an average rate (no live page open).
    if (($current['bits'] ?? 64) === 64) {
        $maxInterval = max($maxInterval, 3600.0);
    }
    if ($elapsed <= 0 || $elapsed > $maxInterval) {
        return $unavailable('snmp_baseline', true);
    }
    if ($elapsed < $minInterval) {
        return $unavailable('snmp_interval_short', false);
    }

    if (isset($current['uptime_ticks'], $previous['uptime_ticks'])
        && is_string($current['uptime_ticks']) && is_string($previous['uptime_ticks'])
        && snmpCompareUint($current['uptime_ticks'], $previous['uptime_ticks']) < 0) {
        return $unavailable('snmp_counter_reset', true);
    }

    $deltaIn = snmpUnsignedDelta((string)$current['in_octets'], (string)$previous['in_octets']);
    $deltaOut = snmpUnsignedDelta((string)$current['out_octets'], (string)$previous['out_octets']);
    if ($deltaIn === null || $deltaOut === null) {
        return $unavailable('snmp_counter_reset', true);
    }

    // Some agents (e.g. Sangfor NGAF) refresh IF-MIB counters only every N seconds.
    // Unchanged counters mean "not refreshed yet", not zero traffic: keep the old
    // baseline and repeat the last rate, so the next refresh is averaged over the
    // whole period instead of a 10-second window (which produced false spikes).
    //
    // A frozen counter pair is never turned into "0 Mbps / 0 Mbps" while the link
    // is plainly carrying traffic (that is what painted Sangfor sites as "down").
    // Only after 15 minutes without any change is the link treated as genuinely
    // idle and an exact zero recorded.
    if ($deltaIn === '0' && $deltaOut === '0') {
        $cachedLimit = 300.0;
        $idleLimit = 900.0;
        if (isset($previous['last_rx'], $previous['last_tx']) && $elapsed <= $cachedLimit) {
            $lrx = (float)$previous['last_rx'];
            $ltx = (float)$previous['last_tx'];
            return [
                'status' => 'up',
                'reason' => 'snmp_ok_cached',
                'rx_mbps' => $lrx,
                'tx_mbps' => $ltx,
                'bandwidth_usage' => round($lrx + $ltx, 2),
                'save_baseline' => false,
            ];
        }
        if ($elapsed < $idleLimit) {
            // Keep the baseline so the eventual refresh is averaged over the whole
            // interval; report "unavailable" rather than a fake zero meanwhile.
            return $unavailable('snmp_waiting_refresh', false);
        }
    }

    $rx = round(((float)$deltaIn * 8.0) / ($elapsed * 1000000.0), 3);
    $tx = round(((float)$deltaOut * 8.0) / ($elapsed * 1000000.0), 3);

    $highSpeed = isset($current['high_speed_mbps']) ? (int)$current['high_speed_mbps'] : 0;
    if ($highSpeed >= 10 && ($rx > $highSpeed * 2.0 || $tx > $highSpeed * 2.0)) {
        return $unavailable('snmp_counter_reset', true);
    }

    return [
        'status' => 'up',
        'reason' => 'snmp_ok',
        'rx_mbps' => $rx,
        'tx_mbps' => $tx,
        'bandwidth_usage' => round($rx + $tx, 2),
        'save_baseline' => true,
    ];
}

/**
 * Poll live RX/TX Mbps for an interface over read-only SNMPv2c (IF-MIB).
 * Returns null metrics (never fake 0) when SNMP is unconfigured, blocked, or missing the interface.
 */
function pollSnmpInterfaceTraffic($ip, $iface, array $options = []) {
    $iface = is_string($iface) ? trim($iface) : '';
    $makeUnavailable = function ($reason, $reachable = false, $resolvedIface = null) use ($iface) {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'interface' => $resolvedIface !== null ? $resolvedIface : $iface,
            'rx_mbps' => null,
            'tx_mbps' => null,
            'bandwidth_usage' => null,
            'snmp_reachable' => (bool)$reachable,
        ];
    };

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $makeUnavailable('invalid_ip');
    }
    if ($iface === '' || !preg_match('/^[A-Za-z0-9._:\/ -]{1,64}$/', $iface)) {
        return $makeUnavailable('invalid_interface');
    }

    // Shared hosting: traffic comes from the poller's push, not from SNMP here.
    if (nocviewPushMode() === 'receive' && !isset($options['walk_fn'])) {
        $e = nocviewPushGet($ip);
        if ($e === null) {
            return $makeUnavailable('push_stale');
        }
        $rx = nocviewPushNum($e['rx_mbps'] ?? null);
        $tx = nocviewPushNum($e['tx_mbps'] ?? null);
        if ($rx === null || $tx === null) {
            return $makeUnavailable((string)($e['bw_reason'] ?? 'push_no_data'), !empty($e['snmp_reachable']));
        }
        return [
            'status' => 'up', 'reason' => 'push_ok', 'interface' => $iface,
            'rx_mbps' => $rx, 'tx_mbps' => $tx, 'bandwidth_usage' => round($rx + $tx, 2),
            'snmp_reachable' => true,
        ];
    }

    $cfg = loadSnmpTargetConfig($ip, $options['config_file'] ?? null);
    if ($cfg === null) {
        return $makeUnavailable('snmp_not_configured');
    }
    if ($cfg['interface'] !== null) {
        if (!preg_match('/^[A-Za-z0-9._:\/ -]{1,64}$/', $cfg['interface'])) {
            return $makeUnavailable('invalid_interface', false, $cfg['interface']);
        }
        $iface = $cfg['interface'];
    }

    $walkFn = $options['walk_fn'] ?? null;
    $getFn = $options['get_fn'] ?? null;
    if ($walkFn === null || $getFn === null) {
        // Net-SNMP is not thread-safe. Under Apache on Windows (one process, many
        // threads) concurrent SNMP calls can crash the whole web server, which shows
        // up in the browser as ERR_CONNECTION_RESET. There, polling runs only in the
        // separate CLI workers (router_worker.php), never inside Apache.
        // Only thread-safe (ZTS) web builds - Apache module on Windows/XAMPP - are at risk.
        // Hostinger (LiteSpeed lsphp / PHP-FPM, NTS, one request per process) polls in-process.
        if (PHP_SAPI !== 'cli' && PHP_ZTS) {
            return $makeUnavailable('snmp_cli_only', false, $iface);
        }
        if (!function_exists('snmp2_real_walk') || !function_exists('snmp2_get')) {
            return $makeUnavailable('snmp_extension_missing', false, $iface);
        }
        if (function_exists('snmp_set_oid_output_format') && defined('SNMP_OID_OUTPUT_NUMERIC')) {
            @snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        }
        $walkFn = function ($host, $community, $oid, $timeoutUs, $retries) {
            return @snmp2_real_walk($host, $community, $oid, $timeoutUs, $retries);
        };
        $getFn = function ($host, $community, $oid, $timeoutUs, $retries) {
            return @snmp2_get($host, $community, $oid, $timeoutUs, $retries);
        };
    }

    // Optional 'host': query the firewall on its own management IP while the
    // location keeps its customer-facing IP everywhere else in NocView.
    $snmpIp = !empty($cfg['host']) ? $cfg['host'] : $ip;
    $host = $cfg['port'] === 161 ? $snmpIp : ($snmpIp . ':' . $cfg['port']);
    $community = $cfg['community'];
    $timeoutUs = $cfg['timeout_us'];
    $retries = $cfg['retries'];

    $stateDir = $options['state_dir'] ?? sys_get_temp_dir();
    $statePath = rtrim($stateDir, '/\\') . DIRECTORY_SEPARATOR
        . 'nocview_snmp_ctr_' . hash('sha256', $ip . '|' . strtolower($iface)) . '.json';

    // Reuse known ifIndex when the agent confirms its name still matches; otherwise walk IF-MIB.
    $oidIfName = '.1.3.6.1.2.1.31.1.1.1.1';
    $oidIfDescr = '.1.3.6.1.2.1.2.2.1.2';
    $ifIndex = null;
    $namePrefix = null;
    $reachable = false;

    if (is_file($statePath)) {
        $hint = json_decode((string)@file_get_contents($statePath), true);
        if (is_array($hint) && !empty($hint['if_index']) && preg_match('/^\d+$/', (string)$hint['if_index'])) {
            $prefix = (isset($hint['name_oid']) && $hint['name_oid'] === $oidIfDescr) ? $oidIfDescr : $oidIfName;
            $check = $getFn($host, $community, $prefix . '.' . $hint['if_index'], $timeoutUs, $retries);
            if ($check !== false) {
                $reachable = true;
                $parsedName = parseSnmpStringValue($check);
                if ($parsedName !== null && strcasecmp($parsedName, $iface) === 0) {
                    $ifIndex = (string)$hint['if_index'];
                    $namePrefix = $prefix;
                }
            }
        }
    }

    if ($ifIndex === null) {
        $ifNames = $walkFn($host, $community, $oidIfName, $timeoutUs, $retries);
        if (is_array($ifNames)) {
            $reachable = true;
            $ifIndex = findSnmpInterfaceIndex($ifNames, $iface);
            if ($ifIndex !== null) {
                $namePrefix = $oidIfName;
            }
        }
        if ($ifIndex === null) {
            $ifDescrs = $walkFn($host, $community, $oidIfDescr, $timeoutUs, $retries);
            if (is_array($ifDescrs)) {
                $reachable = true;
                $ifIndex = findSnmpInterfaceIndex($ifDescrs, $iface);
                if ($ifIndex !== null) {
                    $namePrefix = $oidIfDescr;
                }
            }
        }
    }

    if (!$reachable) {
        return $makeUnavailable('snmp_unreachable', false, $iface);
    }
    if ($ifIndex === null) {
        return $makeUnavailable('interface_not_found', true, $iface);
    }

    $operRaw = $getFn($host, $community, '.1.3.6.1.2.1.2.2.1.8.' . $ifIndex, $timeoutUs, $retries);
    $oper = parseSnmpUintValue($operRaw);
    if ($oper !== null && $oper !== '1') {
        return $makeUnavailable('interface_down', true, $iface);
    }

    // Prefer 64-bit ifXTable counters; fall back to 32-bit ifTable counters.
    $bits = 64;
    $inOctets = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.31.1.1.1.6.' . $ifIndex, $timeoutUs, $retries));
    $outOctets = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.31.1.1.1.10.' . $ifIndex, $timeoutUs, $retries));
    if ($inOctets === null || $outOctets === null) {
        $bits = 32;
        $inOctets = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.2.2.1.10.' . $ifIndex, $timeoutUs, $retries));
        $outOctets = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.2.2.1.16.' . $ifIndex, $timeoutUs, $retries));
    }
    if ($inOctets === null || $outOctets === null) {
        return $makeUnavailable('snmp_no_counters', true, $iface);
    }

    $sampledAt = isset($options['now']) ? (float)$options['now'] : microtime(true);
    $uptimeTicks = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.1.3.0', $timeoutUs, $retries));
    $highSpeedStr = parseSnmpUintValue($getFn($host, $community, '.1.3.6.1.2.1.31.1.1.1.15.' . $ifIndex, $timeoutUs, $retries));

    $sample = [
        'iface' => strtolower($iface),
        'if_index' => $ifIndex,
        'name_oid' => $namePrefix,
        'bits' => $bits,
        'in_octets' => $inOctets,
        'out_octets' => $outOctets,
        'uptime_ticks' => $uptimeTicks,
        'high_speed_mbps' => $highSpeedStr !== null ? (int)$highSpeedStr : 0,
        'sampled_at' => $sampledAt,
    ];

    $fh = @fopen($statePath, 'c+');
    if (!$fh) {
        return $makeUnavailable('snmp_state_unavailable', true, $iface);
    }
    @chmod($statePath, 0600);
    $prev = null;
    if (@flock($fh, LOCK_EX)) {
        rewind($fh);
        $existing = stream_get_contents($fh);
        $decoded = is_string($existing) && $existing !== '' ? json_decode($existing, true) : null;
        if (is_array($decoded)) {
            $prev = $decoded;
        }
        $rate = computeSnmpTrafficRate($sample, $prev);
        if (!empty($rate['save_baseline'])) {
            if ($rate['status'] === 'up') {
                $sample['last_rx'] = $rate['rx_mbps'];
                $sample['last_tx'] = $rate['tx_mbps'];
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($sample));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    } else {
        $rate = computeSnmpTrafficRate($sample, null);
    }
    fclose($fh);

    return [
        'status' => $rate['status'],
        'reason' => $rate['reason'],
        'interface' => $iface,
        'rx_mbps' => $rate['rx_mbps'],
        'tx_mbps' => $rate['tx_mbps'],
        'bandwidth_usage' => $rate['bandwidth_usage'],
        'snmp_reachable' => true,
    ];
}

/** Live SNMP bandwidth adapter for bandwidth.php and collect_bandwidth.php. */
function bw_poll_snmp_live($ip, $iface, array $options = []) {
    $r = pollSnmpInterfaceTraffic($ip, $iface, $options);
    if ($r['status'] === 'up') {
        return [
            'status' => 'up',
            'rx_mbps' => $r['rx_mbps'],
            'tx_mbps' => $r['tx_mbps'],
            'error' => null,
            'reason' => 'snmp_ok',
        ];
    }
    return [
        'status' => 'unavailable',
        'rx_mbps' => null,
        'tx_mbps' => null,
        'error' => null,
        'reason' => $r['reason'],
    ];
}

/**
 * Build monitored bandwidth targets for a client group without touching the DB.
 * RouterOS targets carry router credentials; non-RouterOS targets use SNMP only.
 */
function buildBandwidthTargets(array $clientConfig, $group) {
    $targets = [];
    $zia = $clientConfig['ziauddin'] ?? [];
    $kno = $clientConfig['Knoativ'] ?? [];

    $addZiauddin = function () use (&$targets, $zia) {
        foreach ($zia['locationMap'] ?? [] as $ip => $name) {
            if (strpos($name, 'Knoativ') !== false) {
                continue;
            }
            $iface = $zia['interfaceMap'][$ip] ?? null;
            if (!$iface) {
                continue;
            }
            $targets[] = [
                'name' => $name, 'ip' => $ip, 'iface' => $iface,
                'type' => 'mikrotik',
                'user' => $zia['MT_USER'] ?? '', 'pass' => $zia['MT_PASS'] ?? '',
            ];
        }
        foreach ($zia['pingLocations'] ?? [] as $ip => $name) {
            $iface = $zia['pingInterfaces'][$ip] ?? null;
            if (!$iface) {
                continue;
            }
            $targets[] = [
                'name' => $name, 'ip' => $ip, 'iface' => $iface,
                'type' => 'snmp',
                'user' => '', 'pass' => '',
            ];
        }
    };

    $addKnoativ = function () use (&$targets, $kno) {
        foreach ($kno['interfaceLocations'] ?? [] as $key => $name) {
            $parts = explode('_', $key, 2);
            $ip = $parts[0];
            $iface = $kno['interfaceMap'][$key] ?? ($parts[1] ?? null);
            if (!$iface) {
                continue;
            }
            $targets[] = [
                'name' => $name, 'ip' => $ip, 'iface' => $iface,
                'type' => 'mikrotik',
                'user' => $kno['MT_USER'] ?? '', 'pass' => $kno['MT_PASS'] ?? '',
            ];
        }
    };

    if ($group === 'ziauddin') {
        $addZiauddin();
    } elseif ($group === 'Knoativ') {
        $addKnoativ();
    } else {
        $addZiauddin();
        $addKnoativ();
    }
    return $targets;
}

/** Interface traffic from the router (Mbps), or null when the interface is not found. */
function liveTrafficFromRouter($API, $iface) {
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=' . $iface, false);
    $API->write('=once=', true);
    $r = $API->read();
    if (!isset($r[0]['rx-bits-per-second'])) {
        return null;
    }
    return [
        'rx' => round((float)$r[0]['rx-bits-per-second'] / 1000000, 3),
        'tx' => round((float)($r[0]['tx-bits-per-second'] ?? 0) / 1000000, 3),
    ];
}

/**
 * Poll one router right now.
 * status: 'up' (API login succeeded) or 'down'; reason explains 'down' or partial data.
 */
function pollRouterLive($ip, $username, $password, $iface, $isKnoativ, $reachable) {
    $out = [
        'status' => 'down',
        'reason' => $reachable ? 'login_failed' : 'unreachable',
        'cpu_usage' => 0,
        'rx_mbps' => 0.0,
        'tx_mbps' => 0.0,
        'bandwidth_usage' => 0.0,
        'ping' => 0,
        'interface_stats' => [],
        'ppp_data' => null,
        'google_rtt' => null,      // ms, measured by this router; null = no answer
        'cloudflare_rtt' => null,
    ];
    // The TCP probe is only a hint for the reason text. Login is ALWAYS attempted:
    // a router can miss the 2-second probe while it is busy and still answer the API.

    $API = new RouterosAPI();
    $API->attempts = 1;      // one attempt only - retries made dead routers very slow
    $API->delay = 0;
    $API->timeout = 5;

    if (!$API->connect($ip, $username, $password)) {
        return $out;         // reachable but login failed
    }

    try {
        $out['status'] = 'up';
        $out['reason'] = 'ok';

        $API->write('/system/resource/print');
        $res = $API->read();
        $out['cpu_usage'] = isset($res[0]['cpu-load']) ? (float)$res[0]['cpu-load'] : 0;

        $traffic = liveTrafficFromRouter($API, $iface);
        if ($traffic) {
            $out['rx_mbps'] = $traffic['rx'];
            $out['tx_mbps'] = $traffic['tx'];
            $out['bandwidth_usage'] = round($traffic['rx'] + $traffic['tx'], 2);
        } else {
            $out['reason'] = 'interface_not_found';
        }

        if ($isKnoativ) {
            foreach (['ether1', 'ether2'] as $if) {
                $t = liveTrafficFromRouter($API, $if);
                if ($t) {
                    $out['interface_stats'][$if] = [
                        'download' => $t['rx'],
                        'upload' => $t['tx'],
                        'total' => round($t['rx'] + $t['tx'], 2),
                    ];
                }
            }
        }

        // Google DNS and Cloudflare DNS ping, measured by the router (its real internet link)
        $out['google_rtt'] = routerPingAverage($API, '8.8.8.8');
        $out['cloudflare_rtt'] = routerPingAverage($API, '1.1.1.1');

        // Latency from the router to the server-ping target
        $API->write('/ping', false);
        $API->write('=address=' . ROUTER_PING_TARGET, false);
        $API->write('=count=1', true);
        $pingResult = $API->read();
        if (is_array($pingResult)) {
            foreach ($pingResult as $response) {
                if (isset($response['time'])) {
                    $out['ping'] = (float)$response['time'];
                    break;
                }
            }
        }

        if ($isKnoativ) {
            $API->write('/ppp/active/print');
            $active = $API->read();
            $API->write('/ppp/secret/print');
            $secrets = $API->read();
            $activeList = is_array($active) ? $active : [];
            $secretList = is_array($secrets) ? $secrets : [];

            $connections = [];
            foreach ($activeList as $c) {
                $connections[] = [
                    'name' => $c['name'] ?? 'N/A',
                    'service' => $c['service'] ?? 'N/A',
                    'caller-id' => $c['caller-id'] ?? 'N/A',
                    'address' => $c['address'] ?? 'N/A',
                    'encoding' => $c['encoding'] ?? 'N/A',
                    'uptime' => $c['uptime'] ?? 'N/A',
                ];
            }
            $total = count($secretList);
            $activeCount = count($activeList);
            $out['ppp_data'] = [
                'status' => 'up',
                'total_ppp' => $total,
                'active_ppp' => $activeCount,
                'ppp_usage' => $total > 0 ? round($activeCount / $total * 100, 2) : 0,
                'active_connections' => $connections,
            ];
        }
    } catch (Throwable $e) {
        error_log("pollRouterLive $ip: " . $e->getMessage());
        $out['reason'] = 'read_error';
    } finally {
        $API->disconnect();
    }

    return $out;
}

/**
 * Read the current traffic of one interface from the router. Real value or an explicit error.
 * @return array ['status' => 'up'|'down'|'no_data', 'rx_mbps', 'tx_mbps', 'error']
 */
function bw_poll_live($ip, $iface, $user, $pass) {
    $out = ['status' => 'down', 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'error' => null];

    $API = new RouterosAPI();
    $API->attempts = 1;
    $API->delay = 0;
    $API->timeout = 3;

    if (!$API->connect($ip, $user, $pass)) {
        $out['error'] = 'Router unreachable or login failed';
        return $out;
    }

    try {
        $API->write('/interface/monitor-traffic', false);
        $API->write('=interface=' . $iface, false);
        $API->write('=once=', true);
        $traffic = $API->read();

        if (isset($traffic[0]['rx-bits-per-second'])) {
            $out['status'] = 'up';
            $out['rx_mbps'] = round((float)$traffic[0]['rx-bits-per-second'] / 1000000, 3);
            $out['tx_mbps'] = round((float)($traffic[0]['tx-bits-per-second'] ?? 0) / 1000000, 3);
        } else {
            $out['status'] = 'no_data';
            $out['error'] = 'No traffic data returned for interface ' . $iface;
        }
    } catch (Throwable $e) {
        $out['error'] = 'Router read error: ' . $e->getMessage();
    } finally {
        $API->disconnect();
    }
    return $out;
}

/** Average RTT (ms) of 3 echo requests sent by the router. null when nothing answered. */
function routerPingAverage($API, $address) {
    $API->write('/ping', false);
    $API->write('=address=' . $address, false);
    $API->write('=count=3', false);
    $API->write('=interval=200ms', true);
    $rows = $API->read();
    if (!is_array($rows) || empty($rows)) {
        return null;
    }
    $last = end($rows);
    if (!is_array($last) || !isset($last['avg-rtt'])) {
        return null;
    }
    if (isset($last['packet-loss']) && (float)$last['packet-loss'] >= 100) {
        return null;
    }
    return parseRouterOsDurationMs($last['avg-rtt']);
}

/** '5ms123us' -> 5.123 ; '12ms' -> 12 ; '1s' -> 1000 ; plain number -> milliseconds. */
function parseRouterOsDurationMs($value) {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?(?:s|ms|us))+$|^\d+(?:\.\d+)?$/', $value)) {
        return null;
    }
    $total = 0.0;
    if (preg_match_all('/(\d+(?:\.\d+)?)(s|ms|us)?/', $value, $parts, PREG_SET_ORDER)) {
        foreach ($parts as $p) {
            $n = (float)$p[1];
            $unit = $p[2] ?? 'ms';
            if ($unit === 's') $total += $n * 1000;
            elseif ($unit === 'us') $total += $n / 1000;
            else $total += $n;
        }
    }
    return round($total, 3);
} 