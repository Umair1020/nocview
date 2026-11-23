<?php
// File: router_worker.php
// CLI only. Started by parallel_routers.php, one process per router.
// Reads one JSON job on stdin, writes one JSON result on stdout.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_time_limit(30);
ini_set('display_errors', '0');

require_once __DIR__ . '/router_poll.php';
require_once __DIR__ . '/analytics_poll.php';

$job = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($job)) {
    fwrite(STDERR, "router_worker: invalid job\n");
    exit(2);
}

try {
    switch ($job['task'] ?? '') {
        case 'live':
            $result = pollRouterLive(
                $job['ip'], $job['user'], $job['pass'], $job['iface'],
                (bool)$job['isKnoativ'], (bool)$job['reachable']
            );
            break;

        case 'ping':
            $result = pollPingLocation($job['ip'], $job['iface'] ?? null);
            break;

        case 'bw':
            $result = bw_poll_live($job['ip'], $job['iface'], $job['user'], $job['pass']);
            break;

        case 'bw_snmp':
            $result = bw_poll_snmp_live($job['ip'], $job['iface']);
            break;

        case 'analytics':
            $result = analyticsForRouter(
                $job['ip'], $job['user'], $job['pass'], $job['iface'],
                $job['location'], $job['central']
            );
            break;

        default:
            fwrite(STDERR, "router_worker: unknown task\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'router_worker: ' . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($result);
