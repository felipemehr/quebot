/**
 * QueBot - Render System
 * Handles creation, storage and display of rich visualizations
 */

const Renders = {
    // Store render in backend and get URL
    async save(type, title, html) {
        try {
            const response = await fetch('api/render.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type, title, html })
            });
            
            if (!response.ok) {
                console.error('Render save failed:', response.status);
                return null;
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Render API returned non-JSON:', text.substring(0, 100));
                return null;
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error saving render:', error);
            return null;
        }
    },

    // Open render in sidebar
    openInSidebar(url, title) {
        const panel = document.getElementById('previewPanel');
        const content = document.getElementById('previewContent');
        const titleEl = document.getElementById('previewTitle');
        
        if (!panel || !content) return;
        
        titleEl.textContent = title || 'Visualización';
        content.innerHTML = `<iframe src="${url}" style="width:100%;height:100%;border:none;border-radius:8px;background:#fff;"></iframe>`;
        panel.classList.add('open');
        
        const toggle = document.getElementById('previewToggle');
        if (toggle) toggle.classList.add('active');
    },

    closeSidebar() {
        const panel = document.getElementById('previewPanel');
        if (panel) panel.classList.remove('open');
        
        const toggle = document.getElementById('previewToggle');
        if (toggle) toggle.classList.remove('active');
    },

    createButton(label, icon, onClick) {
        const btn = document.createElement('button');
        btn.className = 'render-btn';
        btn.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                ${icon}
            </svg>
            <span>${label}</span>
        `;
        btn.onclick = onClick;
        return btn;
    },

    // Generate map HTML from locations data
    generateMapHtml(locations, options = {}) {
        const center = options.center || this.calculateCenter(locations);
        const zoom = options.zoom || 11;
        const title = options.title || 'Ubicaciones';
        
        const markersJs = locations.map((loc, i) => {
            const color = this.getPriceColor(loc.price);
            const popupContent = `
                <div class="popup-title">${this.escapeHtml(loc.title || 'Propiedad')}</div>
                ${loc.price ? '<div class="popup-price">$' + (loc.price/1000000).toFixed(0) + ' millones</div>' : ''}
                ${loc.size ? '<div class="popup-size">📐 ' + this.escapeHtml(loc.size) + '</div>' : ''}
                ${loc.description ? '<div class="popup-desc">' + this.escapeHtml(loc.description) + '</div>' : ''}
                ${loc.url ? '<a href="' + this.escapeHtml(loc.url) + '" target="_blank" class="popup-link">Ver detalles →</a>' : ''}
            `.replace(/\n/g, '').replace(/'/g, "\\'");
            
            return `
                L.circleMarker([${loc.lat}, ${loc.lng}], {
                    radius: 12,
                    fillColor: '${color}',
                    color: '#fff',
                    weight: 3,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(map).bindPopup('${popupContent}');
            `;
        }).join('\n');
        
        return `
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                html, body { width: 100%; height: 100%; }
                #map { width: 100%; height: 100%; }
                .popup-title { font-weight: bold; color: #2d5a27; margin-bottom: 8px; font-size: 14px; }
                .popup-price { font-size: 16px; font-weight: bold; color: #1a73e8; margin-bottom: 6px; }
                .popup-size { color: #666; margin-bottom: 6px; font-size: 13px; }
                .popup-desc { font-size: 12px; color: #444; margin-bottom: 10px; line-height: 1.4; }
                .popup-link { 
                    display: inline-block; 
                    background: #1a73e8; 
                    color: white !important; 
                    padding: 6px 12px; 
                    border-radius: 4px; 
                    text-decoration: none; 
                    font-size: 12px;
                }
                .popup-link:hover { background: #1557b0; }
                .legend {
                    background: white;
                    padding: 12px 16px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                    font-size: 13px;
                    line-height: 1.8;
                }
                .legend h4 { margin-bottom: 8px; color: #333; font-size: 14px; }
                .legend-item { display: flex; align-items: center; gap: 8px; }
                .legend-color {
                    width: 14px;
                    height: 14px;
                    border-radius: 50%;
                    border: 2px solid white;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
                }
            </style>
            <div id="map"></div>
            <script>
                // Wait for DOM to be ready
                document.addEventListener('DOMContentLoaded', function() {
                    initMap();
                });
                
                // Also try immediately in case DOMContentLoaded already fired
                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    setTimeout(initMap, 100);
                }
                
                var mapInitialized = false;
                
                function initMap() {
                    if (mapInitialized) return;
                    mapInitialized = true;
                    
                    var map = L.map('map', {
                        center: [${center[0]}, ${center[1]}],
                        zoom: ${zoom},
                        zoomControl: true
                    });
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap',
                        maxZoom: 18
                    }).addTo(map);
                    
                    ${markersJs}
                    
                    // Add legend
                    var legend = L.control({ position: 'bottomright' });
                    legend.onAdd = function() {
                        var div = L.DomUtil.create('div', 'legend');
                        div.innerHTML = '<h4>🏡 Precios</h4>' +
                            '<div class="legend-item"><div class="legend-color" style="background:#22c55e"></div> Hasta $30M</div>' +
                            '<div class="legend-item"><div class="legend-color" style="background:#3b82f6"></div> $30M - $80M</div>' +
                            '<div class="legend-item"><div class="legend-color" style="background:#f59e0b"></div> $80M - $150M</div>' +
                            '<div class="legend-item"><div class="legend-color" style="background:#ef4444"></div> Más de $150M</div>';
                        return div;
                    };
                    legend.addTo(map);
                    
                    // Fix tile loading issue - invalidate size after a delay
                    setTimeout(function() {
                        map.invalidateSize();
                    }, 200);
                    
                    // Also invalidate on window resize
                    window.addEventListener('resize', function() {
                        map.invalidateSize();
                    });
                }
            </script>
        `;
    },

    // Generate chart HTML
    generateChartHtml(type, data, options = {}) {
        const chartId = 'chart_' + Date.now();
        const configJson = JSON.stringify({
            type: type,
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: !!options.title, text: options.title || '' }
                },
                ...options.chartOptions
            }
        });
        
        return `
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                html, body { width: 100%; height: 100%; background: #fff; }
                #chart-container { width: 100%; height: 100%; padding: 20px; }
                canvas { width: 100% !important; height: 100% !important; }
            </style>
            <div id="chart-container">
                <canvas id="${chartId}"></canvas>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var ctx = document.getElementById('${chartId}').getContext('2d');
                    new Chart(ctx, ${configJson});
                });
            </script>
        `;
    },

    // Generate table HTML
    generateTableHtml(headers, rows, options = {}) {
        const headerHtml = headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('');
        const rowsHtml = rows.map(row => {
            const cells = row.map((cell, i) => {
                let content = cell;
                if (typeof cell === 'object' && cell.url) {
                    content = `<a href="${this.escapeHtml(cell.url)}" target="_blank">${this.escapeHtml(cell.text || 'Ver')}</a>`;
                } else if (typeof cell === 'number' && headers[i].toLowerCase().includes('precio')) {
                    content = `<span class="price">$${cell.toLocaleString('es-CL')}</span>`;
                } else {
                    content = this.escapeHtml(String(cell));
                }
                return `<td>${content}</td>`;
            }).join('');
            return `<tr>${cells}</tr>`;
        }).join('');
        
        return `
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                html, body { width: 100%; height: 100%; background: #fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
                .table-container { width: 100%; height: 100%; padding: 20px; overflow: auto; }
                .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
                .data-table th { background: #f1f5f9; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
                .data-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
                .data-table tr:hover { background: #f8fafc; }
                .data-table a { color: #1a73e8; text-decoration: none; }
                .data-table a:hover { text-decoration: underline; }
                .price { font-weight: 600; color: #059669; }
            </style>
            <div class="table-container">
                <table class="data-table">
                    <thead><tr>${headerHtml}</tr></thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            </div>
        `;
    },

    calculateCenter(locations) {
        if (!locations || locations.length === 0) return [-38.9, -71.8];
        const lats = locations.map(l => l.lat);
        const lngs = locations.map(l => l.lng);
        return [
            (Math.min(...lats) + Math.max(...lats)) / 2,
            (Math.min(...lngs) + Math.max(...lngs)) / 2
        ];
    },

    getPriceColor(price) {
        if (!price) return '#3b82f6';
        if (price < 30000000) return '#22c55e';
        if (price < 80000000) return '#3b82f6';
        if (price < 150000000) return '#f59e0b';
        return '#ef4444';
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    parseRenderCommands(text) {
        const renders = [];
        const pattern = /:::render-(\w+)(?:\{([^}]*)\})?\n([\s\S]*?)\n:::/g;
        let match;
        
        while ((match = pattern.exec(text)) !== null) {
            try {
                const type = match[1];
                const attrsStr = match[2] || '';
                const content = match[3].trim();
                
                const attrs = {};
                attrsStr.split(' ').forEach(attr => {
                    const [key, val] = attr.split('=');
                    if (key && val) {
                        attrs[key] = val.replace(/"/g, '');
                    }
                });
                
                const data = JSON.parse(content);
                
                renders.push({
                    type: type,
                    title: attrs.title || data.title || 'Visualización',
                    data: data,
                    raw: match[0]
                });
            } catch (e) {
                console.error('Error parsing render command:', e);
            }
        }
        
        return renders;
    },

    async processMessage(text, container) {
        const renders = this.parseRenderCommands(text);
        let processedText = text;
        
        for (const render of renders) {
            let html = '';
            
            switch (render.type) {
                case 'map':
                    html = this.generateMapHtml(render.data.locations, render.data);
                    break;
                case 'chart':
                    html = this.generateChartHtml(render.data.type, render.data.data, render.data);
                    break;
                case 'table':
                    html = this.generateTableHtml(render.data.headers, render.data.rows, render.data);
                    break;
                default:
                    continue;
            }
            
            const result = await this.save(render.type, render.title, html);
            
            if (result && result.url) {
                const buttonId = 'render_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
                const buttonHtml = `<button class="render-btn" id="${buttonId}" data-url="${result.url}" data-title="${this.escapeHtml(render.title)}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        ${this.getIconForType(render.type)}
                    </svg>
                    <span>${this.escapeHtml(render.title)}</span>
                </button>`;
                
                processedText = processedText.replace(render.raw, buttonHtml);
            } else {
                processedText = processedText.replace(render.raw, '');
            }
        }
        
        return processedText;
    },

    getIconForType(type) {
        switch (type) {
            case 'map':
                return '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>';
            case 'chart':
                return '<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>';
            case 'table':
                return '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/>';
            default:
                return '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/>';
        }
    },

    init() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.render-btn');
            if (btn) {
                const url = btn.dataset.url;
                const title = btn.dataset.title;
                if (url) {
                    this.openInSidebar(url, title);
                }
            }
        });
        
        const closeBtn = document.getElementById('closePreview');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeSidebar());
        }
        
        const toggleBtn = document.getElementById('previewToggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                const panel = document.getElementById('previewPanel');
                if (panel) {
                    panel.classList.toggle('open');
                    toggleBtn.classList.toggle('active');
                }
            });
        }
    }
};

document.addEventListener('DOMContentLoaded', () => Renders.init());
window.Renders = Renders;
