// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initSidebar();
    initLocationDots();
    initCharts();
    setupEventListeners();
    
    // Start auto-refresh
    startAutoRefresh();
});

function initSidebar() {
    // Sidebar toggle for mobile
    document.getElementById('sidebarToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
}

function initLocationDots() {
    function getStatusColor(status) {
        switch (status) {
            case 'up': return 'bg-success';
            case 'down': return 'bg-danger';
            case 'stale': return 'bg-secondary';
            case 'unseen': return 'bg-dark';
            default: return 'bg-info';
        }
    }

    function renderLocationDots(locationsToRender) {
        const container = document.getElementById('location-dots-container');
        container.innerHTML = '';

        for (const locationName in locationsToRender) {
            const location = locationsToRender[locationName];
            const dot = document.createElement('div');
            dot.className = `location-dot cursor-pointer transition-all duration-200 ease-in-out ${getStatusColor(location.status)}`;
            dot.title = locationName;
            dot.dataset.locationName = locationName;

            dot.addEventListener('mouseenter', (event) => {
                const hoveredLoc = currentLocationData.locations[event.target.dataset.locationName];
                const detailsBox = document.getElementById('details-box');
                
                document.getElementById('details-location-name').textContent = event.target.dataset.locationName;
                document.getElementById('details-ip').textContent = hoveredLoc.public_ip;
                const statusEl = document.getElementById('details-status');
                statusEl.textContent = hoveredLoc.status.charAt(0).toUpperCase() + hoveredLoc.status.slice(1);
                statusEl.className = `fw-semibold ${getStatusColor(hoveredLoc.status).replace('bg-', 'text-')}`;
                document.getElementById('details-cpu').textContent = `${hoveredLoc.cpu_usage}%`;
                document.getElementById('details-ping').textContent = `${hoveredLoc.ping}ms`;
                document.getElementById('details-bandwidth').textContent = `${(hoveredLoc.bandwidth_usage / (1024 * 1024)).toFixed(2)} MB`;
                document.getElementById('details-uptime').textContent = `${hoveredLoc.uptime}`;

                const dotRect = event.target.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();

                let left = dotRect.left - containerRect.left + dotRect.width / 2;
                let top = dotRect.top - containerRect.top + dotRect.height / 2;

                // Adjust tooltip position
                const tooltipWidth = detailsBox.offsetWidth;
                const tooltipHeight = detailsBox.offsetHeight;

                if (left + tooltipWidth > containerRect.width) {
                    left = dotRect.left - containerRect.left - tooltipWidth - 15;
                }
                if (top + tooltipHeight > containerRect.height) {
                    top = dotRect.top - containerRect.top - tooltipHeight - 15;
                }

                detailsBox.style.left = `${left}px`;
                detailsBox.style.top = `${top}px`;
                detailsBox.classList.remove('d-none');
            });

            dot.addEventListener('mouseleave', () => {
                document.getElementById('details-box').classList.add('d-none');
            });

            container.appendChild(dot);
        }
    }

    function filterAndRender() {
        const selectedGroup = document.getElementById('group-by').value;
        const selectedColor = document.getElementById('color-by').value;
        
        let locationsToRender = {};

        for (const locationName in currentLocationData.locations) {
            const location = currentLocationData.locations[locationName];
            
            // Group filter
            if (selectedGroup !== 'None') {
                const group = currentLocationData.groups[selectedGroup];
                if (!group || !group.includes(locationName)) {
                    continue;
                }
            }

            // Color filter
            if (selectedColor === 'Live' && location.status !== 'up') {
                continue;
            }
            if (selectedColor === 'Down' && location.status !== 'down') {
                continue;
            }

            locationsToRender[locationName] = location;
        }
        renderLocationDots(locationsToRender);
    }

    // Initial render
    filterAndRender();

    // Set up event listeners for filters
    document.getElementById('group-by').addEventListener('change', filterAndRender);
    document.getElementById('color-by').addEventListener('change', filterAndRender);
}

function initCharts() {
    // Ping Gauge
    const pingGauge = new Chart(document.getElementById('pingGauge'), {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [nodeData.stats.avg_ping, 100 - nodeData.stats.avg_ping],
                backgroundColor: [
                    nodeData.stats.avg_ping < 50 ? '#10b981' : (nodeData.stats.avg_ping < 100 ? '#f59e0b' : '#ef4444'),
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

    // Uptime Chart
    const uptimeCtx = document.getElementById('uptimeChart').getContext('2d');
    const uptimeChart = new Chart(uptimeCtx, {
        type: 'bar',
        data: {
            labels: ['Ziauddin Hospitals', 'Education', 'Corporate'],
            datasets: [{
                label: 'Uptime %',
                data: [
                    calculateGroupAverage('Ziauddin Hospitals'),
                    calculateGroupAverage('Education'),
                    calculateGroupAverage('Corporate')
                ],
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

    function calculateGroupAverage(groupName) {
        const groupLocations = nodeData.groups[groupName];
        if (!groupLocations || groupLocations.length === 0) return 0;
        
        let total = 0;
        let count = 0;
        
        groupLocations.forEach(locationName => {
            if (nodeData.locations[locationName] && nodeData.locations[locationName].uptime) {
                total += nodeData.locations[locationName].uptime;
                count++;
            }
        });
        
        return count > 0 ? Math.round(total / count) : 0;
    }
}

function setupEventListeners() {
    // Fullscreen toggle
    document.querySelectorAll('.grid-item-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const card = this.closest('.grid-item');
            if (!document.fullscreenElement) {
                if (card.requestFullscreen) {
                    card.requestFullscreen();
                } else if (card.webkitRequestFullscreen) {
                    card.webkitRequestFullscreen();
                } else if (card.msRequestFullscreen) {
                    card.msRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        });
    });
}

function startAutoRefresh() {
    // Live Ping Updates
    function updatePing(ip, locationName) {
        fetch(`ping_gateway.php?ip=${ip}`)
            .then(response => response.json())
            .then(data => {
                if (data.ping) {
                    const pingElements = document.querySelectorAll(`.location-card h3`);
                    pingElements.forEach(el => {
                        if (el.textContent.includes(locationName)) {
                            const parentCard = el.closest('.location-card');
                            const pingElement = parentCard.querySelector('.text-success, .text-warning, .text-danger');
                            if (pingElement) {
                                pingElement.textContent = `${data.ping}ms`;
                                pingElement.className = '';
                                if (data.ping < 50) {
                                    pingElement.classList.add('text-success');
                                } else if (data.ping < 100) {
                                    pingElement.classList.add('text-warning');
                                } else {
                                    pingElement.classList.add('text-danger');
                                }
                            }
                            
                            parentCard.className = `location-card ${data.status}`;
                            parentCard.classList.add(data.status);
                            
                            const statusBadge = parentCard.querySelector('.status-badge');
                            if (statusBadge) {
                                statusBadge.className = `status-badge ${data.status}`;
                                statusBadge.innerHTML = `<i class="fas fa-${data.status === 'up' ? 'check-circle' : 'times-circle'}"></i> ${data.status.charAt(0).toUpperCase() + data.status.slice(1)}`;
                            }
                        }
                    });
                }
            })
            .catch(error => console.error('Error updating ping:', error));
    }
    
    // Update all pings
    function updateAllPings() {
        Object.entries(currentLocationData.locations).forEach(([name, location]) => {
            updatePing(location.public_ip, name);
        });
        
        // Update the timestamp
        const now = new Date();
        document.getElementById('lastUpdateTime').textContent = `Last updated: ${now.toLocaleTimeString()}`;
    }
    
    // Initial update
    updateAllPings();
    
    // Update every 10 seconds
    setInterval(updateAllPings, 10000);
}