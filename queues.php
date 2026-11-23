<?php
ob_start();
session_start();

// Disable caching
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Redirect if not logged in or not 'cc' user
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['username'] !== 'Knoativ') {
    ob_end_clean();
    header('Location: login.php');
    exit;
}

require_once('routeros_api.class.php');
$API = new RouterosAPI();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queues Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
    :root {
        /* Dark Theme Variables */
        --dark-bg: #121212;
        --dark-card-bg: #1e1e1e;
        --dark-text: #f8f9fa;
        --dark-text-muted: #adb5bd;
        --dark-border: #333;
        --dark-logo: url('noclogo1.png');
        --dark-spotcomm-logo: url('logo1.png');
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
        --light-logo: url('nclogo.png');
        --light-spotcomm-logo: url('logo.png');
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
        /* White background in dark mode */
        color: #000000;
        /* Black icon in dark mode */
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: none;
        transition: all 0.3s;
        z-index: 1000;
    }

    body.light-mode .theme-toggle {
        background-color: #000000;
        /* Black background in light mode */
        color: #ffffff;
        /* White icon in light mode */
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


    .dashboard-header {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transition: all 0.3s;
    }

    .queue-card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
        border-left: 4px solid var(--border);
        margin-bottom: 15px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.3s;
    }

    .queue-card.enabled {
        border-left-color: var(--success);
    }

    .queue-card.disabled {
        border-left-color: var(--danger);
    }

    .queue-card:hover {
        background: rgba(var(--primary-rgb), 0.05);
        transform: translateY(-3px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .queue-card .status-badge {
        font-size: 12px;
        padding: 3px 8px;
        border-radius: 4px;
    }

    .queue-card .status-badge.enabled {
        background: rgba(var(--success-rgb), 0.1);
        color: var(--success);
    }

    .queue-card .status-badge.disabled {
        background: rgba(var(--danger-rgb), 0.1);
        color: var(--danger);
    }

    .btn-custom {
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        font-size: 14px;
    }

    .add-queue-btn {
        background: var(--success);
        color: white;
    }

    .add-queue-btn:hover {
        background: var(--success);
        filter: brightness(1.1);
    }

    .modal-content {
        background: var(--card-bg);
        color: var(--text);
        border: 1px solid var(--border);
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border);
    }

    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
        background: var(--card-bg);
        border-radius: 8px;
        padding: 10px;
        border: 1px solid var(--border);
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
            display: flex;
        }

        .main-content {
            margin-left: 0;
            width: 100%;
        }
    }
    </style>
</head>

<body class="dark-mode">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>
    <div class="sidebar d-flex flex-column flex-shrink-0 p-3">
        <a href="index.php" class="d-flex align-items-center mb-3">
            <div class="logo-container"></div>
        </a>
        <hr>
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Overview</a>
            </li>
            <li>
    <a href="locations.php" class="nav-link  <?= $current_page == 'locations.php' ? 'active' : '' ?>">
        <i class="fas fa-network-wired"></i>
        <?php 
        if (isset($_SESSION['username']) && $_SESSION['username'] === 'Knoativ') {
            echo 'All Devices';
        } else {
            echo 'All Locations';
        }
        ?>
    </a>
</li>
            <li><a href="heatmap.php" class="nav-link"><i class="fas fa-th"></i> Heatmap</a></li>
            <li><a href="bandwidth.php" class="nav-link"><i class="fas fa-chart-line"></i> Bandwidth</a></li>
            <li><a href="locations_Analytics.php" class="nav-link"><i class="fas fa-wifi"></i> Locations Analytics</a>
            </li>
            <li><a href="location_information.php" class="nav-link"><i class="fas fa-map-marker-alt"></i> Location
                    Information</a></li>
            <li><a href="queues.php" class="nav-link active"><i class="fas fa-list"></i> Queues</a></li>
        </ul>
        <div class="spotcomm-logo-container mt-auto"></div>
    </div>

    <div class="main-content">
        <div class="dashboard-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1>Queues Management</h1>
                    <p class="mb-0">Manage MikroTik Simple Queues</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="input-group mb-3" style="max-width: 300px; display: inline-flex;">
                        <span class="input-group-text"
                            style="background: var(--card-bg); border-color: var(--border); color: var(--text);">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" id="queue-search" class="form-control"
                            placeholder="Search by name or target IP"
                            style="background: var(--card-bg); color: var(--text); border-color: var(--border);">
                    </div>
                    <button class="btn-custom add-queue-btn" data-bs-toggle="modal" data-bs-target="#addQueueModal">
                        <i class="fas fa-plus"></i> Add Queue
                    </button>
                    <a href="logout.php" class="btn-custom logout-btn ms-2"><i class="fas fa-sign-out-alt"></i>
                        Logout</a>
                </div>
            </div>
        </div>

        <div id="queue-list" class="row">Loading...</div>

        <!-- Add Queue Modal -->
        <div class="modal fade" id="addQueueModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5>Add Queue</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Queue Name</label>
                            <input type="text" id="add-name" class="form-control" placeholder="e.g. user1-limit">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Target IP</label>
                            <input type="text" id="add-target" class="form-control" placeholder="e.g. 192.168.88.100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max Limit (e.g. 5M/2M)</label>
                            <input type="text" id="add-max-limit" class="form-control" placeholder="e.g. 5M/2M">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button onclick="addQueue()" class="btn btn-success">Create</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Queue Modal -->
        <div class="modal fade" id="editQueueModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5>Edit Queue</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="edit-id">
                        <div class="mb-3">
                            <label class="form-label">Queue Name</label>
                            <input type="text" id="edit-name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Target IP</label>
                            <input type="text" id="edit-target" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max Limit (e.g. 5M/2M)</label>
                            <input type="text" id="edit-max-limit" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button onclick="updateQueue()" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Traffic Modal -->
        <!-- Traffic Modal -->
        <div class="modal fade" id="trafficModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 id="traffic-title" class="modal-title">Live Traffic</h5>
                        <!-- <div class="ms-auto">
                            <select id="unitSelector" class="form-select form-select-sm"
                                onchange="changeUnit(this.value)">
                                <option value="kbps">Kbps</option>
                                <option value="mbps">Mbps</option>
                                <option value="kb">KB/s</option>
                                <option value="mb">MB/s</option>
                            </select>
                        </div> -->
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="chart-container">
                            <canvas id="trafficChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let trafficChart = null;
    let trafficInterval = null;
    let allQueues = ''; // Store the raw HTML of all queues



    // Theme toggle functionality
    function toggleTheme() {
        const body = document.body;
        const themeToggle = document.getElementById('themeToggle');
        const isDarkMode = body.classList.contains('dark-mode');

        if (isDarkMode) {
            body.classList.remove('dark-mode');
            body.classList.add('light-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            localStorage.setItem('theme', 'light');
        } else {
            body.classList.remove('light-mode');
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            localStorage.setItem('theme', 'dark');
        }
    }

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        sidebar.classList.toggle('show');
    }

    // Load saved theme on page load
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme');
        const body = document.body;
        const themeToggle = document.getElementById('themeToggle');

        if (savedTheme === 'light') {
            body.classList.remove('dark-mode');
            body.classList.add('light-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }

        // Add event listeners
        document.getElementById('themeToggle').addEventListener('click', toggleTheme);
        document.getElementById('sidebarToggle').addEventListener('click', toggleSidebar);
        document.getElementById('queue-search').addEventListener('input', filterQueues);
        loadQueues();
    });

    function loadQueues() {
        fetch('load_queues.php')
            .then(res => res.text())
            .then(html => {
                allQueues = html; // Store the full queue list
                document.getElementById('queue-list').innerHTML = html;
                filterQueues(); // Apply initial filter (if search input exists)
            })
            .catch(err => {
                console.error('Load queues error:', err);
                document.getElementById('queue-list').innerHTML =
                    '<div class="col-12"><p class="text-danger">Failed to load queues</p></div>';
            });
    }

    function filterQueues() {
        const searchTerm = document.getElementById('queue-search').value.toLowerCase();
        const queueList = document.getElementById('queue-list');

        // If no search term, show all queues
        if (!searchTerm) {
            queueList.innerHTML = allQueues;
            return;
        }

        // Create a temporary container to parse the queue HTML
        const tempContainer = document.createElement('div');
        tempContainer.innerHTML = allQueues;

        // Filter queue cards
        const queueCards = tempContainer.querySelectorAll('.col-md-4');
        let hasResults = false;
        queueList.innerHTML = ''; // Clear current list

        queueCards.forEach(card => {
            const name = card.querySelector('b').textContent.toLowerCase();
            const target = card.innerHTML.toLowerCase().includes('target:') ?
                card.innerHTML.match(/target: ([^<]+)/i)?. [1].toLowerCase() || '' :
                '';
            const maxLimit = card.innerHTML.toLowerCase().includes('max limit:') ?
                card.innerHTML.match(/max limit: ([^<]+)/i)?. [1].toLowerCase() || '' :
                '';

            if (name.includes(searchTerm) || target.includes(searchTerm) || maxLimit.includes(searchTerm)) {
                queueList.appendChild(card.cloneNode(true));
                hasResults = true;
            }
        });

        // Show message if no results found
        if (!hasResults) {
            queueList.innerHTML =
                '<div class="col-12"><p class="text-muted">No queues found matching your search.</p></div>';
        }
    }

    function openEdit(id, target, maxLimit, name) {
        document.getElementById('edit-id').value = id;
        document.getElementById('edit-target').value = target;
        document.getElementById('edit-max-limit').value = maxLimit; // Raw max-limit for editing
        document.getElementById('edit-name').value = name;
        new bootstrap.Modal(document.getElementById('editQueueModal')).show();
    }

    function updateQueue() {
        const id = document.getElementById('edit-id').value;
        const name = document.getElementById('edit-name').value;
        const limit = document.getElementById('edit-max-limit').value;

        fetch('update_queue.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id,
                    name,
                    limit
                })
            })
            .then(async res => {
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                const data = await res.json();
                if (data.success) {
                    alert('âœ… Queue Updated');
                    bootstrap.Modal.getInstance(document.getElementById('editQueueModal')).hide();
                    loadQueues();
                } else {
                    alert(' Update failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Update queue error:', err);
                alert(' Network error or invalid response');
            });
    }

    function addQueue() {
        const name = document.getElementById('add-name').value;
        const target = document.getElementById('add-target').value;
        const limit = document.getElementById('add-max-limit').value;

        fetch('add_queue.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name,
                    target,
                    limit
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('âœ… Queue Added');
                    bootstrap.Modal.getInstance(document.getElementById('addQueueModal')).hide();
                    loadQueues();
                } else {
                    alert(' Add failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Add queue error:', err);
                alert(' Network error or invalid response');
            });
    }

    function toggleQueueStatus(id, action) {
        if (!confirm(`Are you sure you want to ${action} this queue?`)) return;

        fetch('toggle_queue.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id,
                    action
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('âœ… Queue status updated');
                    loadQueues();
                } else {
                    alert(' Failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Toggle queue error:', err);
                alert(' Failed to toggle queue');
            });
    }

    function deleteQueue(id) {
        if (!confirm('Are you sure you want to delete this queue?')) return;

        fetch('delete_queue.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(' Queue deleted');
                    loadQueues();
                } else {
                    alert(' Delete failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Delete queue error:', err);
                alert(' Failed to send delete request');
            });
    }

    function showUserChart(target, name, queueId) {
        document.getElementById('traffic-title').innerText = ` Live Traffic: ${name} (${target})`;
        const ctx = document.getElementById('trafficChart').getContext('2d');

        if (trafficChart) {
            trafficChart.destroy();
        }

        trafficChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                        label: ' RX Kbps',
                        data: [],
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    },
                    {
                        label: ' TX Kbps',
                        data: [],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Kbps'
                        },
                        suggestedMax: 20000, // 20 Mbps = 20000 Kbps
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        }
                    }
                }
            }
        });

        if (trafficInterval) clearInterval(trafficInterval);

        // Immediate first call
        fetchTrafficData(target, queueId);

        // Then set interval
        trafficInterval = setInterval(() => {
            fetchTrafficData(target, queueId);
        }, 2000);

        const modal = new bootstrap.Modal(document.getElementById('trafficModal'));
        modal.show();

        document.getElementById('trafficModal').addEventListener('hidden.bs.modal', () => {
            if (trafficInterval) {
                clearInterval(trafficInterval);
                trafficInterval = null;
            }
        }, {
            once: true
        });
    }

    // Separate function for fetching traffic data
    function fetchTrafficData(target, queueId) {
        let url = `get_queue_traffic.php?target=${encodeURIComponent(target)}`;
        if (queueId) {
            url += `&queue_id=${encodeURIComponent(queueId)}`;
        }

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    console.error('Traffic fetch error:', data.error);
                    // Don't return, continue with zero values
                }

                const now = new Date().toLocaleTimeString();
                const rxValue = data.rx || 0;
                const txValue = data.tx || 0;

                // Add data to chart
                trafficChart.data.labels.push(now);
                trafficChart.data.datasets[0].data.push(rxValue);
                trafficChart.data.datasets[1].data.push(txValue);

                // Keep only last 20 data points
                if (trafficChart.data.labels.length > 20) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }

                trafficChart.update('none'); // 'none' for better performance

                console.log(`Updated at ${now}: RX=${rxValue} Kbps, TX=${txValue} Kbps`, data);
            })
            .catch(err => {
                console.error('Traffic fetch error:', err.message);
                // Continue with zero values even on error
                const now = new Date().toLocaleTimeString();
                trafficChart.data.labels.push(now);
                trafficChart.data.datasets[0].data.push(0);
                trafficChart.data.datasets[1].data.push(0);

                if (trafficChart.data.labels.length > 20) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }

                trafficChart.update('none');
            });
    }

    let currentUnit = 'kbps'; // Default unit

    function showUserChart(target, name, queueId) {
        document.getElementById('traffic-title').innerText = ` Live Traffic: ${name} (${target})`;
        const ctx = document.getElementById('trafficChart').getContext('2d');

        if (trafficChart) {
            trafficChart.destroy();
        }

        // Get unit from selector or use default
        const unitSelector = document.getElementById('unitSelector');
        currentUnit = unitSelector ? unitSelector.value : 'kbps';

        trafficChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                        label: ` RX ${currentUnit.toUpperCase()}`,
                        data: [],
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    },
                    {
                        label: ` TX ${currentUnit.toUpperCase()}`,
                        data: [],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: getYAxisLabel(currentUnit)
                        },
                        suggestedMax: getSuggestedMax(currentUnit),
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        }
                    }
                }
            }
        });

        if (trafficInterval) clearInterval(trafficInterval);

        // Immediate first call
        fetchTrafficData(target, queueId, currentUnit);

        // Then set interval
        trafficInterval = setInterval(() => {
            fetchTrafficData(target, queueId, currentUnit);
        }, 2000);

        const modal = new bootstrap.Modal(document.getElementById('trafficModal'));
        modal.show();

        document.getElementById('trafficModal').addEventListener('hidden.bs.modal', () => {
            if (trafficInterval) {
                clearInterval(trafficInterval);
                trafficInterval = null;
            }
        }, {
            once: true
        });
    }

    // Helper function for Y-axis label
    function getYAxisLabel(unit) {
        const labels = {
            'kbps': 'Kbps',
            'mbps': 'Mbps',
            'kb': 'KB/s',
            'mb': 'MB/s'
        };
        return labels[unit] || 'Kbps';
    }

    // Helper function for suggested max based on unit
    function getSuggestedMax(unit) {
        const maxValues = {
            'kbps': 20000, // 20 Mbps
            'mbps': 20, // 20 Mbps
            'kb': 2500, // 20 Mbps = ~2500 KB/s
            'mb': 2.5 // 20 Mbps = ~2.5 MB/s
        };
        return maxValues[unit] || 20000;
    }

    // Separate function for fetching traffic data
    function fetchTrafficData(target, queueId, unit = 'kbps') {
        let url = `get_queue_traffic.php?target=${encodeURIComponent(target)}&unit=${unit}`;
        if (queueId) {
            url += `&queue_id=${encodeURIComponent(queueId)}`;
        }

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    console.error('Traffic fetch error:', data.error);
                    // Don't return, continue with zero values
                }

                const now = new Date().toLocaleTimeString();
                const rxValue = data.rx || 0;
                const txValue = data.tx || 0;

                // Update chart labels with current unit
                trafficChart.data.datasets[0].label = ` RX ${data.unit?.toUpperCase() || 'Kbps'}`;
                trafficChart.data.datasets[1].label = ` TX ${data.unit?.toUpperCase() || 'Kbps'}`;

                // Add data to chart
                trafficChart.data.labels.push(now);
                trafficChart.data.datasets[0].data.push(rxValue);
                trafficChart.data.datasets[1].data.push(txValue);

                // Keep only last 20 data points
                if (trafficChart.data.labels.length > 20) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }

                trafficChart.update('none');

                console.log(
                    `Updated at ${now}: RX=${rxValue} ${data.unit?.toUpperCase() || 'Kbps'}, TX=${txValue} ${data.unit?.toUpperCase() || 'Kbps'}`,
                    data);
            })
            .catch(err => {
                console.error('Traffic fetch error:', err.message);
                // Continue with zero values even on error
                const now = new Date().toLocaleTimeString();
                trafficChart.data.labels.push(now);
                trafficChart.data.datasets[0].data.push(0);
                trafficChart.data.datasets[1].data.push(0);

                if (trafficChart.data.labels.length > 20) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }

                trafficChart.update('none');
            });
    }

    // Function to change unit dynamically
    function changeUnit(newUnit) {
        currentUnit = newUnit;
        if (trafficChart) {
            trafficChart.destroy();
            // You might want to restart the traffic monitoring with new unit
        }
    }
    </script>
</body>

</html>
<?php ob_end_flush(); ?>