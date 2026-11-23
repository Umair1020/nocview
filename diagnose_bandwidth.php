<?php
// File: diagnose_bandwidth.php
// CLI-only diagnostic. Run on the server:
//   php diagnose_bandwidth.php
// It only reads. It does not change any data.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_connect.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

function out($s = '') { echo $s . PHP_EOL; }
function runq($db, $sql) {
    $res = $db->query($sql);
    if ($res === false) {
        out("  QUERY ERROR: " . $db->error);
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

out("=== 1. SQL mode (ONLY_FULL_GROUP_BY matters for the history queries) ===");
$mode = runq($db, "SELECT @@sql_mode AS sql_mode, @@version AS version");
out("  " . json_encode($mode));
$sqlMode = $mode[0]['sql_mode'] ?? '';
out("  ONLY_FULL_GROUP_BY enabled: " . (stripos($sqlMode, 'ONLY_FULL_GROUP_BY') !== false ? 'YES' : 'no'));

out();
out("=== 2. Whole-table totals in bandwidth_logs ===");
$tot = runq($db, "SELECT COUNT(*) AS rows_total, MIN(logged_at) AS first_row, MAX(logged_at) AS last_row,
                         COUNT(DISTINCT location) AS locations, COUNT(DISTINCT CONCAT(location,'|',interface)) AS loc_iface_pairs
                  FROM bandwidth_logs");
out("  " . json_encode($tot));

out();
out("=== 3. Rows per location and interface (date range + count) ===");
$rows = runq($db, "SELECT location, interface, COUNT(*) AS n, MIN(logged_at) AS first_at, MAX(logged_at) AS last_at,
                          COUNT(DISTINCT DATE(logged_at)) AS days_with_data
                   FROM bandwidth_logs
                   GROUP BY location, interface
                   ORDER BY location, interface");
foreach ($rows as $r) {
    out(sprintf("  %-30s %-28s rows=%-8s days=%-5s %s -> %s",
        $r['location'], $r['interface'], $r['n'], $r['days_with_data'], $r['first_at'], $r['last_at']));
}
if (!$rows) out("  (no rows)");

out();
out("=== 4. Rows per day (last 400 days), all locations ===");
$days = runq($db, "SELECT DATE(logged_at) AS d, COUNT(*) AS n
                   FROM bandwidth_logs
                   WHERE logged_at >= NOW() - INTERVAL 400 DAY
                   GROUP BY DATE(logged_at)
                   ORDER BY d");
out("  distinct days with data: " . count($days));
foreach ($days as $d) out("  {$d['d']}  {$d['n']}");

out();
out("=== 5. Status distribution (stored as TINYINT: 1=up, 0=down) ===");
$st = runq($db, "SELECT status, COUNT(*) AS n FROM bandwidth_logs GROUP BY status ORDER BY status");
foreach ($st as $s) out("  status={$s['status']}  rows={$s['n']}");

out();
out("=== 6. Samples per minute in the last 24h (is collection running every minute?) ===");
$per = runq($db, "SELECT DATE_FORMAT(logged_at, '%Y-%m-%d %H:%i') AS minute, COUNT(*) AS n
                  FROM bandwidth_logs
                  WHERE logged_at >= NOW() - INTERVAL 24 HOUR
                  GROUP BY minute ORDER BY minute DESC LIMIT 30");
out("  minutes with data in last 24h: " . count($per));
foreach ($per as $p) out("  {$p['minute']}  {$p['n']}");

out();
out("=== 7. Check: history query used by bandwidth_lib.php (7d / 6m / 1y) ===");
$probe = runq($db, "SELECT COUNT(*) AS buckets FROM (
                        SELECT FLOOR(UNIX_TIMESTAMP(logged_at)/86400) AS b
                        FROM bandwidth_logs
                        WHERE logged_at >= NOW() - INTERVAL 365 DAY
                        GROUP BY b) t");
out("  daily buckets in last 365 days: " . ($probe[0]['buckets'] ?? 'error'));

out();
out("=== 8. Other tables the dashboard touches (row counts) ===");
foreach (['bandwidth_data', 'mikrotik_stats', 'alerts', 'network_status_cache'] as $t) {
    $c = runq($db, "SELECT COUNT(*) AS n FROM `$t`");
    out("  $t: " . ($c[0]['n'] ?? 'missing'));
}

out();
out("Done. Share this output to confirm the 1-2 year history claim.");
