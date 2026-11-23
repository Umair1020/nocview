<!DOCTYPE html>
<html>
<head>
    <title>Bandwidth Chart</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body.dark { background-color: #121212; color: white; }
        body.light { background-color: white; color: black; }
    </style>
</head>
<body class="dark">

<h2>Bandwidth Monitor</h2>
<select id="location"></select>
<select id="range">
    <option value="live">Live</option>
    <option value="24h">Last 24 Hours</option>
    <option value="7d">Last 7 Days</option>
    <option value="30d">Last 30 Days</option>
</select>

<canvas id="bandwidthChart"></canvas>

<script>
let chart;
const ctx = document.getElementById('bandwidthChart').getContext('2d');

function fetchData() {
    const loc = document.getElementById('location').value;
    const range = document.getElementById('range').value;
    fetch(`get_bandwidth_data.php?location=${loc}&range=${range}`)
        .then(res => res.json())
        .then(data => {
            const labels = data.map(d => d.time);
            const values = data.map(d => d.bandwidth);

            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: `Bandwidth Usage (Mbps) - ${loc}`,
                        data: values,
                        borderColor: 'blue',
                        fill: false
                    }]
                }
            });
        });
}

// Live update every 5 seconds if range = live
setInterval(() => {
    if (document.getElementById('range').value === 'live') {
        fetchData();
    }
}, 5000);

// Populate location dropdown (you can load from PHP)
const locations = <?php echo json_encode(array_keys($locationMap)); ?>;
let locSelect = document.getElementById('location');
locations.forEach(loc => {
    let opt = document.createElement('option');
    opt.value = loc;
    opt.innerText = loc;
    locSelect.appendChild(opt);
});

document.getElementById('location').addEventListener('change', fetchData);
document.getElementById('range').addEventListener('change', fetchData);

fetchData();
</script>
</body>
</html>
