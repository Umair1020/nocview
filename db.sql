CREATE DATABASE network_monitor;

USE network_monitor;

CREATE TABLE bandwidth_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    interface VARCHAR(50),
    bandwidth_usage DECIMAL(10, 2) DEFAULT 0.00,
    timestamp DATETIME NOT NULL,
    INDEX idx_ip_timestamp (ip, timestamp)
);