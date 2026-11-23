<?php
// File: tools/sangfor_actions_poller.php
// Free, always-on poller for the Sangfor sites - no VPS, no PC left running.
//
// It runs inside a GitHub Actions runner (or any machine with net-snmp + PHP-CLI):
//   1. SNMP-reads every configured Sangfor interface  -> baseline counters
//   2. waits --gap seconds (default 20)               -> second counter sample
//   3. computes RX/TX Mbps + ICMP ping
//   4. POSTs the batch to snmp_push.php on the website (same endpoint the PC
//      pusher uses, so history/charts stay identical for both)
//
// Because each run takes its own two samples, no counter state has to be kept
// between runs - a CI runner that starts fresh every 5 minutes works fine.
//
// Usage:
//   php tools/sangfor_actions_poller.php                 # run + POST
//   php tools/sangfor_actions_poller.php --probe         # only test SNMP reachability
//   php tools/sangfor_actions_poller.php --dry-run       # poll + print, no POST
//   php tools/sangfor_actions_poller.php --gap=20
//
// Environment:
//   NOCVIEW_PUSH_URL             e.g. https://nocview.spotcommglobal.com/snmp_push.php
//   NOCVIEW_PUSH_TOKEN           same token as push_config.local.php on the website
//   NOCVIEW_SNMP_COMMUNITIES     JSON: {"103.246.109.194":"COMMUNITY", ...}
//   NOCVIEW_SNMP_CONFIG_FILE     alternative: path to an snmp_config.local.php
//   NOCVIEW_INTERVAL_SEC         cadence the run represents (default 300)

require_once __DIR__ . '/../collector_common.php';

if (!collectorIsCli()) {
    http_response_code(404);
    exit;
}

set_time_limit(0);
@ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Karachi');

require_once __DIR__ . '/../router_poll.php';
require_once __DIR__ . '/../push_ingest_lib.php';
require_once __DIR__ . '/actions_lib.php';
require_once __DIR__ . '/../config.php';
global $clientConfig;
// config.php sets a 30 s web limit; this run needs ~60 s (two samples + POST).
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('display_errors', '0');

function actionsFail($message) {
    $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
    fwrite($err, $message . "\n");
    exit(1);
}

/**
 * Append a per-site summary to the GitHub Actions step summary (when running
 * in a workflow), so the result is visible on the Actions page itself - not
 * only in the raw log. Silently does nothing anywhere else.
 */
function actionsStepSummary(array $lines) {
    $file = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($file) && $file !== '') {
        @file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND);
    }
}

$args = collectorParseArgs($argv ?? [], ['gap', 'url', 'token', 'ticks']);
if (isset($args['help'])) {
    echo "Usage: php tools/sangfor_actions_poller.php [--probe] [--dry-run] [--gap=20]\n";
    exit(0);
}

$probe = isset($args['probe']);
$dryRun = isset($args['dry-run']) || $probe;
$gap = collectorIntOption($args, 'gap', 20, 5, 120);
$url = is_string($args['url'] ?? null) ? $args['url'] : (string)getenv('NOCVIEW_PUSH_URL');
$token = is_string($args['token'] ?? null) ? $args['token'] : (string)getenv('NOCVIEW_PUSH_TOKEN');
$interval = (int)(getenv('NOCVIEW_INTERVAL_SEC') ?: 300);
$interval = max(60, min(3600, $interval));
$runNumber = (int)($args['ticks'] ?? getenv('GITHUB_RUN_NUMBER') ?: 0);

$sites = pushAllowedTargets($clientConfig);
if ($sites === []) {
    actionsFail('No Sangfor sites in config.php pingLocations');
}

// ---------------------------------------------------------------- SNMP config
$configFile = null;
$tmpConfig = null;
$communitiesJson = (string)getenv('NOCVIEW_SNMP_COMMUNITIES');
$configFileEnv = (string)getenv('NOCVIEW_SNMP_CONFIG_FILE');
if ($communitiesJson !== '') {
    $map = json_decode($communitiesJson, true);
    if (!is_array($map) || $map === []) {
        actionsFail('NOCVIEW_SNMP_COMMUNITIES is not a JSON object');
    }
    $entries = [];
    foreach ($map as $ip => $community) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && is_string($community) && trim($community) !== '') {
            $entries[$ip] = ['community' => trim($community)];
        }
    }
    if ($entries === []) {
        actionsFail('NOCVIEW_SNMP_COMMUNITIES has no valid ip => community pairs');
    }
    $tmpConfig = tempnam(sys_get_temp_dir(), 'nocview_snmp_');
    file_put_contents($tmpConfig, "<?php\nreturn " . var_export($entries, true) . ";\n");
    @chmod($tmpConfig, 0600);
    // The file holds SNMP communities: always remove it, whatever path we exit on.
    register_shutdown_function(function () use ($tmpConfig) {
        @unlink($tmpConfig);
    });
    $configFile = $tmpConfig;
} elseif ($configFileEnv !== '' && is_file($configFileEnv)) {
    $configFile = $configFileEnv;
} elseif (is_file(__DIR__ . '/../snmp_config.local.php')) {
    $configFile = __DIR__ . '/../snmp_config.local.php';
}

$stateDir = sys_get_temp_dir() . '/nocview_actions_state';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0700, true);
}

$snmpOptions = ['state_dir' => $stateDir];
if ($configFile !== null) {
    $snmpOptions['config_file'] = $configFile;
}
// ICMP from cloud runners is often filtered; set NOCVIEW_SKIP_ICMP=1 to rely on SNMP only.
$snmpOptions['snmp_only'] = getenv('NOCVIEW_SKIP_ICMP') === '1';

$cliFns = nocviewSnmpCliFns();
if ($cliFns !== null) {
    // Use the binaries explicitly so this works without PHP's snmp extension and
    // never reads a local push cache by accident.
    $snmpOptions['walk_fn'] = $cliFns['walk'];
    $snmpOptions['get_fn'] = $cliFns['get'];
}

// ---------------------------------------------------------------- environment report
$haveExt = function_exists('snmp2_real_walk') && function_exists('snmp2_get');
$haveCli = $cliFns !== null;
if (!$haveExt && !$haveCli) {
    actionsFail('Neither the PHP snmp extension nor net-snmp binaries (snmpget/snmpwalk) are available.');
}

collectorLog(sprintf(
    'sangfor poller: %d site(s), snmp=%s, config=%s, gap=%ss, mode=%s',
    count($sites),
    $haveExt && $cliFns === null ? 'php-ext' : 'net-snmp-cli',
    $configFile !== null ? basename($configFile) : 'env/NOCVIEW_SNMP_COMMUNITY',
    $gap,
    $probe ? 'probe' : ($dryRun ? 'dry-run' : 'push')
));

$dbg = function ($message) {
    collectorLog($message);
};

// ---------------------------------------------------------------- first sample / probe
if ($probe) {
    $rows = [];
    $okCount = 0;
    foreach ($sites as $ip => $site) {
        if (empty($site['iface'])) {
            $dbg("$ip {$site['name']}: no interface configured, skipped");
            continue;
        }
        $sample = pollSnmpInterfaceTraffic($ip, $site['iface'], array_merge($snmpOptions, ['now' => microtime(true)]));
        $ok = $sample['reason'] === 'snmp_baseline';
        $okCount += $ok ? 1 : 0;
        $dbg(sprintf(
            '%-34s %-16s %-6s %s',
            substr($site['name'], 0, 34),
            $ip,
            $site['iface'],
            $ok
                ? 'SNMP OK (counters read, baseline primed)'
                : $sample['reason']
        ));
        $rows[] = [
            'site' => str_replace('|', '/', substr($site['name'], 0, 40)),
            'iface' => $site['iface'],
            'result' => $ok ? 'SNMP OK' : $sample['reason'],
        ];
    }
    $dbg('probe finished: "snmp_baseline" means the firewall answered and the interface was found.');
    $lines = ['### Sangfor SNMP probe', '', '| Site | Interface | Result |', '| --- | --- | --- |'];
    foreach ($rows as $row) {
        $lines[] = sprintf('| %s | `%s` | %s |', $row['site'], $row['iface'], $row['result']);
    }
    if ($okCount === 0 && $rows !== []) {
        $lines[] = '';
        $lines[] = '**No site answered SNMP from this runner.** The Sangfor firewalls do not allow SNMP from';
        $lines[] = "GitHub's IP ranges - see `docs/free-24x7-github-actions.md` section 5 for the fixes";
        $lines[] = '(allow-list a static poller IP, or run `snmp_pusher.php --loop` on an always-on machine at the office).';
    }
    actionsStepSummary($lines);
    exit(0);
}

// ---------------------------------------------------------------- collect (2 samples) + send
collectorLog("collecting: first counter sample, then waiting {$gap}s for the second one...");
$collect = actionsCollect($sites, $snmpOptions, $gap);
$healthy = 0;
foreach ($collect['summary'] as $ip => $row) {
    collectorLog(sprintf(
        '%-34s %s %s',
        substr($row['name'], 0, 34),
        $row['rx_mbps'] !== null ? round((float)$row['rx_mbps'], 2) . '/' . round((float)$row['tx_mbps'], 2) . ' Mbps' : '-',
        $row['status'] . ' ' . ($row['bw_reason'] ?: '')
    ));
    if ($row['rx_mbps'] !== null) {
        $healthy++;
    }
}
$lines = ['### Sangfor collector - per-site results', '', '| Site | RX/TX | Status | Reason |', '| --- | --- | --- | --- |'];
foreach ($collect['summary'] as $ip => $row) {
    $rate = $row['rx_mbps'] !== null
        ? round((float)$row['rx_mbps'], 2) . ' / ' . round((float)$row['tx_mbps'], 2) . ' Mbps'
        : '-';
    $lines[] = sprintf('| %s | %s | %s | %s |',
        str_replace('|', '/', substr($row['name'], 0, 40)),
        $rate,
        str_replace('|', '/', $row['status']),
        str_replace('|', '/', (string)($row['bw_reason'] ?: '')));
}
if ($healthy === 0 && $collect['summary'] !== []) {
    $lines[] = '';
    $lines[] = '**SNMP unreachable for every site from this runner.** The pushes still stored the reason, but the';
    $lines[] = 'firewalls do not answer SNMP from GitHub\'s IPs - see `docs/free-24x7-github-actions.md` section 5';
    $lines[] = '(allow-list a static poller IP, or run `snmp_pusher.php --loop` on an always-on machine at the office).';
}
actionsStepSummary($lines);

if ($dryRun) {
    collectorLog('dry-run: nothing posted');
    exit(0);
}
if ($url === '' || !preg_match('#^https?://#i', $url) || $token === '') {
    actionsFail('NOCVIEW_PUSH_URL / NOCVIEW_PUSH_TOKEN are not set');
}

$payload = actionsPayload($collect['results'], actionsPollerMeta($interval, $runNumber));
$status = 0;
$body = '';
for ($attempt = 1; $attempt <= 2; $attempt++) {
    [$status, $body] = actionsSend($url, $token, $payload, 25);
    if ($status >= 200 && $status < 300) {
        break;
    }
    if ($attempt < 2) {
        sleep(3);
    }
}

collectorLog(sprintf('HTTP %d %s', $status, trim(preg_replace('/\s+/', ' ', $body))));
if ($status < 200 || $status >= 300) {
    // Failing the job makes the problem visible in the Actions tab (red run).
    exit(1);
}
