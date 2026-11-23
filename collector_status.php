<?php
// File: collector_status.php
// "Is 24/7 collection actually running?" - one page, no terminal needed.
//
// Shows:
//   * heartbeat of the always-on poller  (snmp_pusher.php --loop)   - is it up? when did it last tick?
//   * heartbeat of the history collector (collect_bandwidth.php)    - cron or --loop
//   * per site: age of the last pushed measurement, last stored history sample,
//     and how many of the last 60 minutes / 24 hours actually have data.
//
// Add ?format=json for a machine-readable copy (works with any uptime monitor).
// Read-only: this page never writes.

require_once __DIR__ . '/auth_check.php';   // starts the session + remember-me cookie
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_lib.php';
require_once __DIR__ . '/collector_common.php';
// Keep the page usable when MySQL is down: db_connect.php throws instead of exiting.
define('NOCVIEW_DB_SOFT_FAIL', true);
date_default_timezone_set('Asia/Karachi');
session_write_close();

$wantsJson = (($_GET['format'] ?? '') === 'json');
$now = time();

function csAge($epoch, $now) {
    if ($epoch === null || !is_numeric($epoch)) {
        return null;
    }
    return max(0, $now - (int)$epoch);
}

function csAgeText($seconds) {
    if ($seconds === null) {
        return 'never';
    }
    if ($seconds < 60) {
        return $seconds . 's ago';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm ago';
    }
    return floor($seconds / 86400) . 'd ago';
}

/** live / lagging / stopped / never for a heartbeat document. */
function csHeartbeatState($hb, $interval = 60, $now = null) {
    $now = $now === null ? time() : (int)$now;
    if (!is_array($hb)) {
        return ['state' => 'never', 'age' => null];
    }
    $age = csAge($hb['updated_epoch'] ?? null, $now);
    if ($age === null) {
        return ['state' => 'never', 'age' => null];
    }
    $interval = max(10, (int)$interval);
    if ($age <= $interval * 3 + 30) {
        return ['state' => 'live', 'age' => $age];
    }
    if ($age <= $interval * 15 + 120) {
        return ['state' => 'lagging', 'age' => $age];
    }
    return ['state' => 'stopped', 'age' => $age];
}

$pusherHeartbeatPath = collectorHeartbeatPath('snmp_pusher');
$collectorHeartbeatPath = collectorHeartbeatPath('collect_bandwidth');

// The poller usually runs on another machine (office PC / VPS), so its heartbeat
// file is not here. In that case the receiver's record of the last accepted push
// is used instead - it proves the poller is alive just as well.
$pusherHb = collectorReadHeartbeat($pusherHeartbeatPath);
$pusherSource = is_array($pusherHb) ? 'heartbeat file' : 'no heartbeat yet';
$meta = nocviewPushMetaRead();
if (is_array($meta) && !empty($meta['received_at'])) {
    $fromPush = [
        'name' => 'snmp_pusher',
        'mode' => $meta['mode'] ?? 'unknown',
        'host' => $meta['host'] ?? '',
        'php' => $meta['php'] ?? null,
        'interval_sec' => (int)($meta['interval_sec'] ?? 60),
        'ticks' => $meta['ticks'] ?? null,
        'consecutive_failures' => (int)($meta['consecutive_failures'] ?? 0),
        'last_http_code' => '200 (accepted)',
        'last_success_epoch' => (int)$meta['received_at'],
        'last_success_at' => date('c', (int)$meta['received_at']),
        'updated_epoch' => (int)$meta['received_at'],
        'updated_at' => date('c', (int)$meta['received_at']),
        'last_error' => null,
        'last_tick_epoch' => (int)$meta['received_at'],
        'last_tick_at' => date('c', (int)$meta['received_at']),
    ];
    $localAge = is_array($pusherHb) ? csAge($pusherHb['updated_epoch'] ?? null, $now) : null;
    if ($localAge === null || $localAge > csAge($fromPush['updated_epoch'], $now)) {
        $pusherHb = $fromPush;
        $pusherSource = 'last push from ' . ($fromPush['host'] !== '' ? $fromPush['host'] : 'poller');
    }
}

$collectorHb = collectorReadHeartbeat($collectorHeartbeatPath);
$pusherState = csHeartbeatState($pusherHb, (int)($pusherHb['interval_sec'] ?? 60), $now);
$collectorState = csHeartbeatState($collectorHb, (int)($collectorHb['interval_sec'] ?? 60), $now);

// ---------------------------------------------------------------- per site
$targets = [];
foreach ($clientConfig as $client) {
    if (!is_array($client)) {
        continue;
    }
    foreach (($client['locationMap'] ?? []) as $ip => $name) {
        if (isset($targets[$ip])) {
            continue;
        }
        $iface = $client['interfaceMap'][$ip] ?? null;
        if (!$iface) {
            continue;
        }
        $targets[$ip] = ['name' => (string)$name, 'ip' => (string)$ip, 'iface' => $iface, 'type' => 'mikrotik'];
    }
    foreach (($client['pingLocations'] ?? []) as $ip => $name) {
        if (isset($targets[$ip])) {
            continue;
        }
        $iface = $client['pingInterfaces'][$ip] ?? null;
        if (!$iface) {
            continue;
        }
        $targets[$ip] = ['name' => (string)$name, 'ip' => (string)$ip, 'iface' => $iface, 'type' => 'snmp'];
    }
    foreach (($client['interfaceLocations'] ?? []) as $key => $name) {
        $parts = explode('_', (string)$key, 2);
        $ip = $parts[0];
        $iface = $client['interfaceMap'][$key] ?? ($parts[1] ?? null);
        if (!$iface || isset($targets[$ip])) {
            continue;
        }
        $targets[$ip] = ['name' => (string)$name, 'ip' => (string)$ip, 'iface' => $iface, 'type' => 'mikrotik'];
    }
}

$history = [];
$dbError = null;
$retention = [];
try {
    require_once __DIR__ . '/db_connect.php';
    $mysqli = getDBConnection();
    $sql = "SELECT location, interface,
                   MAX(logged_at) AS last_at,
                   SUM(logged_at >= NOW() - INTERVAL 1 HOUR) AS last_hour,
                   SUM(logged_at >= NOW() - INTERVAL 1 DAY) AS last_day,
                   COUNT(DISTINCT CASE WHEN logged_at >= NOW() - INTERVAL 1 DAY
                                       THEN DATE_FORMAT(logged_at, '%Y-%m-%d %H:%i') END) AS minutes_day
            FROM bandwidth_logs
            WHERE logged_at >= NOW() - INTERVAL 2 DAY
            GROUP BY location, interface";
    $res = $mysqli->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $history[$row['location'] . '|' . $row['interface']] = $row;
        }
        $res->free();
    }

    // Retention overview so a 30d / 1y chart never surprises anyone.
    $res = $mysqli->query("SELECT COUNT(*) AS rows_total, MIN(logged_at) AS oldest,
                                  MAX(logged_at) AS newest FROM bandwidth_logs");
    if ($res) {
        $retention = $res->fetch_assoc() ?: [];
        $res->free();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$rows = [];
$problems = [];
foreach ($targets as $t) {
    $key = $t['name'] . '|' . $t['iface'];
    $h = $history[$key] ?? null;
    $lastStored = $h['last_at'] ?? null;
    $lastStoredAge = $lastStored ? csAge(strtotime($lastStored), $now) : null;
    $lastHour = isset($h['last_hour']) ? (int)$h['last_hour'] : 0;
    $lastDay = isset($h['last_day']) ? (int)$h['last_day'] : 0;
    $minutesDay = isset($h['minutes_day']) ? (int)$h['minutes_day'] : 0;

    $push = null;
    $pushAge = null;
    $pushState = null;
    if ($t['type'] === 'snmp') {
        $entry = nocviewPushGetAny($t['ip']);
        if (is_array($entry)) {
            $fresh = nocviewPushFreshness($entry, $now);
            $pushAge = $fresh['age'];
            $pushState = $fresh['state'];
            $push = [
                'status' => $entry['status'] ?? null,
                'rx_mbps' => $entry['rx_mbps'] ?? null,
                'tx_mbps' => $entry['tx_mbps'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'bw_reason' => $entry['bw_reason'] ?? null,
                'age' => $pushAge,
                'state' => $pushState,
            ];
        } else {
            $pushState = 'missing';
        }
    }

    if ($dbError === null) {
        if ($lastStoredAge === null) {
            $problems[] = $t['name'] . ': no history stored yet';
        } elseif ($lastStoredAge > 300) {
            $problems[] = $t['name'] . ': last history sample ' . csAgeText($lastStoredAge);
        }
    }
    if ($t['type'] === 'snmp' && $pushState !== null && $pushState !== 'live') {
        $problems[] = $t['name'] . ': pushed data ' . ($pushState === 'missing' ? 'missing' : $pushState . ' (' . csAgeText($pushAge) . ')');
    }

    $rows[] = [
        'name' => $t['name'],
        'ip' => $t['ip'],
        'iface' => $t['iface'],
        'type' => $t['type'],
        'push' => $push,
        'push_state' => $pushState,
        'push_age' => $pushAge,
        'last_stored_at' => $lastStored,
        'last_stored_age' => $lastStoredAge,
        'samples_1h' => $lastHour,
        'samples_24h' => $lastDay,
        'minutes_24h' => $minutesDay,
        'coverage_24h_pct' => $minutesDay > 0 ? round(min(100, $minutesDay / 1440 * 100), 1) : 0.0,
    ];
}

if ($pusherState['state'] !== 'live') {
    $problems[] = 'poller (snmp_pusher) heartbeat: ' . $pusherState['state'] . ' (' . csAgeText($pusherState['age']) . ')';
}
if ($collectorState['state'] !== 'live') {
    $problems[] = 'history collector (collect_bandwidth) heartbeat: ' . $collectorState['state'] . ' (' . csAgeText($collectorState['age']) . ')';
}
if ($dbError !== null) {
    $problems[] = 'database error: ' . $dbError;
}

$overall = 'ok';
if ($problems !== []) {
    $overall = 'warn';
}
if ($pusherState['state'] === 'stopped' || $collectorState['state'] === 'stopped') {
    $overall = 'bad';
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => $overall !== 'bad',
        'overall' => $overall,
        'checked_at' => date('c'),
        'problems' => $problems,
        'poller' => ['state' => $pusherState['state'], 'age' => $pusherState['age'], 'heartbeat' => $pusherHb],
        'collector' => ['state' => $collectorState['state'], 'age' => $collectorState['age'], 'heartbeat' => $collectorHb],
        'retention' => $retention,
        'sites' => $rows,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$badge = function ($state) {
    $map = ['live' => 'ok', 'lagging' => 'warn', 'stopped' => 'bad', 'never' => 'bad', 'missing' => 'bad', 'stale' => 'warn', 'expired' => 'bad', 'up' => 'ok'];
    return $map[$state] ?? 'warn';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>Collector status - NOCView</title>
<style>
  :root { color-scheme: dark; --bg:#0b1220; --card:#131c2e; --line:#22304a; --txt:#e6eefc; --muted:#8ea3c4; --ok:#20c997; --warn:#ffb020; --bad:#ff5c5c; }
  * { box-sizing: border-box; }
  body { margin:0; padding:24px; background:var(--bg); color:var(--txt); font:14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); margin-bottom:20px; }
  .cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; margin-bottom:22px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px 16px; }
  .card h2 { font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 10px; }
  .kv { display:flex; justify-content:space-between; gap:12px; padding:3px 0; font-size:13px; }
  .kv span:last-child { color:var(--txt); font-variant-numeric:tabular-nums; }
  .kv span:first-child { color:var(--muted); }
  .badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
  .badge.ok { background:rgba(32,201,151,.16); color:var(--ok); }
  .badge.warn { background:rgba(255,176,32,.16); color:var(--warn); }
  .badge.bad { background:rgba(255,92,92,.16); color:var(--bad); }
  table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  th, td { padding:9px 11px; text-align:left; border-bottom:1px solid var(--line); font-size:13px; }
  th { background:#0f1829; color:var(--muted); font-weight:600; text-transform:uppercase; font-size:11px; letter-spacing:.05em; }
  tr:last-child td { border-bottom:none; }
  td.num { font-variant-numeric:tabular-nums; }
  .muted { color:var(--muted); }
  .problems { margin:0 0 20px; padding:12px 16px; border-radius:10px; border:1px solid var(--line); background:var(--card); }
  .problems ul { margin:8px 0 0; padding-inline-start:20px; }
  .problems li { margin:2px 0; }
  a { color:#7cb8ff; }
  .foot { color:var(--muted); margin-top:18px; font-size:12px; }
</style>
</head>
<body>
  <h1>Collector status <span class="badge <?= $e($badge($overall === 'ok' ? 'live' : ($overall === 'bad' ? 'stopped' : 'lagging'))) ?>"><?= $e(strtoupper($overall)) ?></span></h1>
  <div class="sub">Checked <?= $e(date('Y-m-d H:i:s')) ?> (Asia/Karachi) · refreshes every 30s · <a href="?format=json">JSON</a> · <a href="bandwidth.php">Bandwidth page</a></div>

  <div class="cards">
    <div class="card">
      <h2>Poller &mdash; snmp_pusher.php</h2>
      <div class="kv"><span>Source</span><span><?= $e($pusherSource) ?></span></div>
      <div class="kv"><span>State</span><span class="badge <?= $e($badge($pusherState['state'])) ?>"><?= $e($pusherState['state']) ?></span></div>
      <div class="kv"><span>Last tick</span><span><?= $e(csAgeText($pusherState['age'])) ?></span></div>
      <div class="kv"><span>Mode</span><span><?= $e($pusherHb['mode'] ?? '-') ?></span></div>
      <div class="kv"><span>Interval</span><span><?= $e(($pusherHb['interval_sec'] ?? '-')) ?>s</span></div>
      <div class="kv"><span>Ticks / failures</span><span><?= $e($pusherHb['ticks'] ?? 0) ?> / <?= $e($pusherHb['consecutive_failures'] ?? 0) ?></span></div>
      <div class="kv"><span>Last HTTP</span><span><?= $e($pusherHb['last_http_code'] ?? '-') ?></span></div>
      <div class="kv"><span>Last success</span><span><?= $e(csAgeText(csAge($pusherHb['last_success_epoch'] ?? null, $now))) ?></span></div>
      <?php if (!empty($pusherHb['last_error'])): ?>
        <div class="kv"><span>Last error</span><span title="<?= $e($pusherHb['last_error']) ?>"><?= $e(substr((string)$pusherHb['last_error'], 0, 60)) ?></span></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>History collector &mdash; collect_bandwidth.php</h2>
      <div class="kv"><span>State</span><span class="badge <?= $e($badge($collectorState['state'])) ?>"><?= $e($collectorState['state']) ?></span></div>
      <div class="kv"><span>Last pass</span><span><?= $e(csAgeText($collectorState['age'])) ?></span></div>
      <div class="kv"><span>Mode</span><span><?= $e($collectorHb['mode'] ?? '-') ?></span></div>
      <div class="kv"><span>Targets</span><span><?= $e($collectorHb['targets'] ?? '-') ?></span></div>
      <div class="kv"><span>Last written</span><span><?= $e($collectorHb['last_pass']['written'] ?? '-') ?></span></div>
      <div class="kv"><span>Push mode</span><span><?= $e(!empty($collectorHb['push_receive_mode']) ? 'receive' : 'direct') ?></span></div>
    </div>

    <div class="card">
      <h2>History retention</h2>
      <div class="kv"><span>Rows</span><span><?= $e(number_format((int)($retention['rows_total'] ?? 0))) ?></span></div>
      <div class="kv"><span>Oldest sample</span><span><?= $e($retention['oldest'] ?? '-') ?></span></div>
      <div class="kv"><span>Newest sample</span><span><?= $e($retention['newest'] ?? '-') ?></span></div>
      <div class="kv"><span>Push cache</span><span><?= $e(count(nocviewPushReadAll())) ?> entries</span></div>
      <div class="kv"><span>Push mode</span><span><?= $e(nocviewPushMode()) ?></span></div>
      <div class="kv"><span>Live window</span><span><?= $e(nocviewPushMaxAge()) ?>s</span></div>
      <div class="kv"><span>Last-known-good</span><span><?= $e(nocviewPushGrace()) ?>s</span></div>
    </div>
  </div>

  <div class="problems">
    <strong>Checks</strong>
    <?php if ($problems === []): ?>
      <div class="muted">Everything is collecting normally.</div>
    <?php else: ?>
      <ul>
        <?php foreach ($problems as $p): ?>
          <li><?= $e($p) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>Site</th><th>Type</th><th>Interface</th>
        <th>Pushed</th><th>RX / TX</th>
        <th>Last history sample</th><th>Samples 1h</th><th>Samples 24h</th><th>Coverage 24h</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $e($r['name']) ?><div class="muted"><?= $e($r['ip']) ?></div></td>
          <td><?= $e($r['type']) ?></td>
          <td><?= $e($r['iface']) ?></td>
          <td>
            <?php if ($r['type'] === 'snmp'): ?>
              <span class="badge <?= $e($badge($r['push_state'] ?? 'missing')) ?>"><?= $e($r['push_state'] ?? 'missing') ?></span>
              <div class="muted"><?= $e(csAgeText($r['push_age'])) ?></div>
            <?php else: ?>
              <span class="muted">direct poll</span>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($r['push'] && $r['push']['rx_mbps'] !== null): ?>
              <?= $e(round((float)$r['push']['rx_mbps'], 1)) ?> / <?= $e(round((float)$r['push']['tx_mbps'], 1)) ?> Mbps
            <?php else: ?>
              <span class="muted"><?= $e($r['push']['bw_reason'] ?? '-') ?></span>
            <?php endif; ?>
          </td>
          <td><?= $e($r['last_stored_at'] ?? 'never') ?><div class="muted"><?= $e(csAgeText($r['last_stored_age'])) ?></div></td>
          <td class="num"><?= $e($r['samples_1h']) ?></td>
          <td class="num"><?= $e($r['samples_24h']) ?></td>
          <td class="num"><?= $e($r['coverage_24h_pct']) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="foot">
    Heartbeats: <?= $e($pusherHeartbeatPath) ?> · <?= $e($collectorHeartbeatPath) ?><br>
    A healthy setup: poller <em>live</em>, history collector <em>live</em>, every Sangfor site <em>live</em> with coverage near 100%.
  </div>
</body>
</html>
