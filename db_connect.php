<?php
// File: /public_html/db_connect.php
$serverName = $_SERVER['SERVER_NAME'] ?? '';
if ($serverName === 'localhost' || $serverName === '127.0.0.1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error_log.txt');
}

class Database {
      private $host = "localhost";
    private $username = "root";
    private $password = "";
    private $database = "network_master";

    private $mysqli;
    private static $instance = null;

    public function __construct() {
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $this->mysqli = new mysqli($this->host, $this->username, $this->password, $this->database);
            if ($this->mysqli->connect_error) {
                throw new Exception("Database connection failed: " . $this->mysqli->connect_error);
            }
            $this->mysqli->set_charset("utf8mb4");
            $this->mysqli->query("SET time_zone = '+05:00'"); // Optional, for Pakistan timezone
        } catch (Exception $e) {
            error_log("DB Error: " . $e->getMessage() . " in " . $e->getFile() . " at line " . $e->getLine());
            // Diagnostic pages define this so they can render "database down"
            // instead of echoing JSON and dying.
            if (defined('NOCVIEW_DB_SOFT_FAIL') && NOCVIEW_DB_SOFT_FAIL) {
                throw $e;
            }
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json');
            echo json_encode([
                'error' => ($serverName === 'localhost'
                    ? 'Database connection error: ' . $e->getMessage()
                    : 'Database connection error. Please try again later.')
            ]);
            exit;
        }
    }

    public function getConnection() {
        return $this->mysqli;
    }

    public function closeConnection() {
        if ($this->mysqli) {
            $this->mysqli->close();
            self::$instance = null;
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
}

function indexExists($mysqli, $table, $indexName) {
    try {
        $result = $mysqli->query("SHOW INDEX FROM $table WHERE Key_name = '$indexName'");
        if ($result === false) {
            error_log("Index check failed for table $table, index $indexName: " . $mysqli->error);
            return false;
        }
        return $result && $result->num_rows > 0;
    } catch (Exception $e) {
        error_log("indexExists error: " . $e->getMessage());
        return false;
    }
}

function initializeDatabase() {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    
    // Alerts Table
    $query = "CREATE TABLE IF NOT EXISTS alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        location VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('warning', 'critical') NOT NULL,
        status ENUM('new', 'acknowledged', 'resolved') DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_alerts_type (type),
        INDEX idx_alerts_status (status),
        INDEX idx_alerts_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $mysqli->query($query);
    
    // Locations Table
    $query = "CREATE TABLE IF NOT EXISTS locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        type ENUM('hospital', 'university', 'corporate', 'other') NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ip (ip_address),
        INDEX idx_location_type (type),
        INDEX idx_location_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $mysqli->query($query);
    
    // Network Cache
    $query = "CREATE TABLE IF NOT EXISTS network_status_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        location VARCHAR(255) NOT NULL,
        bandwidth_usage DECIMAL(10,2) DEFAULT 0,
        status BOOLEAN DEFAULT FALSE,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ip (ip),
        INDEX idx_network_status (status),
        INDEX idx_network_last_updated (last_updated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $mysqli->query($query);

    // Bandwidth Logs (for history) - one row per location/interface per sample
    $query = "CREATE TABLE IF NOT EXISTS bandwidth_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(100) NOT NULL,
    interface VARCHAR(50) NOT NULL,
    rx_mbps DECIMAL(10,3) DEFAULT 0,
    tx_mbps DECIMAL(10,3) DEFAULT 0,
    status TINYINT DEFAULT 0,
    logged_at DATETIME NOT NULL,
    INDEX idx_location_interface (location, interface),
    INDEX idx_logged_at (logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $mysqli->query($query);

    // Composite index used by the bandwidth history queries (location + interface + time range)
    if (!indexExists($mysqli, 'bandwidth_logs', 'idx_bw_loc_iface_time')) {
        $mysqli->query("CREATE INDEX idx_bw_loc_iface_time ON bandwidth_logs (location, interface, logged_at)");
    }
}

// Initialize database when this file is included
initializeDatabase();

function getDBConnection() {
    return Database::getInstance()->getConnection();
}

function logAlert($location, $message, $type = 'warning') {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("INSERT INTO alerts (location, message, type) VALUES (?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return false;
    }
    $stmt->bind_param("sss", $location, $message, $type);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function logBandwidthData($location, $interface, $rx_mbps, $tx_mbps, $status) {
    $mysqli = getDBConnection();

    // status column is TINYINT: 1 = up, 0 = down / no data
    $statusInt = ($status === 1 || $status === true || $status === 'up') ? 1 : 0;
    $rx_mbps = (float)$rx_mbps;
    $tx_mbps = (float)$tx_mbps;

    $stmt = $mysqli->prepare("INSERT INTO bandwidth_logs (location, interface, rx_mbps, tx_mbps, status, logged_at)
                              VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        error_log("Failed to prepare statement for bandwidth log: " . $mysqli->error);
        return false;
    }

    $stmt->bind_param("ssddi", $location, $interface, $rx_mbps, $tx_mbps, $statusInt);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log("Failed to execute bandwidth log: " . $stmt->error);
    }
    $stmt->close();
    return $ok;
}

// Stores a sample only when no sample for this location/interface was stored in the last $minGapSeconds.
// Lets both the cron collector and the live page write to the same history without creating duplicates.
function logBandwidthSampleIfDue($location, $interface, $rx_mbps, $tx_mbps, $status, $minGapSeconds = 45) {
    $mysqli = getDBConnection();
    $gap = max(1, (int)$minGapSeconds);

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM bandwidth_logs
                              WHERE location = ? AND interface = ?
                                AND logged_at >= NOW() - INTERVAL $gap SECOND");
    if (!$stmt) {
        error_log("Prepare failed (bandwidth due check): " . $mysqli->error);
        return false;
    }
    $stmt->bind_param("ss", $location, $interface);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['c'] ?? 0) > 0) {
        return false; // a fresh sample already exists
    }
    return logBandwidthData($location, $interface, $rx_mbps, $tx_mbps, $status);
}

?>
