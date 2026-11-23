CREATE TABLE network_status_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(15) NOT NULL,
    location VARCHAR(255) NOT NULL,
    bandwidth_usage DECIMAL(10,2) DEFAULT 0,
    status BOOLEAN DEFAULT FALSE,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip)
);