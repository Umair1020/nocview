<?php

session_start();

// Disable caching
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ⛔ Not logged in? Redirect before anything loads — no flash
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=login.php">
    <script>window.location.replace("login.php");</script></head><body></body></html>';
    exit;
}

require('routeros_api.class.php');
$API = new RouterosAPI();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>MikroTik Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  window.addEventListener("pageshow", function (event) {
    if (event.persisted || performance.getEntriesByType("navigation")[0]?.type === "back_forward") {
      // If not logged in, force redirect
      fetch('check_session.php')
        .then(res => res.json())
        .then(data => {
          if (!data.logged_in) {
            window.location.href = "login.php";
          }
        });
    }
  });
</script>

  <style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f4f4; }
    .sidebar {
      height: 100vh; width: 200px; position: fixed;
      background-color: #343a40; color: white; padding-top: 20px;
    }
    .sidebar h4 { padding-left: 20px; }
    .sidebar a {
      color: white; text-decoration: none; padding: 10px 20px; display: block;
    }
    .sidebar a:hover { background-color: #495057; }
    .header {
      background-color:#343a40; color: white; padding: 15px 20px;
      display: flex; justify-content: space-between; align-items: center;
      margin-left: 200px;
    }
    .content { margin-left: 200px; padding: 20px; }
    .logout-btn {
      background-color: #dc3545; border: none;
      padding: 8px 15px; color: white; border-radius: 4px; text-decoration: none;
    }
  </style>
</head>
<body>

<div class="sidebar">
  <h4>📡 MikroTik</h4>
  <a href="#" onclick="showTab('dashboard')">📊 Dashboard</a>
  <a href="#" onclick="showTab('queues')">📶 Manage Queues</a>
</div>

<div class="header">
  <h5>MikroTik Dashboard</h5>
  <div class="card p-3 mb-3" style="width: 300px; background-color: #f1f1f1; border-left: 5px solid #007bff;">
  <div id="ping-box" style="font-weight: bold; color: #333;">Checking...</div>
</div>

  <a href="logout.php" class="logout-btn">Logout</a>
</div>

<div class="content">
  <div id="dashboard-tab">
    <h5>📈 Realtime Charts Here</h5>
   <div id="table-area"></div>
<!-- <h5>🌐 Top 5 Websites</h5>
<table class="table table-bordered" id="top-sites">
  <thead>
    <tr>
      <th>#</th>
      <th>Domain</th>
      <th>IP Address</th>
      <th>Connections</th>
    </tr>
  </thead>
  <tbody></tbody>
</table> -->

  <!-- <canvas id="bandwidthChart" width="1000" height="400"></canvas> -->

<hr>
<div class="row justify-content-center align-items-center">
  <!-- Live Chart -->
  <div class=" col-lg-5 ">
    <h5>🔴 Realtime Chart</h5>
    <canvas id="bandwidthChart" style="width:100%; height:300px;"></canvas>
  </div>

  <!-- History Chart + Dropdown -->
<div class="col-12 col-md-6">
  <h5>⏱️ History Chart</h5>
  <select id="history-range" class="form-select mb-2" style="max-width: 200px;">
    <option value="1">Last 1 Hour</option>
    <option value="24" selected>Last 24 Hours</option>
    <option value="48">Last 48 Hours</option>
  </select>
  <canvas id="historyChart" style="width:100%; height:300px;"></canvas>
</div>


</div>
  </div>

  <div id="queues-tab" style="display: none;">
    <div class="d-flex justify-content-between mb-2">
      <h5>📶 Simple Queues</h5>
      <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addQueueModal">➕ Add Queue</button>
    </div>
    <div id="queue-list">Loading...</div>
  </div>
</div>




<!-- Edit Modal -->
<div class="modal fade" id="editQueueModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5>Edit Queue</h5></div>
      
      <div class="modal-body">
        <input type="hidden" id="edit-id">
        <div class="mb-2">
          <label>Queue Name</label>
          <input type="text" id="edit-name" class="form-control">
        </div>
        <div class="mb-2">
          <label>Target</label>
          <input type="text" id="edit-target" class="form-control" readonly>
        </div>
        <div class="mb-2">
          <label>Max Limit (e.g. 5M/2M)</label>
          <input type="text" id="edit-max-limit" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button onclick="updateQueue()" class="btn btn-primary">💾 Save</button>
      </div>
    </div>
  </div>
</div>
<!-- User Chart Modal -->
<!-- 📊 Queue Traffic Modal -->


<div class="modal fade" id="trafficModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 id="traffic-title" class="modal-title">Live Traffic</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <canvas id="trafficChart" style="width: 100%; height: 300px;"></canvas>
      </div>
    </div>
  </div>
</div>




<!-- Add Queue Modal -->
<div class="modal fade" id="addQueueModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5>Add Queue</h5></div>
     <div class="modal-body">
  <div class="mb-2">
    <label>Queue Name</label>
    <input type="text" id="add-name" class="form-control" placeholder="e.g. user1-limit">
  </div>
  <div class="mb-2">
    <label>Target IP</label>
    <input type="text" id="add-target" class="form-control" placeholder="e.g. 192.168.88.100">
  </div>
  <div class="mb-2">
    <label>Max Limit (e.g. 5M/2M)</label>
    <input type="text" id="add-max-limit" class="form-control" placeholder="e.g. 5M/2M">
  </div>
</div>

      <div class="modal-footer">
        <button onclick="addQueue()" class="btn btn-success">➕ Create</button>
      </div>
    </div>
  </div>
</div>


<script>
const historyCtx = document.getElementById('historyChart').getContext('2d');

let historyChart = new Chart(historyCtx, {
    type: 'line',
    data: {
        labels: [],
        datasets: [
            {
                label: '📥 RX Mbps',
                data: [],
                borderColor: '#36a2eb',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 2
            },
            {
                label: '📤 TX Mbps',
                data: [],
                borderColor: '#ff6384',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 2
            }
        ]
    },
    options: {
        responsive: true,
        animation: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function (ctx) {
                        return `${ctx.dataset.label}: ${ctx.formattedValue} Mbps`;
                    }
                }
            }
        },
        scales: {
            x: {
                title: { display: true, text: 'Time' }
            },
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Mbps' }
            }
        }
    }
});

function loadHistoryChart(hours = 24) {
    fetch(`history-data.php?hours=${hours}`)
        .then(res => res.json())
        .then(data => {
            historyChart.data.labels = data.labels;
            historyChart.data.datasets[0].data = data.rx;
            historyChart.data.datasets[1].data = data.tx;
            historyChart.update();
        })
        .catch(err => console.error("History data fetch error:", err));
}

// Initial load
loadHistoryChart();

// On range change
document.getElementById('history-range').addEventListener('change', function () {
    loadHistoryChart(this.value);
});

</script>

<script>
const ctx = document.getElementById('bandwidthChart').getContext('2d');
const labels = [];
const rxData = [];
const txData = [];

const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: '📥 RX Mbps',
                data: rxData,
                borderColor: '#36a2eb',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                fill: true,
                tension: 0.4,
                pointRadius: 0
            },
            {
                label: '📤 TX Mbps',
                data: txData,
                borderColor: '#ff6384',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                fill: true,
                tension: 0.4,
                pointRadius: 0
            }
        ]
    },
    options: {
        animation: false,
        responsive: true,
        scales: {
            x: { title: { display: true, text: 'Time' } },
            y: { beginAtZero: true, title: { display: true, text: 'Mbps' } }
        }
    }
});

setInterval(() => {
    fetch('bandwidth-data.php?interface=ether3-WAN-Sec')
        .then(res => res.json())
        .then(data => {
            const now = new Date().toLocaleTimeString();

            if (labels.length > 30) {
                labels.shift();
                rxData.shift();
                txData.shift();
            }

            labels.push(now);
            rxData.push(data.rx ?? 0);
            txData.push(data.tx ?? 0);
            chart.update();
        });
}, 2000);

function updateTable() {
    fetch('bandwidth-table.php?interface=ether3-WAN-Sec')
        .then(res => res.text())
        .then(html => {
            document.getElementById('table-area').innerHTML = html;
        });
}
updateTable();
setInterval(updateTable, 1000);

</script>
<script>
function showTab(tab) {
  document.getElementById('dashboard-tab').style.display = (tab === 'dashboard') ? 'block' : 'none';
  document.getElementById('queues-tab').style.display = (tab === 'queues') ? 'block' : 'none';
  if (tab === 'queues') loadQueues();
}

function loadQueues() {
  fetch('load_queues.php')
    .then(res => res.text())
    .then(html => document.getElementById('queue-list').innerHTML = html);
}

function openEdit(id, target, maxLimit, name) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-target').value = target;
  document.getElementById('edit-max-limit').value = maxLimit;
  document.getElementById('edit-name').value = name;
  new bootstrap.Modal(document.getElementById('editQueueModal')).show();
}
// function updateQueue() {
//   const id = document.getElementById('edit-id').value;
//   const name = document.getElementById('edit-name').value;
//   const limit = document.getElementById('edit-max-limit').value;

//   fetch('update_queue.php', {
//     method: 'POST',
//     headers: { 'Content-Type': 'application/json' },
//     body: JSON.stringify({ id, name, limit })
//   })
//   .then(res => res.json())
//   .then(json => {
//     if (json.success) {
//       alert('✅ Queue Updated');
//       loadQueues();
//       bootstrap.Modal.getInstance(document.getElementById('editQueueModal')).hide();
//     } else {
//       alert('❌ Update failed: ' + (json.message || 'Unknown error'));
//     }
//   });
// }

function updateQueue() {
  const id = document.getElementById('edit-id').value;
  const name = document.getElementById('edit-name').value;
  const limit = document.getElementById('edit-max-limit').value;

  fetch('update_queue.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, name, limit })
  })
  .then(async (res) => {
    if (!res.ok) throw new Error("HTTP error " + res.status);

    const data = await res.json();
    if (data.success) {
      alert('✅ Queue Updated');

      // Hide modal
      const modalEl = document.getElementById('editQueueModal');
      const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
      modal.hide();

      loadQueues(); // Reload queue list
    } else {
      alert('❌ Update failed: ' + (data.message || 'Unknown error'));
    }
  })
  .catch((err) => {
    console.error('❌ Fetch error:', err);
    alert('❌ Network error or invalid response');
  });
}






function addQueue() {
  const name = document.getElementById('add-name').value;
  const target = document.getElementById('add-target').value;
  const limit = document.getElementById('add-max-limit').value;

  fetch('add_queue.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, target, limit })
  }).then(() => {
    loadQueues();
    bootstrap.Modal.getInstance(document.getElementById('addQueueModal')).hide();
  });
}


function fetchPing() {
  fetch('ping_gateway.php')
    .then(res => res.json())
    .then(data => {
      const pingBox = document.getElementById('ping-box');
      if (data.status === 'up') {
        pingBox.innerHTML = `✅ Gateway Ping: ${data.latency}`;
        pingBox.style.color = 'green';
      } else if (data.status === 'down') {
        pingBox.innerHTML = '❌ Gateway not reachable';
        pingBox.style.color = 'red';
      } else {
        pingBox.innerHTML = '⚠️ MikroTik connection error';
        pingBox.style.color = 'orange';
      }
    })
    .catch(err => {
      document.getElementById('ping-box').innerHTML = '⚠️ Error';
    });
}

// Run every second
setInterval(fetchPing, 1000);
fetchPing(); // Initial call


function toggleQueueStatus(id, action) {
  const confirmMsg = action === 'enable' ? 'Are you sure to enable this queue?' : 'Disable this queue?';
  if (!confirm(confirmMsg)) return;

  fetch('toggle_queue.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action })
  })
  .then(res => res.json())
  .then(json => {
    if (json.success) {
      alert('✅ Queue status updated');
      loadQueues();
    } else {
      alert('❌ Failed: ' + json.message);
    }
  })
  .catch(err => {
    console.error('❌ Toggle error:', err);
    alert('❌ Failed to toggle queue');
  });
}
function deleteQueue(id) {
  if (!confirm("Are you sure you want to delete this queue?")) return;

  fetch('delete_queue.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  })
  .then(res => res.json())
  .then(json => {
    if (json.success) {
      alert('✅ Queue deleted');
      loadQueues();
    } else {
      alert('❌ Delete failed: ' + (json.message || 'Unknown error'));
    }
  })
  .catch(err => {
    console.error('❌ Delete error:', err);
    alert('❌ Failed to send delete request');
  });
}

let trafficChart = null;
let trafficInterval = null;

function showUserChart(target, name) {
  // Modal title set karo
  document.getElementById('traffic-title').innerText = `📊 Live Traffic: ${name} (${target})`;

  const ctx = document.getElementById('trafficChart').getContext('2d');

  // Purana chart destroy karo
  if (trafficChart) {
    trafficChart.destroy();
  }

  trafficChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        {
          label: '📥 RX Mbps',
          data: [],
          borderColor: '#007bff',
          backgroundColor: 'rgba(0, 123, 255, 0.1)',
          fill: true,
          tension: 0.4
        },
        {
          label: '📤 TX Mbps',
          data: [],
          borderColor: '#dc3545',
          backgroundColor: 'rgba(220, 53, 69, 0.1)',
          fill: true,
          tension: 0.4
        }
      ]
    },
    options: {
      responsive: true,
      animation: false,
      scales: {
        x: { title: { display: true, text: 'Time' } },
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Mbps' }
        }
      }
    }
  });

  // Interval band karo agar already chal raha ho
  if (trafficInterval) clearInterval(trafficInterval);

  trafficInterval = setInterval(() => {
    fetch(`get_queue_traffic.php?target=${target}`)
      .then(res => res.json())
      .then(data => {
        const now = new Date().toLocaleTimeString();
        trafficChart.data.labels.push(now);
        trafficChart.data.datasets[0].data.push(data.rx);
        trafficChart.data.datasets[1].data.push(data.tx);

        if (trafficChart.data.labels.length > 30) {
          trafficChart.data.labels.shift();
          trafficChart.data.datasets[0].data.shift();
          trafficChart.data.datasets[1].data.shift();
        }

        trafficChart.update();
      });
  }, 1000);

  // Show the modal
  const modal = new bootstrap.Modal(document.getElementById('trafficModal'));
  modal.show();

  // 🛑 Stop fetching if modal is closed
  document.getElementById('trafficModal').addEventListener('hidden.bs.modal', () => {
    if (trafficInterval) clearInterval(trafficInterval);
  }, { once: true });
}



</script>
<script>
  // Handle back/forward button to avoid showing cached page
  window.addEventListener("pageshow", function (event) {
    if (event.persisted || performance.getEntriesByType("navigation")[0]?.type === "back_forward") {
      // Force reload to check session again
      location.reload();
    }
  });
</script>




</body>
</html>
