<?php

$configPath = __DIR__.'/config/config.php';
if (!file_exists($configPath)) {
    die("Config file not found at: $configPath");
}
require_once $configPath;

// 2. Load Mikrotik API class (from parent root)
$apiPath = __DIR__.'/routeros_api.class.php';
if (!file_exists($apiPath)) {
    die("RouterOS API class not found at: $apiPath");
}
require_once $apiPath;

// 3. Load required functions (from includes)
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/ping_functions.php';



// 5. Get additional data
$nodeData = getMikrotikData();
$cpuRaw = $liveData['stats']['average_cpu_usage'] ?? '0%';
$cpuFloat = floatval(str_replace('%', '', $cpuRaw));
$jsonData = json_encode($liveData);

// 6. Calculate bandwidth metrics
$totalBandwidth = 0;
$locationCount = 0;

foreach ($liveData['locations'] as $location) {
    $totalBandwidth += $location['bandwidth_usage'];
    $locationCount++;
}

$avgBandwidthMbps = 0;
if ($locationCount > 0) {
    $avgBandwidthMbps = ($totalBandwidth / $locationCount) / (1024 * 1024);
}

// 7. Start output buffering
ob_start();

// 8. Include header (from includes)
require_once __DIR__.'/includes/header.php';

// 9. Include sidebar (from includes)
require_once __DIR__.'/includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <?php
    // 10. Include dashboard header
    require_once __DIR__.'/includes/dashboard_header.php';
    
    require_once __DIR__.'/includes/location_overview.php';
    // 11. Include ping status
    require_once __DIR__.'/includes/ping_status.php';
    
    // 12. Include dashboard stats
    require_once __DIR__.'/includes/dashboard_stats.php';
    
    // 13. Include alerts
    require_once __DIR__.'/includes/alert_section.php';
    
    // 14. Include locations
    ?>
    
    <!-- Last Updated -->
    <div class="last-updated" id="lastUpdateTime">
        Last updated: <?= date('H:i:s') ?>
    </div>
</div>

<?php
// 15. Include footer (from includes)
require_once __DIR__.'/includes/footer.php';

// 16. Flush output buffer
ob_end_flush();