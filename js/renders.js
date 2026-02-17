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
            
            if (!response.ok) throw new Error('Failed to save render');
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
        
        titleEl.textContent = title || 'Visualizacion';
        content.innerHTML = `<iframe src="${url}" style="width:100%;height:100%;border:none;border-radius:8px;"></iframe>`;
        panel.classList.add('open');
        
        // Update toggle button state
        const toggle = document.getElementById('previewToggle');
        if (toggle) toggle.classList.add('active');
    },

    // Close sidebar
    closeSidebar() {
        const panel = document.getElementById('previewPanel');
        if (panel) panel.classList.remove('open');
        
        const toggle = document.getElementById('previewToggle');
        if (toggle) toggle.classList.remove('active');
    },

    // Create a render button for inline display
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
        
        // Generate markers JavaScript
        const markersJs = locations.map((loc, i) => {
            const color = this.getPriceColor(loc.price);
            const popupContent = `
                <div class="popup-title">${this.escapeHtml(loc.title || 'Propiedad')}</div>
                ${loc.price ? '<div class="popup-price">$' + (loc.price/1000000).toFixed(0) + ' millones</div>' : ''}
                ${loc.size ? '<div class="popup-size">\ud83d\udcd0 ' + this.escapeHtml(loc.size) + '</div>' : ''}
                ${loc.description ? '<div class="popup-desc">' + this.escapeHtml(loc.description) + '</div>' : ''}
                ${loc.url ? '<a href="' + this.escapeHtml(loc.url) + '" target="_blank" class="popup-link">Ver detalles \u2192</a>' : ''}
            `.replace(/\n/g, '');
            
            return `
                L.circleMarker([${loc.lat}, ${loc.lng}], {
                    radius: 10,
                    fillColor: '${color}',
                    color: '#fff',
                    weight: 3,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(map).bindPopup(\`${popupContent}\`);
            `;
        }).join('\n');
        
        return `
            <div id="map"></div>
            <script>
                const map = L.map('map').setView([${center[0]}, ${center[1]}], ${zoom});
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '\u00a9 OpenStreetMap'
                }).addTo(map);
                
                ${markersJs}
                
                // Add legend
                const legend = L.control({ position: 'bottomright' });
                legend.onAdd = function() {
                    const div = L.DomUtil.create('div', 'legend');
                    div.innerHTML = \`
                        <h4>\ud83c\udfe1 Precios</h4>
                        <div class="legend-item"><div class="legend-color" style="background:#22c55e"></div> Hasta $30M</div>
                        <div class="legend-item"><div class="legend-color" style="background:#3b82f6"></div> $30M - $80M</div>
                        <div class="legend-item"><div class="legend-color" style="background:#f59e0b"></div> $80M - $150M</div>
                        <div class="legend-item"><div class="legend-color" style="background:#ef4444"></div> Mas de $150M</div>
                    \`;
                    return div;
                };
                legend.addTo(map);
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
            <div id="chart-container">
                <canvas id="${chartId}"></canvas>
            </div>
            <script>
                const ctx = document.getElementById('${chartId}').getContext('2d');
                new Chart(ctx, ${configJson});
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
            <table class="data-table">
                <thead><tr>${headerHtml}</tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        `;
    },

    // Helper: Calculate center from locations
    calculateCenter(locations) {
        if (!locations || locations.length === 0) return [-38.9, -71.8]; // Default: Chile
        const lats = locations.map(l => l.lat);
        const lngs = locations.map(l => l.lng);
        return [
            (Math.min(...lats) + Math.max(...lats)) / 2,
            (Math.min(...lngs) + Math.max(...lngs)) / 2
        ];
    },

    // Helper: Get color based on price
    getPriceColor(price) {
        if (!price) return '#3b82f6';
        if (price < 30000000) return '#22c55e';  // Green - affordable
        if (price < 80000000) return '#3b82f6';  // Blue - mid range
        if (price < 150000000) return '#f59e0b'; // Orange - high
        return '#ef4444';                         // Red - premium
    },

    // Helper: Escape HTML
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    // Parse render commands from Claude's response
    parseRenderCommands(text) {
        const renders = [];
        
        // Pattern: :::render-TYPE{title="Title"}\n...JSON...\n:::
        const pattern = /:::render-(\w+)(?:\{([^}]*)\})?\n([\s\S]*?)\n:::/g;
        let match;
        
        while ((match = pattern.exec(text)) !== null) {
            try {
                const type = match[1];
                const attrsStr = match[2] || '';
                const content = match[3].trim();
                
                // Parse attributes
                const attrs = {};
                attrsStr.split(' ').forEach(attr => {
                    const [key, val] = attr.split('=');
                    if (key && val) {
                        attrs[key] = val.replace(/"/g, '');
                    }
                });
                
                // Parse JSON content
                const data = JSON.parse(content);
                
                renders.push({
                    type: type,
                    title: attrs.title || data.title || 'Visualizacion',
                    data: data,
                    raw: match[0]
                });
            } catch (e) {
                console.error('Error parsing render command:', e);
            }
        }
        
        return renders;
    },

    // Process message and create renders
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
            
            // Save render and get URL
            const result = await this.save(render.type, render.title, html);
            
            if (result && result.url) {
                // Replace render command with button
                const buttonId = 'render_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
                const buttonHtml = `<button class="render-btn" id="${buttonId}" data-url="${result.url}" data-title="${this.escapeHtml(render.title)}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        ${this.getIconForType(render.type)}
                    </svg>
                    <span>${this.escapeHtml(render.title)}</span>
                </button>`;
                
                processedText = processedText.replace(render.raw, buttonHtml);
            } else {
                // Remove render command if failed
                processedText = processedText.replace(render.raw, '');
            }
        }
        
        return processedText;
    },

    // Get icon SVG for render type
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

    // Initialize event listeners
    init() {
        // Delegate click events for render buttons
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
        
        // Close sidebar button
        const closeBtn = document.getElementById('closePreview');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeSidebar());
        }
        
        // Toggle sidebar button
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

// Initialize on load
document.addEventListener('DOMContentLoaded', () => Renders.init());

// Make globally available
window.Renders = Renders;
