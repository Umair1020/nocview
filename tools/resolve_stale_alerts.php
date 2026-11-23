<?php
// File: tools/resolve_stale_alerts.php
// One-time (and on-demand) maintenance for the alerts table.
//
// Older versions of the alerting scripts created a fresh 'critical' alert every
// few minutes for every failing check and never marked anything resolved, so
// the dashboard's "Active Alerts" showed hundreds of stale criticals that no
// longer reflect reality. The collectors now resolve alerts automatically when
// a check proves the location is healthy; this tool sweeps up the backlog
// (and lets you close alerts manually, e.g. after a planned maintenance).
//
// Nothing is deleted: rows are only moved 'new' -> 'resolved', exactly what the
// dashboard already understands. 'acknowledged' rows are never touched.
//
// Usage:
//   php tools/resolve_stale_alerts.php                                   # resolve ALL open alerts
//   php tools/resolve_stale_alerts.php --dry-run                         # just count what would change
//   php tools/resolve_stale_alerts.php --older-than=24                   # only alerts older than 24 hours
//   php tools/resolve_stale_alerts.php --location="ZCR Boat Basin (Clifton)" --message-like="Failed to connect"
//   php tools/resolve_stale_alerts.php --location="Name" --location="Other" --older-than=0 --dry-run
//
// Exit codes: 0 = ok, 1 = failed (database unreachable / bad arguments).

require_once __DIR__ . '/../collector_common.php';

if (!collectorIsCli()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script can only be run from the command line.\n";
    exit(1);
}

// collectorParseArgs overwrites repeated options, so gather the multi-value
// ones (--location, --message-like) by hand first, then parse the rest.
$multiLocations = [];
$multiMessageLikes = [];
$rest = [$argv[0] ?? 'resolve_stale_alerts.php'];
foreach (array_slice($argv ?? [], 1) as $arg) {
    $arg = (string)$arg;
    if (strpos($arg, '--location=') === 0 && trim(substr($arg, 11)) !== '') {
        $multiLocations[] = trim(substr($arg, 11));
        continue;
    }
    if (strpos($arg, '--message-like=') === 0 && trim(substr($arg, 15)) !== '') {
        $multiMessageLikes[] = trim(substr($arg, 15));
        continue;
    }
    $rest[] = $arg;
}

$args = collectorParseArgs($rest, ['older-than', 'location', 'message-like']);

if (isset($args['help'])) {
    echo <<<TXT
resolve_stale_alerts.php - close open ('new') alerts safely

Usage:
  php tools/resolve_stale_alerts.php [options]

Options:
  --older-than=HOURS     only alerts at least this old (default 0 = no age limit)
  --location=NAME        only this location (repeatable); default = all locations
  --message-like=TEXT    only alerts whose message contains TEXT (repeatable)
  --dry-run              print how many alerts match, change nothing
  --help                 this text

Examples:
  # See how many stale alerts exist (no changes):
  php tools/resolve_stale_alerts.php --dry-run

  # Sweep false-positive RouterOS alerts for the four Sangfor sites:
  php tools/resolve_stale_alerts.php --message-like="Failed to connect" \\
      --location="Ziauddin Hospital Clifton & University" \\
      --location="Ziauddin North Nazimabad" \\
      --location="ZCR Boat Basin (Clifton)" \\
      --location="Ziauddin Education Board (ZEB)"

TXT;
    exit(0);
}

define('NOCVIEW_DB_SOFT_FAIL', true);
try {
    require_once __DIR__ . '/../db_connect.php';
} catch (Throwable $e) {
    fwrite(STDERR, "resolve_stale_alerts: database unavailable: " . $e->getMessage() . "\n");
    exit(1);
}

$mysqli = getDBConnection();
if (!$mysqli) {
    fwrite(STDERR, "resolve_stale_alerts: no database connection\n");
    exit(1);
}

$olderThan = collectorIntOption($args, 'older-than', 0, 0, 24 * 365);
$dryRun = isset($args['dry-run']);

// Also honor the single-value "--location NAME" / "--message-like TEXT" form.
foreach (['location' => 'multiLocations', 'message-like' => 'multiMessageLikes'] as $flag => $bucket) {
    if (isset($args[$flag]) && is_string($args[$flag]) && trim($args[$flag]) !== '') {
        ${$bucket}[] = trim($args[$flag]);
    }
}

$locations = array_values(array_unique($multiLocations));
$messageLikes = array_values(array_unique($multiMessageLikes));

$where = ["status = 'new'"];
$types = '';
$params = [];

if ($olderThan > 0) {
    $where[] = 'created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)';
    $types .= 'i';
    $params[] = $olderThan;
}
if ($locations !== []) {
    $where[] = 'location IN (' . str_repeat('?,', count($locations) - 1) . '?)';
    $types .= str_repeat('s', count($locations));
    foreach ($locations as $location) {
        $params[] = $location;
    }
}
if ($messageLikes !== []) {
    $likeClauses = [];
    foreach ($messageLikes as $like) {
        $likeClauses[] = 'message LIKE ?';
        $params[] = '%' . $like . '%';
        $types .= 's';
    }
    $where[] = '(' . implode(' OR ', $likeClauses) . ')';
}

$whereSql = implode(' AND ', $where);

// Count first - always, so --dry-run is exact and the real run is predictable.
$countStmt = $mysqli->prepare("SELECT COUNT(*) FROM alerts WHERE $whereSql");
if (!$countStmt) {
    fwrite(STDERR, "resolve_stale_alerts: prepare failed: " . $mysqli->error . "\n");
    exit(1);
}
if ($params !== []) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countStmt->bind_result($matching);
$countStmt->fetch();
$countStmt->close();
$matching = (int)$matching;

if ($dryRun) {
    echo "dry-run: {$matching} open alert(s) match; nothing was changed.\n";
    exit(0);
}

if ($matching === 0) {
    echo "Nothing to do: 0 open alerts match.\n";
    exit(0);
}

$updateStmt = $mysqli->prepare("UPDATE alerts SET status = 'resolved', updated_at = NOW() WHERE $whereSql");
if (!$updateStmt) {
    fwrite(STDERR, "resolve_stale_alerts: prepare failed: " . $mysqli->error . "\n");
    exit(1);
}
if ($params !== []) {
    $updateStmt->bind_param($types, ...$params);
}
$ok = $updateStmt->execute();
$resolved = $ok ? (int)$updateStmt->affected_rows : 0;
$updateStmt->close();

if (!$ok) {
    fwrite(STDERR, "resolve_stale_alerts: update failed: " . $mysqli->error . "\n");
    exit(1);
}

echo "Resolved {$resolved} alert(s). Active Alerts on the dashboard will drop accordingly on the next page load.\n";
exit(0);
