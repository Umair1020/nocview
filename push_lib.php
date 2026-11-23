<?php
// File: push_lib.php
// SNMP "push" mode for shared hosting (Hostinger blocks outbound UDP 161 and exec()).
//
//   Poller (always-on VM or office PC, mode 'push')
//       snmp_pusher.php --loop  polls the Sangfor firewalls (ICMP + read-only SNMP)
//                               and POSTs the results to snmp_push.php on the website.
//   Website (Hostinger, mode 'receive')
//       snmp_push.php           stores the latest result per IP and appends one
//                               bandwidth_logs history sample per accepted update.
//       dashboard / bandwidth   read that cache instead of polling the firewalls.
//
// Mode and token come from push_config.local.php (server-only, never committed;
// see push_config.example.php):
//   Hostinger:  return ['mode' => 'receive', 'token' => 'LONG-RANDOM-SECRET'];
//   Poller:     return ['mode' => 'push', 'token' => 'LONG-RANDOM-SECRET',
//                       'url' => 'https://nocview.spotcommglobal.com/snmp_push.php'];
// Without that file the mode is 'off' and NocView polls directly, as before.
//
// Two freshness windows:
//   NOCVIEW_PUSH_MAX_AGE - a result this new is "live" (drives the live tiles).
//   NOCVIEW_PUSH_GRACE   - after that, the last known result is still served with
//                          stale=true instead of flipping the site to "down".
//                          Nothing stale is ever written to bandwidth_logs, so
//                          history only ever contains real measurements.

const NOCVIEW_PUSH_MAX_AGE = 150;        // seconds a pushed result counts as live
const NOCVIEW_PUSH_GRACE = 900;          // seconds a result stays "last known good"
const NOCVIEW_PUSH_KEEP = 21600;         // seconds results are kept for the status page
const NOCVIEW_PUSH_MIN_ACCEPT_GAP = 3;   // per-site throttle for stored live results
const NOCVIEW_PUSH_HISTORY_GAP = 45;     // history sample spacing (same as the live page / cron)

/**
 * Live window in seconds.
 * Default 150 s suits a 1-minute poller. For a 5-minute poller (GitHub Actions
 * free tier) set ['live_max_age' => 420] in push_config.local.php so the tiles
 * show the latest measurement instead of flapping to "unknown" every cycle.
 */
function nocviewPushMaxAge() {
    $cfg = nocviewPushConfig();
    $value = is_array($cfg) ? ($cfg['live_max_age'] ?? null) : null;
    if (!is_numeric($value)) {
        $value = getenv('NOCVIEW_PUSH_LIVE_MAX_AGE');
    }
    if (is_numeric($value) && (int)$value >= 30) {
        return (int)$value;
    }
    return NOCVIEW_PUSH_MAX_AGE;
}

/** How long a result stays "last known good" (default 15 min; configurable). */
function nocviewPushGrace() {
    $cfg = nocviewPushConfig();
    $value = is_array($cfg) ? ($cfg['grace_seconds'] ?? null) : null;
    if (!is_numeric($value)) {
        $value = getenv('NOCVIEW_PUSH_GRACE');
    }
    if (is_numeric($value) && (int)$value >= nocviewPushMaxAge()) {
        return (int)$value;
    }
    return max(NOCVIEW_PUSH_GRACE, nocviewPushMaxAge() * 2);
}

/** Server-local push configuration, or null when push_config.local.php is absent. */
function nocviewPushConfig($reload = false) {
    static $cfg = null;
    static $loaded = false;
    if ($reload) {
        $cfg = null;
        $loaded = false;
    }
    if ($loaded) {
        return $cfg;
    }
    $file = getenv('NOCVIEW_PUSH_CONFIG_FILE') ?: (__DIR__ . '/push_config.local.php');
    $cfg = is_file($file) ? (include $file) : null;
    if (!is_array($cfg)) {
        $cfg = null;
    }
    $loaded = true;
    return $cfg;
}

function nocviewPushMode() {
    $cfg = nocviewPushConfig();
    $mode = is_array($cfg) ? ($cfg['mode'] ?? 'off') : 'off';
    return in_array($mode, ['push', 'receive'], true) ? $mode : 'off';
}

function nocviewPushToken() {
    $cfg = nocviewPushConfig();
    return is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
}

/** Constant-time token check. An empty stored token never matches anything. */
function nocviewPushTokenMatches($given, $expected = null) {
    $expected = $expected === null ? nocviewPushToken() : (string)$expected;
    $given = is_string($given) ? $given : '';
    if ($expected === '' || $given === '') {
        return false;
    }
    return hash_equals($expected, $given);
}

function nocviewPushCachePath() {
    return getenv('NOCVIEW_PUSH_CACHE_PATH') ?: (__DIR__ . '/snmp_push_cache.json');
}

/** Latest pushed result per IP, including entries that are already stale. */
function nocviewPushReadAll() {
    $path = nocviewPushCachePath();
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    // Reads are safe even without a lock: the writer only ever renames a fully
    // written temp file into place. Some filesystems do not support flock().
    $locked = @flock($fh, LOCK_SH);
    $raw = (string)stream_get_contents($fh);
    if ($locked) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    $data = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** Live / stale / expired state of one cached entry. */
function nocviewPushFreshness(array $entry, $now = null) {
    $now = $now === null ? time() : (int)$now;
    if (empty($entry['received_at']) || !is_numeric($entry['received_at'])) {
        return ['state' => 'expired', 'age' => null];
    }
    $age = max(0, $now - (int)$entry['received_at']);
    if ($age <= nocviewPushMaxAge()) {
        return ['state' => 'live', 'age' => $age];
    }
    if ($age <= nocviewPushGrace()) {
        return ['state' => 'stale', 'age' => $age];
    }
    return ['state' => 'expired', 'age' => $age];
}

/**
 * Latest result for one IP.
 * $allowStale = true also returns last-known-good entries inside the grace window,
 * flagged with stale=true and age=<seconds>, instead of reporting the site as lost.
 */
function nocviewPushGet($ip, $allowStale = false) {
    $all = nocviewPushReadAll();
    $entry = $all[$ip] ?? null;
    if (!is_array($entry)) {
        return null;
    }
    $fresh = nocviewPushFreshness($entry);
    if ($fresh['state'] === 'live') {
        $entry['stale'] = false;
        $entry['age'] = $fresh['age'];
        return $entry;
    }
    if ($allowStale && $fresh['state'] === 'stale') {
        $entry['stale'] = true;
        $entry['age'] = $fresh['age'];
        return $entry;
    }
    return null;
}

/** Cached result for one IP regardless of age (status page / receiver throttle). */
function nocviewPushGetAny($ip) {
    $all = nocviewPushReadAll();
    return isset($all[$ip]) && is_array($all[$ip]) ? $all[$ip] : null;
}

/** Drop entries that are older than the retention window. */
function nocviewPushPrune(array $all, $now = null) {
    $now = $now === null ? time() : (int)$now;
    $kept = [];
    foreach ($all as $ip => $entry) {
        if (!is_array($entry) || empty($entry['received_at'])) {
            continue;
        }
        if ($now - (int)$entry['received_at'] > NOCVIEW_PUSH_KEEP) {
            continue;
        }
        $kept[$ip] = $entry;
    }
    return $kept;
}

/**
 * Merge new results into the cache file. Written under a lock to a temp file and
 * renamed, so a dashboard read never sees a half-written JSON document and two
 * overlapping pollers cannot corrupt it.
 */
function nocviewPushStore(array $entries, $now = null) {
    $path = nocviewPushCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    $locked = false;
    if ($lock) {
        $locked = @flock($lock, LOCK_EX);
    }
    $all = nocviewPushReadAll();
    foreach ($entries as $ip => $entry) {
        $all[$ip] = $entry;
    }
    $all = nocviewPushPrune($all, $now);
    $json = json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $ok = $json !== false
        && @file_put_contents($tmp, $json, LOCK_EX) !== false
        && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    } else {
        @chmod($path, 0640);
    }
    if ($lock) {
        if ($locked) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
    }
    return $ok;
}

/** Where the receiver records the last successful poller push (poller self-report). */
function nocviewPushMetaPath() {
    $path = nocviewPushCachePath();
    return preg_replace('/\.json$/', '', $path) . '_poller.json';
}

function nocviewPushMetaRead() {
    $path = nocviewPushMetaPath();
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Store the poller self-report with the website's own receive time. */
function nocviewPushMetaWrite(array $meta, $now = null) {
    $now = $now === null ? time() : (int)$now;
    $meta['received_at'] = $now;
    $path = nocviewPushMetaPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $ok = $json !== false
        && @file_put_contents($tmp, $json, LOCK_EX) !== false
        && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    } else {
        @chmod($path, 0640);
    }
    return $ok;
}

/** Number or null (never turns missing data into 0). */
function nocviewPushNum($v) {
    return is_numeric($v) ? round((float)$v, 3) : null;
}
