<?php
require_once __DIR__ . '/router_poll.php';
// File: bandwidth_lib.php
// Shared bandwidth helpers used by bandwidth.php (page + AJAX) and collect_bandwidth.php (cron).
// No caching: every live request talks to the MikroTik router, and history is read straight from bandwidth_logs.

require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Karachi');

// History ranges.
//  hours  : how far back to read
//  bucket : seconds per point (samples are averaged per bucket, peaks are kept as well)
//  fmt    : label format for the x-axis
const BW_RANGES = [
    '24h' => ['label' => 'Last 24 hours', 'hours' => 24,   'bucket' => 300,   'fmt' => 'h:i A'],
    '7d'  => ['label' => 'Last 7 days',   'hours' => 168,  'bucket' => 1800,  'fmt' => 'M d, h:i A'],
    '30d' => ['label' => 'Last 30 days',  'hours' => 720,  'bucket' => 7200,  'fmt' => 'M d, h:i A'],
    '6m'  => ['label' => 'Last 6 months', 'hours' => 4380, 'bucket' => 43200, 'fmt' => 'M d, Y'],
    '1y'  => ['label' => 'Last 1 year',   'hours' => 8760, 'bucket' => 86400, 'fmt' => 'M d, Y'],
];

// Users that see the Ziauddin group (same mapping as the rest of the dashboard)
function bw_user_group($username) {
    if (in_array($username, ['ziauddin', 'khurram', 'noman', 'admin'], true)) {
        return 'ziauddin';
    }
    if ($username === 'Knoativ') {
        return 'Knoativ';
    }
    return 'all';
}

/**
 * Build the list of monitored targets for a user group.
 * Each RouterOS target carries its owning client credentials; non-RouterOS targets use SNMP only.
 *
 * @return array list of ['name', 'ip', 'iface', 'type', 'user', 'pass']
 */
function bw_targets_for_group($clientConfig, $group) {
    return buildBandwidthTargets($clientConfig, $group);
}

/**
 * History for one location/interface, bucketed for the requested range.
 * Every bucket in the window is returned; buckets without samples are null (shown as gaps, not as zero).
 */
function bw_history($location, $iface, $range) {
    $cfg = BW_RANGES[$range];
    $hours = (int)$cfg['hours'];
    $bucket = (int)$cfg['bucket'];
    $mysqli = getDBConnection();

    $sql = "SELECT FLOOR(UNIX_TIMESTAMP(logged_at) / $bucket) * $bucket AS bucket_ts,
                   AVG(rx_mbps) AS avg_rx, MAX(rx_mbps) AS max_rx,
                   AVG(tx_mbps) AS avg_tx, MAX(tx_mbps) AS max_tx,
                   COUNT(*) AS samples
            FROM bandwidth_logs
            WHERE location = ? AND interface = ?
              AND logged_at >= NOW() - INTERVAL $hours HOUR
            GROUP BY bucket_ts
            ORDER BY bucket_ts";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('bandwidth history prepare failed: ' . $mysqli->error);
        throw new RuntimeException('History query failed');
    }
    $stmt->bind_param('ss', $location, $iface);
    if (!$stmt->execute()) {
        error_log('bandwidth history execute failed: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('History query failed');
    }
    $result = $stmt->get_result();
    $byBucket = [];
    while ($row = $result->fetch_assoc()) {
        $byBucket[(int)$row['bucket_ts']] = $row;
    }
    $stmt->close();

    // Coverage: the earliest and latest sample stored for this location/interface (uses the index)
    $cov = $mysqli->prepare("SELECT MIN(logged_at) AS first_at, MAX(logged_at) AS last_at
                             FROM bandwidth_logs WHERE location = ? AND interface = ?");
    $coverage = ['first' => null, 'last' => null];
    if ($cov) {
        $cov->bind_param('ss', $location, $iface);
        $cov->execute();
        $c = $cov->get_result()->fetch_assoc();
        $coverage = ['first' => $c['first_at'] ?? null, 'last' => $c['last_at'] ?? null];
        $cov->close();
    }

    // Build the full time axis for the window (gaps stay visible)
    $now = time();
    $end = (int)(floor($now / $bucket) * $bucket);
    $start = (int)(floor(($now - $hours * 3600) / $bucket) * $bucket);

    $data = [
        'epoch' => [], 'ts' => [], 'rx' => [], 'tx' => [],
        'rx_max' => [], 'tx_max' => [], 'samples' => [],
    ];
    $sumRx = $sumTx = 0.0;
    $pointsWithData = 0;
    $peakRx = $peakTx = 0.0;
    $totalSamples = 0;

    for ($t = $start; $t <= $end; $t += $bucket) {
        $data['epoch'][] = $t;
        $data['ts'][] = date($cfg['fmt'], $t);
        if (isset($byBucket[$t])) {
            $r = $byBucket[$t];
            $avgRx = round((float)$r['avg_rx'], 3);
            $avgTx = round((float)$r['avg_tx'], 3);
            $data['rx'][] = $avgRx;
            $data['tx'][] = $avgTx;
            $data['rx_max'][] = round((float)$r['max_rx'], 3);
            $data['tx_max'][] = round((float)$r['max_tx'], 3);
            $data['samples'][] = (int)$r['samples'];
            $sumRx += $avgRx;
            $sumTx += $avgTx;
            $pointsWithData++;
            $peakRx = max($peakRx, (float)$r['max_rx']);
            $peakTx = max($peakTx, (float)$r['max_tx']);
            $totalSamples += (int)$r['samples'];
        } else {
            $data['rx'][] = null;
            $data['tx'][] = null;
            $data['rx_max'][] = null;
            $data['tx_max'][] = null;
            $data['samples'][] = 0;
        }
    }

    return [
        'range' => $range,
        'label' => $cfg['label'],
        'bucket_seconds' => $bucket,
        'data' => $data,
        'summary' => [
            'avg_rx' => $pointsWithData ? round($sumRx / $pointsWithData, 3) : null,
            'avg_tx' => $pointsWithData ? round($sumTx / $pointsWithData, 3) : null,
            'peak_rx' => $pointsWithData ? round($peakRx, 3) : null,
            'peak_tx' => $pointsWithData ? round($peakTx, 3) : null,
            'samples' => $totalSamples,
            'points_with_data' => $pointsWithData,
            'points_total' => count($data['ts']),
        ],
        'coverage' => $coverage,
    ];
}
