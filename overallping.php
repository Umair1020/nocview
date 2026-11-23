<?php
// overallping.php (merged with ajax_ping.php)

// List of locations with IPs
$locations = [
    "Ziauddin Hospital Korangi Creek" => "103.246.108.50",
    "ZCR Boat Basin (Clifton)" => "103.246.109.74",
    "Ziauddin Education Board (ZEB)" => "103.246.109.82",
    "Nursing - North" => "103.246.109.98",
    "ZCRS / MT / FM (North)" => "103.246.109.106",
    "College of Intermediate" => "103.246.109.114",
    "KDLB - Kemari Hospital" => "103.246.109.122",
    "College of SLT" => "103.246.109.130",
    "MD - Home" => "103.246.109.138",
    "SLT Clinic" => "103.246.109.146",
    "Ziauddin Memorial Hospital" => "103.246.109.154",
    "Spotcomm Office" => "115.42.66.56"
];

// AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $results = [];
    $totalPing = 0;
    $count = 0;

    foreach ($locations as $name => $ip) {
        $localPing = pingHost($ip);
        $googlePing = pingHost("8.8.8.8");

        $results[] = [
            "name" => $name,
            "ip" => $ip,
            "local" => $localPing,
            "google" => $googlePing
        ];

        if (is_numeric($localPing)) {
            $totalPing += $localPing;
            $count++;
        }
    }

    $overallAvg = $count > 0 ? round($totalPing / $count, 2) : 0;

    echo json_encode([
        "overall" => $overallAvg,
        "locations" => $results,
        "generated_at" => date("H:i:s")
    ]);
    exit;
}

function pingHost($target) {
    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
        ? "ping -n 1 " . escapeshellarg($target)
        : "ping -c 1 -W 1 " . escapeshellarg($target);

    @exec($cmd, $output, $status);

    if ($status === 0) {
        foreach ($output as $line) {
            if (preg_match('/time[=<]([\d\.]+)/', $line, $matches)) {
                return (float)$matches[1];
            }
        }
    }
    return "Timeout";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Network Overview</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
<style>
:root{
    --bg:#121212; --card:#1e1e1e; --text:#f8f9fa; --muted:#adb5bd; --border:#2a2a2a;
    --primary:#0d6efd; --success:#10b981; --warn:#f59e0b; --danger:#ef4444;
              --dark-logo: url('../assets/images/noclogo1.png');
            --dark-spotcomm-logo: url('../assets/images/logo1.png');

                        --light-logo: url('../assets/images/nclogo.png');
            --light-spotcomm-logo: url('../assets/images/logo.png');

                    --logo: var(--dark-logo);
            --spotcomm-logo: var(--dark-spotcomm-logo);

             /* Default to dark theme */
           
            --logo: var(--dark-logo);
            --spotcomm-logo: var(--dark-spotcomm-logo);
           
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
            background-color: #ffffff; /* White background in dark mode */
            color: #000000; /* Black icon in dark mode */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: none;
            transition: all 0.3s;
            z-index: 1000;
        }

        body.light-mode .theme-toggle {
            background-color: #000000; /* Black background in light mode */
            color: #ffffff; /* White icon in light mode */
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        .theme-toggle i {
            font-size: 1.2rem;
        }
body.light{
    --bg:#f8f9fa; --card:#ffffff; --text:#212529; --muted:#6c757d; --border:#dee2e6;

      --logo: var(--light-logo);
            --spotcomm-logo: var(--light-spotcomm-logo);
    --primary:#0d6efd; --success:#198754; --warn:#ffc107; --danger:#dc3545;
}
body{background:var(--bg); color:var(--text); font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;}
a{text-decoration:none}
.sidebar{position:fixed;left:0;top:0;height:100vh;width:260px;background:var(--card);border-right:1px solid var(--border);padding:14px;overflow:auto}
.logo{height:70px;background-image:url('');background-repeat:no-repeat;background-position:center;background-size:contain;margin-bottom:8px}
.sidebar .nav-link{color:var(--text);padding:10px 12px;border-radius:8px;margin:6px 0;display:flex;gap:10px;align-items:center}
.sidebar .nav-link.active, .sidebar .nav-link:hover{background:var(--primary);   color: white;}
.main{margin-left:260px;padding:18px}
@media (max-width: 991px){
    .sidebar{left:-260px;transition:left .25s}
    .sidebar.show{left:0}
    .main{margin-left:0}
    .toggle-sidebar{display:block}
}
.toggle-sidebar{display:none;position:fixed;top:12px;left:12px;z-index:1100;border:none;background:var(--primary);color:#fff;width:42px;height:42px;border-radius:50%}
.theme-toggle{position:fixed;right:16px;bottom:16px;border:1px solid var(--border);background:var(--card);color:var(--text);width:48px;height:48px;border-radius:50%}

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
    display: flex
;
    height: 80px;
    background-image: var(--spotcomm-logo);
    background-size: contain;
    background-repeat: no-repeat;
    margin: 370px 2px 0px 40px;
    background-position: center;
}
.header{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px}
.card-header{border-bottom:1px solid var(--border);background:transparent; color:var(--text)}
.chart{width:100%;height:250px;color:var(--text)}
</style>
</head>
<body class="dark">
<button class="toggle-sidebar" id="btnSidebar"><i class="fa fa-bars"></i></button>
<button class="theme-toggle" id="btnTheme"><i class="fa fa-moon"></i></button>

<aside class="sidebar" id="sidebar">
       <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 text text-decoration-none">
                <div class="logo-container"></div>
            </a>
            <hr>
 
 <nav class="nav flex-column">
      <ul class="nav nav-pills flex-column mb-auto">
          <li class="nav-item">
              <a href="index.php" class="nav-link <?= $current_page == 'overallping.php' ? 'active' : '' ?>">
                  <i class="fas fa-tachometer-alt"></i>
                  Overview
              </a>
          </li>
          <li>
              <a href="locations.php" class="nav-link <?= $current_page == 'locations.php' ? 'active' : '' ?>">
                  <i class="fas fa-network-wired"></i>
                  All Locations
              </a>
          </li>
          <li>
              <a href="heatmap.php" class="nav-link <?= $current_page == 'heatmap.php' ? 'active' : '' ?>">
                  <i class="fas fa-th"></i>
                  Heatmap
              </a>
          </li>
          <li>
              <a href="bandwidth.php" class="nav-link <?= $current_page == 'bandwidth.php' ? 'active' : '' ?>">
                  <i class="fas fa-chart-line"></i>
                  Bandwidth
              </a>
          </li>
          <li>
              <a href="overallping.php" class="nav-link active <?= $current_page == 'overallping.php' ? 'active' : '' ?>">
                  <i class="fas fa-wifi"></i>
                  Ping
              </a>
          </li>
      </ul>
  </nav>
     
            <div class="spotcomm-logo-container text-center "></div>
</aside>

<main class="main">
  <div class="header d-flex align-items-center justify-content-between">
    <div>
      <h4 class="mb-1">Network Overview</h4>
      <div class="small">Local Gateway Ping & Google DNS Ping — Live</div>
    </div>
    <div class="text-end">
      <span class="small" id="lastUpdated">Loading…</span>
    </div>
  </div>

  <div class="row g-3" id="locationGrid">
    <?php foreach ($locations as $name => $ip): ?>
      <div class="col-12 col-lg-6">
        <div class="card h-100 p-2">
          <div class="card-header small"><strong><?= htmlspecialchars($name) ?></strong> (<?= $ip ?>)</div>
          <div class="card-body d-flex justify-content-between">
            <div id="chart-loc-<?= md5($ip) ?>" class="chart small"></div>
            <div id="chart-dns-<?= md5($ip) ?>" class="chart small"></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</main>

<script>
const MAX_PING = 300;

function gaugeOption(value, title) {
    return {
        series: [{
            type: 'gauge',
            min: 0,
            max: MAX_PING, // Keep your max ping value (300)
            startAngle: 180, // Adjusted to match the example's half-circle
            endAngle: 0,
            radius: '90%', // Larger radius for a more prominent gauge
            center: ['50%', '60%'], // Adjust center to align with example
            progress: {
                show: true,
                width: 10, // Thinner progress bar like in the example
                itemStyle: {
                    color: '#1890ff' // Single color from the example (blue)
                }
            },
            axisLine: {
                lineStyle: {
                    width: 10, // Thinner axis line
                    color: [[1, '#e6ebf5']] // Light gray background for axis
                }
            },
            axisTick: { show: false }, // Hide ticks
            splitLine: { show: false }, // Hide split lines
            axisLabel: { show: false }, // Hide axis labels
            pointer: { show: false }, // Hide pointer
            title: {
                show: true,
                offsetCenter: [0, '-40%'], // Position title above gauge
                fontSize: 14,
                color: 'inherit'
            },
            detail: {
                valueAnimation: true,
                formatter: '{value} ms',
                fontSize: 18,
                offsetCenter: [0, '20%'], // Position value below gauge
                color: 'inherit'
            },
            data: [{
                value: value,
                name: title
            }]
        }]
    };
}
window.__charts = {};
<?php foreach ($locations as $name => $ip): ?>
(function(){
    const locChart = echarts.init(document.getElementById('chart-loc-<?= md5($ip) ?>'));
    const dnsChart = echarts.init(document.getElementById('chart-dns-<?= md5($ip) ?>'));
    locChart.setOption(gaugeOption(0,'Local'));
    dnsChart.setOption(gaugeOption(0,'Google DNS'));
    window.__charts['<?= $ip ?>'] = {loc:locChart,dns:dnsChart};
})();
<?php endforeach; ?>

async function refresh() {
    const LAST = document.getElementById('lastUpdated');
    try {
        const res = await fetch('?ajax=1', {cache:'no-store'});
        const data = await res.json();
        LAST.textContent = 'Updated: ' + (data.generated_at || '');

        data.locations.forEach(item=>{
            const pair = window.__charts[item.ip];
            if(!pair) return;
            pair.loc.setOption(gaugeOption(item.local,'Local'));
            pair.dns.setOption(gaugeOption(item.google,'Google DNS'));
        });
    } catch(e) {
        LAST.textContent = 'Update failed';
    }
}

refresh();
setInterval(refresh,2000);

// Sidebar toggle
document.getElementById('btnSidebar').addEventListener('click',()=>{document.getElementById('sidebar').classList.toggle('show');});

// Theme toggle
document.getElementById('btnTheme').addEventListener('click',()=>{
    const isLight = document.body.classList.contains('light');
    document.body.classList.toggle('light',!isLight);
    document.body.classList.toggle('dark',isLight);
});
</script>
</body>
</html>
