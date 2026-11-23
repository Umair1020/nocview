<?php
require('routeros_api.class.php');
require_once(__DIR__ . '/db_connect.php');
require_once(__DIR__ . '/config.php');

$client = getCurrentClient();
$MT_USER = $client['MT_USER'];
$MT_PASS = $client['MT_PASS'];
$router_ip = $client['locations'][0]['ip'];

$API = new RouterosAPI();
$API->debug = false;
$logs = [];
$error = '';

try {
  if ($API->connect($router_ip, $MT_USER, $MT_PASS)) {
    $logs = $API->comm("/log/print", []);
    $API->disconnect();
  }
} catch (Exception $e) {
  $error = $e->getMessage();
}

/**
 * Same keyword list in PHP for server-side detection.
 * Keep in sync with JS list above.
 */
$alert_keywords = [
  'attack',
  'attacked',
  'denied',
  'blocked',
  'failed',
  'failure',
  'invalid',
  'invalid user',
  'authentication failure',
  'auth failure',
  'port scan',
  'scan',
  'brute',
  'bruteforce',
  'brute-force',
  'ssh failed',
  'telnet failed',
  'login failed',
  'failed login',
  'drop',
  'dropped',
  'icmp flood',
  'syn flood',
  'tcp syn',
  'blocked by',
  'firewall drop',
  'udp flood',
  'dos',
  'ddos'
];

function is_alert_log_php($text, $keywords)
{
  if (!$text)
    return false;
  $lower = mb_strtolower($text);
  foreach ($keywords as $k) {
    if (mb_stripos($lower, $k) !== false)
      return true;
  }
  return false;
}

function esc($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8">
  <title>All Mikrotik Logs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    a.back {
      display: inline-block;
      margin-bottom: 10px;
      color: #333;
      text-decoration: none;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }

    th,
    td {
      padding: 8px 10px;
      border: 1px solid #ddd;
      text-align: left;
      vertical-align: top;
    }

    tr:hover {
      background: rgba(0, 120, 255, 0.03);
    }

    /* 
    th {
      background: #eee;
    } */

    .alert-row {
      background-color: rgba(255, 50, 50, 0.08);
      color: #8b0000;
      font-weight: 600;
    }

    .alert-row td {
      border-left: 4px solid #d32f2f;
      padding-left: 6px;
    }

    :root {
      /* Dark Theme Variables */
      --dark-bg: #121212;
      --dark-card-bg: #1e1e1e;
      --dark-text: #f8f9fa;
      --dark-text-muted: #adb5bd;
      --dark-border: #333;
      --dark-logo: url('/noclogo1.png');
      --dark-spotcomm-logo: url('/logo1.png');
      --dark-primary: #0062ff;
      --dark-secondary: #6366f1;
      --dark-success: #10b981;
      --dark-warning: #f59e0b;
      --dark-danger: #ef4444;
      --dark-highlight: #60a5fa;
      --dark-tooltip-bg: #2c2c2c;
      /* Light Theme Variables */
      --light-bg: #f8f9fa;
      --light-card-bg: #ffffff;
      --light-text: #212529;
      --light-logo: url('/nclogo.png');
      --light-spotcomm-logo: url('/logo.png');
      --light-text-muted: #6c757d;
      --light-border: #dee2e6;
      --light-primary: #0d6efd;
      --light-secondary: #6c757d;
      --light-success: #198754;
      --light-warning: #ffc107;
      --light-danger: #dc3545;
      --light-highlight: #1a56db;
      --light-tooltip-bg: #ffffff;
      /* Default to dark theme */
      --bg: var(--dark-bg);
      --card-bg: var(--dark-card-bg);
      --text: var(--dark-text);
      --text-muted: var(--dark-text-muted);
      --border: var(--dark-border);
      --primary: var(--dark-primary);
      --secondary: var(--dark-secondary);
      --logo: var(--dark-logo);
      --spotcomm-logo: var(--dark-spotcomm-logo);
      --success: var(--dark-success);
      --warning: var(--dark-warning);
      --danger: var(--dark-danger);
      --highlight: var(--dark-highlight);
      --tooltip-bg: var(--dark-tooltip-bg);
      /* RGB for opacity */
      --primary-rgb: 0, 98, 255;
      --success-rgb: 16, 185, 129;
      --warning-rgb: 245, 158, 11;
      --danger-rgb: 239, 68, 68;
      --card-bg-rgb: 30, 30, 30;
    }

    body.light-mode {
      --bg: var(--light-bg);
      --card-bg: var(--light-card-bg);
      --text: var(--light-text);
      --text-muted: var(--light-text-muted);
      --border: var(--light-border);
      --primary: var(--light-primary);
      --secondary: var(--light-secondary);
      --logo: var(--light-logo);
      --spotcomm-logo: var(--light-spotcomm-logo);
      --success: var(--light-success);
      --warning: var(--light-warning);
      --danger: var(--light-danger);
      --highlight: var(--light-highlight);
      --tooltip-bg: var(--light-tooltip-bg);
      --primary-rgb: 13, 110, 253;
      --success-rgb: 25, 135, 84;
      --warning-rgb: 255, 193, 7;
      --danger-rgb: 220, 53, 69;
      --card-bg-rgb: 255, 255, 255;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg);
      color: var(--text);
      min-height: 100vh;
      transition: background-color 0.3s, color 0.3s;
      margin: 0;
    }

    .sidebar {
      background-color: var(--card-bg);
      border-right: 1px solid var(--border);
      width: 250px;
      height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      overflow-y: hidden;
    }

    .sidebar .nav-link {
      color: var(--text);
      padding: 10px 15px;
      border-radius: 6px;
      margin: 5px 10px;
      transition: all 0.2s;
      font-weight: 500;
    }

    .sidebar .nav-link:hover {
      background: rgba(var(--primary-rgb), 0.1);
      color: var(--primary);
    }

    .sidebar .nav-link.active {
      background-color: var(--primary);
      color: white;
    }

    hr {
      border-color: var(--border);
    }

    .logo-container {
      width: 100%;
      height: 80px;
      background-image: var(--logo);
      background-size: contain;
      background-repeat: no-repeat;
      background-position: center;
      transition: background-image 0.3s;
    }

    .spotcomm-logo-container {
      background-color: var(--card-bg);
      transition: background-color 0.3s;
      width: 70%;
      justify-content: center;
      display: flex;
      height: 80px;
      background-image: var(--spotcomm-logo);
      background-size: contain;
      background-repeat: no-repeat;
      background-position: center;
    }

    .theme-toggle {
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      background-color: #ffffff;
      color: #000000;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      border: none;
      transition: all 0.3s;
      z-index: 1000;
    }

    body.light-mode .theme-toggle {
      background-color: #000000;
      color: #ffffff;
    }

    .theme-toggle:hover {
      transform: scale(1.1);
    }

    .theme-toggle i {
      font-size: 1.2rem;
    }

    .sidebar-toggle {
      display: none;
      position: fixed;
      top: 10px;
      left: 10px;
      z-index: 1050;
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .logs-section {
      margin-top: 30px;
    }

    .card {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      padding: 15px;
    }

    .section-title {
      margin-bottom: 10px;
      font-size: 18px;
      font-weight: 600;
    }

    .table-responsive {
      overflow-x: auto;
    }

     .logs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .logs-table th,
    .logs-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #ddd;
        text-align: left;
        vertical-align: top;
    }

    .logs-table tbody tr:hover {
        background: rgba(0, 120, 255, 0.03);
    }

    /* alert (attack) row style */
    .alert-row {
        background-color: rgba(255, 50, 50, 0.08) !important;
        color: var(--text);
        font-weight: 600;
    }

    .alert-row td {
        border-left: 4px solid #d32f2f;
        padding-left: 6px;
    }

    .status-text {
        font-size: 13px;
        color: gray;
        text-align: right;
        margin-top: 5px;
    }

    @media (prefers-color-scheme: dark) {
        .card {
            background: #1f1f1f;
            color: #e0e0e0;
        }

        .logs-table th,
        .logs-table td {
            border-color: #333;
        }

        .alert-row {
            background-color: rgba(255, 50, 50, 0.12) !important;
            color: #ffb3b3;
        }
    }

    /* small responsive tweaks */
    @media (max-width: 600px) {

        .logs-table th:nth-child(1),
        .logs-table td:nth-child(1) {
            width: 24px;
        }

        .logs-table th:nth-child(2),
        .logs-table td:nth-child(2) {
            white-space: nowrap;
        }
    }
    /* alert (attack) row style */
  
    .status-text {
      font-size: 13px;
      color: gray;
      text-align: right;
      margin-top: 5px;
    }

    @media (prefers-color-scheme: dark) {
      .card {
        background: #1f1f1f;
        color: #e0e0e0;
      }

      .logs-table th,
      .logs-table td {
        border-color: #333;
      }

      .alert-row {
        background-color: rgba(255, 50, 50, 0.12) !important;
        color: #ffb3b3;
      }
    }



    .main-content {
      margin-left: 250px;
      width: calc(100% - 250px);
      padding: 15px;
    }

    @media (max-width: 992px) {
      .sidebar {
        left: -250px;
        transition: left 0.3s;
      }

      .sidebar.show {
        left: 0;
      }

      .sidebar-toggle {
        display: block;
      }

      .main-content {
        margin-left: 0;
        width: 100%;
      }
    }

    /* Additional styles from provided CSS */
    .nav-tabs {
      border-bottom: 1px solid var(--border);
    }

    .nav-tabs .nav-link {
      color: var(--text-muted);
      border: 1px solid transparent;
      border-bottom: none;
      padding: 10px 15px;
      font-weight: 500;
    }

    .nav-tabs .nav-link:hover {
      border-color: transparent;
      color: var(--success);
    }

    .nav-tabs .nav-link.active {
      color: var(--success);
      background-color: transparent;
      border-color: var(--border) var(--border) var(--bg);
      border-bottom: none;
    }
  </style>
</head>

<body>
  <div class="d-flex">
    <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
      <a href="index.php" class="d-flex align-items-center mb-3">
        <div class="logo-container"></div>
      </a>
      <hr>
      <ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item"><a href="index.php" class="nav-link "><i class="fas fa-tachometer-alt"></i>
            Overview</a></li>
        <li><a href="locations.php" class="nav-link"><i class="fas fa-network-wired"></i> All Locations</a></li>
        <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
        <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
        <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations Analytics</a></li>
        <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location
            Information</a></li>

      </ul>
      <div class="spotcomm-logo-container mt-auto"></div>
    </div>
    <div class="main-content">
      <div class="d-flex justify-content-between">
        <h2>All Mikrotik Logs</h2>
        <a class="back btn btn-primary text-light" href="index.php">← Back</a>
      </div>

      <?php if (!empty($error)): ?>
        <p style="color:red;">Error: <?= esc($error) ?></p>
      <?php elseif (empty($logs)): ?>
        <p>No logs found.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Time</th>
              <th>Topics</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $logs = array_reverse($logs); // latest first
            foreach ($logs as $i => $log):
              $time = isset($log['time']) ? $log['time'] : '-';
              // Attempt to format time; if unparseable, show raw
              $timeFormatted = '-';
              if ($time !== '-') {
                $ts = strtotime($time);
                if ($ts !== false && $ts !== -1)
                  $timeFormatted = date('Y-m-d H:i:s', $ts);
                else
                  $timeFormatted = $time;
              }
              $topics = $log['topics'] ?? '';
              $message = $log['message'] ?? '';

              $isAlert = is_alert_log_php($topics, $alert_keywords) || is_alert_log_php($message, $alert_keywords);
              ?>
              <tr class="<?= $isAlert ? 'alert-row' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= esc($timeFormatted) ?></td>
                <td><?= esc($topics ?: '-') ?></td>
                <td><?= esc($message ?: '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</body>

</html>