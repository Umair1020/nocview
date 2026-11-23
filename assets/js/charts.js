let pingGauge, uptimeChart;

function initPingGauge(avgPing) {
    pingGauge = new Chart(document.getElementById('pingGauge'), {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [avgPing, 100 - avgPing],
                backgroundColor: [
                    avgPing < 50 ? '#10b981' : (avgPing < 100 ? '#f59e0b' : '#ef4444'),
                    '#334155'
                ],
                borderWidth: 0
            }]
        },
        options: {
            circumference: 180,
            rotation: 270,
            cutout: '80%',
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    });
}

function initUptimeChart(hospitalAvg, universityAvg, otherAvg) {
    uptimeChart = new Chart(document.getElementById('uptimeChart'), {
        type: 'bar',
        data: {
            labels: ['Ziauddin Hospitals', 'Education', 'Corporate'],
            datasets: [{
                label: 'Uptime %',
                data: [hospitalAvg, universityAvg, otherAvg],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(59, 130, 246, 0.7)',
                    'rgba(245, 158, 11, 0.7)'
                ],
                borderColor: [
                    'rgba(16, 185, 129, 1)',
                    'rgba(59, 130, 246, 1)',
                    'rgba(245, 158, 11, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        color: '#94a3b8',
                        callback: function(value) { return value + '%'; }
                    },
                    grid: { color: '#334155' }
                },
                x: {
                    ticks: { color: '#94a3b8' },
                    grid: { color: '#334155' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(2) + '%';
                        }
                    }
                }
            }
        }
    });
}

function initChartsWhenVisible() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const chartId = entry.target.id;
                if (chartId === 'pingGauge' && !window.pingGaugeInitialized) {
                    initPingGauge(<?php echo $nodeData['stats']['avg_ping']; ?>);
                    window.pingGaugeInitialized = true;
                }
                if (chartId === 'uptimeChart' && !window.uptimeChartInitialized) {
                    const hospitalAvg = <?php echo calculateGroupAverage($nodeData, 'Hospital'); ?>;
                    const universityAvg = <?php echo calculateGroupAverage($nodeData, 'University'); ?>;
                    const otherAvg = <?php echo calculateGroupAverage($nodeData, 'Other'); ?>;
                    initUptimeChart(hospitalAvg, universityAvg, otherAvg);
                    window.uptimeChartInitialized = true;
                }
                observer.unobserve(entry.target);
            }
        });
    });

    document.querySelectorAll('.chart-container').forEach(el => {
        observer.observe(el);
    });
}