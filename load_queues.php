<?php
require('routeros_api.class.php');
require('config.php');

// Function to format bandwidth (e.g., 200000000 to 200M)
function formatBandwidth($value) {
    if (!is_numeric($value) || $value == 0) {
        return 'N/A';
    }

    $units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    $magnitude = floor(log($value, 1000));
    $magnitude = min($magnitude, count($units) - 1); // Cap at Gbps
    $value /= pow(1000, $magnitude);

    // Round to 2 decimal places, remove trailing .00
    $value = round($value, 2);
    $value = rtrim(sprintf('%.2f', $value), '0.');
    
    return $value . $units[$magnitude];
}

// Function to format max-limit (e.g., 200000000/200000000 to 200M/200M)
function formatMaxLimit($maxLimit) {
    if (empty($maxLimit) || $maxLimit === 'none/none') {
        return 'N/A';
    }
    
    list($upload, $download) = explode('/', $maxLimit, 2);
    $upload = formatBandwidth($upload);
    $download = formatBandwidth($download);
    
    return "$upload/$download";
}

$API = new RouterosAPI();
if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
    $API->write('/queue/simple/print');
    $queues = $API->read();

    foreach ($queues as $q) {
        $status = isset($q['disabled']) && $q['disabled'] === 'true' ? 'disabled' : 'enabled';
        $toggleLabel = $status === 'disabled' ? ' Enable' : ' Disable';
        $toggleAction = $status === 'disabled' ? 'enable' : 'disable';
        $target = $q['target'] ?? 'N/A'; // Fallback for missing target
        $maxLimit = formatMaxLimit($q['max-limit'] ?? 'N/A'); // Format max-limit

        echo '<div class="col-md-4">
                <div class="queue-card border rounded p-3 mb-3 ' . ($status === 'enabled' ? 'enabled' : 'disabled') . '">
                    <div class="queue-details">
                        <b>' . htmlspecialchars($q['name']) . '</b><br>
                        <span class="">Target:</span> ' . htmlspecialchars($target) . '<br>
                        <span class="">Max Limit:</span> ' . htmlspecialchars($maxLimit) . '<br>
                        <span class="">Status:</span> 
                        <span class="status-badge badge bg-' . ($status === 'enabled' ? 'success' : 'secondary') . '">' . $status . '</span>
                    </div>
                    <div class="queue-actions mt-3 d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-secondary" 
                            onclick="openEdit(\'' . htmlspecialchars($q['.id']) . '\', \'' . htmlspecialchars($target) . '\', \'' . htmlspecialchars($q['max-limit'] ?? 'N/A') . '\', \'' . htmlspecialchars($q['name']) . '\')"> Edit</button>
                        <button class="btn btn-sm btn-secondary"
                            onclick="toggleQueueStatus(\'' . htmlspecialchars($q['.id']) . '\', \'' . $toggleAction . '\')">' . $toggleLabel . '</button>
                        <button class="btn btn-sm btn-danger"
                            onclick="deleteQueue(\'' . htmlspecialchars($q['.id']) . '\')"> Delete</button>
                        <button class="btn btn-sm btn-secondary"
                            onclick="showUserChart(\'' . addslashes($target) . '\', \'' . addslashes($q['name']) . '\', \'' . addslashes($q['.id']) . '\')">
                             Traffic
                        </button>
                    </div>
                </div>
            </div>';
    }

    $API->disconnect();
} else {
    echo '<div class="col-12"><p class="text-danger">❌ Failed to connect to MikroTik</p></div>';
}
?>