// Visualization module for QueBot
const Viz = {
    mapInstances: {},
    chartInstances: {},

    // Initialize a map with markers
    createMap: function(containerId, locations, options = {}) {
        const container = document.getElementById(containerId);
        if (!container || typeof L === 'undefined') return null;

        // Default center (Chile)
        const defaultCenter = [-38.9, -71.8];
        const defaultZoom = 9;

        // Calculate center from locations if available
        let center = defaultCenter;
        let zoom = defaultZoom;
        
        if (locations && locations.length > 0) {
            const lats = locations.map(l => l.lat);
            const lngs = locations.map(l => l.lng);
            center = [
                (Math.min(...lats) + Math.max(...lats)) / 2,
                (Math.min(...lngs) + Math.max(...lngs)) / 2
            ];
        }

        // Create map
        const map = L.map(containerId).setView(center, zoom);
        
        // Add tile layer (CartoDB dark works well)
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap © CARTO',
            maxZoom: 19
        }).addTo(map);

        // Add markers
        if (locations && locations.length > 0) {
            locations.forEach(loc => {
                const color = this.getPriceColor(loc.price);
                const marker = L.circleMarker([loc.lat, loc.lng], {
                    radius: 8,
                    fillColor: color,
                    color: '#fff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.8
                }).addTo(map);

                if (loc.title || loc.price) {
                    const popup = `
                        <div style="min-width: 150px;">
                            <strong>${loc.title || 'Propiedad'}</strong><br>
                            ${loc.location ? '<small>' + loc.location + '</small><br>' : ''}
                            ${loc.price ? '<span style="color: #00c853; font-weight: bold;">$' + (loc.price/1000000).toFixed(0) + 'M</span><br>' : ''}
                            ${loc.url ? '<a href="' + loc.url + '" target="_blank" style="color: #2196f3;">Ver más →</a>' : ''}
                        </div>
                    `;
                    marker.bindPopup(popup);
                }
            });

            // Fit bounds to markers
            if (locations.length > 1) {
                const bounds = L.latLngBounds(locations.map(l => [l.lat, l.lng]));
                map.fitBounds(bounds, { padding: [20, 20] });
            }
        }

        this.mapInstances[containerId] = map;
        return map;
    },

    // Create a chart
    createChart: function(containerId, type, data, options = {}) {
        const container = document.getElementById(containerId);
        if (!container || typeof Chart === 'undefined') return null;

        const ctx = container.getContext('2d');
        
        // Destroy existing chart
        if (this.chartInstances[containerId]) {
            this.chartInstances[containerId].destroy();
        }

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const textColor = isDark ? '#aaa' : '#666';
        const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: textColor }
                }
            },
            scales: type !== 'pie' && type !== 'doughnut' ? {
                x: { ticks: { color: textColor }, grid: { color: gridColor } },
                y: { ticks: { color: textColor }, grid: { color: gridColor } }
            } : undefined
        };

        const chart = new Chart(ctx, {
            type: type,
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartInstances[containerId] = chart;
        return chart;
    },

    // Render property cards
    renderPropertyCards: function(container, properties) {
        if (typeof container === 'string') {
            container = document.getElementById(container);
        }
        if (!container) return;

        let html = '<div class="property-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">';
        
        properties.forEach(prop => {
            const priceColor = this.getPriceColor(prop.price);
            const priceText = prop.price ? `$${(prop.price/1000000).toFixed(0)} millones` : 'Consultar precio';
            
            html += `
                <div class="property-card">
                    <div class="property-title">${prop.title || 'Propiedad'}</div>
                    <div class="property-location">📍 ${prop.location || 'Chile'}</div>
                    ${prop.size ? '<div style="color: var(--text-secondary); font-size: 0.9em;">📐 ' + prop.size + '</div>' : ''}
                    <div class="property-price" style="color: ${priceColor}">${priceText}</div>
                    ${prop.url ? '<a href="' + prop.url + '" target="_blank" rel="noopener" class="property-link">Ver en ' + (prop.source || 'sitio') + ' →</a>' : ''}
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
    },

    // Helper to get color based on price
    getPriceColor: function(price) {
        if (!price) return '#2196f3';
        if (price < 30000000) return '#00c853';
        if (price < 80000000) return '#2196f3';
        if (price < 150000000) return '#ff9800';
        return '#f44336';
    },

    // Parse visualization commands from response
    parseVizCommands: function(text) {
        const commands = [];
        
        // Look for map command: [MAP: {...}]
        const mapRegex = /\[MAP:\s*({[^}]+})\]/g;
        let match;
        while ((match = mapRegex.exec(text)) !== null) {
            try {
                commands.push({ type: 'map', data: JSON.parse(match[1]) });
            } catch (e) { console.error('Map parse error:', e); }
        }

        // Look for chart command: [CHART: {...}]
        const chartRegex = /\[CHART:\s*({[^}]+})\]/g;
        while ((match = chartRegex.exec(text)) !== null) {
            try {
                commands.push({ type: 'chart', data: JSON.parse(match[1]) });
            } catch (e) { console.error('Chart parse error:', e); }
        }

        return commands;
    }
};

// Make it globally available
window.Viz = Viz;
