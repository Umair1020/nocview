<?php
// File: push_lib.php
// SNMP "push" mode for shared hosting (Hostinger blocks outbound UDP 161 and exec()).
//
//   Poller PC/VM (mode 'push')    : snmp_pusher.php polls the Sangfor firewalls (ping + SNMP)
//                                   and POSTs the results to snmp_push.php on the website.
//   Website on Hostinger ('receive'): snmp_push.php stores the latest result per IP; the
//                                   dashboard, bandwidth page and cron collector read it
//                                   instead of polling the firewalls themselves.
//
// Mode and token come from push_config.local.php (server-only, never commit):
//   Hostinger:  return ['mode' => 'receive', 'token' => 'LONG-RANDOM-SECRET'];
//   Poller:     return ['mode' => 'push', 'token' => 'LONG-RANDOM-SECRET',
//                       'url' => 'https://nocview.spotcommglobal.com/snmp_push.php'];
// Without that file the mode is 'off' and NocView polls directly, as before.

const NOCVIEW_PUSH_MAX_AGE = 150;   // seconds a pushed result counts as live

function nocviewPushConfig() {
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    $file = __DIR__ . '/push_config.local.php';
    $cfg = is_file($file) ? (include $file) : null;
    if (!is_array($cfg)) {
        $cfg = null;
    }
    return $cfg;
}

function nocviewPushMode() {
    $cfg = nocviewPushConfig();
    $mode = is_array($cfg) ? ($cfg['mode'] ?? 'off') : 'off';
    return in_array($mode, ['push', 'receive'], true) ? $mode : 'off';
}

function nocviewPushCachePath() {
    return __DIR__ . '/snmp_push_cache.json';
}

function nocviewPushReadAll() {
    $raw = @file_get_contents(nocviewPushCachePath());
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** Latest pushed result for one IP, or null when missing or older than NOCVIEW_PUSH_MAX_AGE. */
function nocviewPushGet($ip) {
    $all = nocviewPushReadAll();
    $entry = $all[$ip] ?? null;
    if (!is_array($entry) || empty($entry['received_at'])) {
        return null;
    }
    if (time() - (int)$entry['received_at'] > NOCVIEW_PUSH_MAX_AGE) {
        return null;
    }
    return $entry;
}

/** Merge new results into the cache file (locked, atomic enough for one writer per minute). */
function nocviewPushStore(array $entries) {
    $path = nocviewPushCachePath();
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $existing = json_decode((string)stream_get_contents($fh), true);
        $all = is_array($existing) ? $existing : [];
        foreach ($entries as $ip => $entry) {
            $all[$ip] = $entry;
        }
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, json_encode($all)) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

/** Number or null (never turns missing data into 0). */
function nocviewPushNum($v) {
    return is_numeric($v) ? round((float)$v, 3) : null;
}
