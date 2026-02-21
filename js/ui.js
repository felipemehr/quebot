/**
 * QueBot - UI Module
 * Funciones de interfaz de usuario
 */

const UI = {
    elements: {},
    renderCounter: 0,
    pendingRenders: {},
    _thinkingInterval: null,
    _thinkingIndex: 0,
    _streamBuffer: '',
    _streamMessageAppended: false,
    _tokenRenderTimer: null,
    _tokenRenderPending: false,

    /**
     * Inicializar referencias a elementos del DOM
     */
    init() {
        this.elements = {
            sidebar: document.getElementById('sidebar'),
            sidebarOverlay: document.getElementById('sidebarOverlay'),
            sidebarToggle: document.getElementById('sidebarToggle'),
            mobileMenuBtn: document.getElementById('mobileMenuBtn'),
            newChatBtn: document.getElementById('newChatBtn'),
            chatHistory: document.getElementById('chatHistory'),
            todayChats: document.getElementById('todayChats'),
            olderChats: document.getElementById('olderChats'),
            themeToggle: document.getElementById('themeToggle'),
            chatTitle: document.getElementById('chatTitle'),
            previewToggle: document.getElementById('previewToggle'),
            previewPanel: document.getElementById('previewPanel'),
            closePreview: document.getElementById('closePreview'),
            previewTitle: document.getElementById('previewTitle'),
            previewContent: document.getElementById('previewContent'),
            messagesArea: document.getElementById('messagesArea'),
            welcomeScreen: document.getElementById('welcomeScreen'),
            messagesList: document.getElementById('messagesList'),
            messageInput: document.getElementById('messageInput'),
            sendBtn: document.getElementById('sendBtn'),
            attachBtn: document.getElementById('attachBtn'),
            fileInput: document.getElementById('fileInput'),
            attachedFiles: document.getElementById('attachedFiles'),
            loadingOverlay: document.getElementById('loadingOverlay'),
            toastContainer: document.getElementById('toastContainer')
        };

        // Configure marked for markdown
        if (typeof marked !== 'undefined') {
            marked.setOptions({
                highlight: function(code, lang) {
                    if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                        return hljs.highlight(code, { language: lang }).value;
                    }
                    return code;
                },
                breaks: true,
                gfm: true
            });
        }
    },

    /**
     * Aplicar tema
     */
    setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        Storage.setTheme(theme);
    },

    /**
     * Alternar tema
     */
    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.setTheme(newTheme);
    },

    /**
     * Abrir sidebar
     */
    openSidebar() {
        this.elements.sidebar.classList.add('open');
        this.elements.sidebar.classList.remove('collapsed');
        if (window.innerWidth <= 768 && this.elements.sidebarOverlay) {
            this.elements.sidebarOverlay.classList.add('visible');
        }
    },

    /**
     * Cerrar sidebar
     */
    closeSidebar() {
        this.elements.sidebar.classList.remove('open');
        this.elements.sidebar.classList.add('collapsed');
        if (this.elements.sidebarOverlay) {
            this.elements.sidebarOverlay.classList.remove('visible');
        }
    },

    /**
     * Alternar sidebar
     */
    toggleSidebar() {
        if (this.elements.sidebar.classList.contains('open')) {
            this.closeSidebar();
        } else {
            this.openSidebar();
        }
    },

    /**
     * Alternar panel de preview
     */
    togglePreview() {
        this.elements.previewPanel.classList.toggle('open');
        this.elements.previewToggle.classList.toggle('active');
    },

    /**
     * Cerrar panel de preview
     */
    closePreview() {
        this.elements.previewPanel.classList.remove('open');
        this.elements.previewToggle.classList.remove('active');
    },

    /**
     * Mostrar contenido en preview
     */
    showPreview(title, content, isHtml = false) {
        this.elements.previewTitle.textContent = title;
        if (isHtml) {
            this.elements.previewContent.innerHTML = content;
        } else {
            this.elements.previewContent.innerHTML = `<div class="preview-text">${this.renderMarkdown(content)}</div>`;
        }
        this.elements.previewPanel.classList.add('open');
        this.elements.previewToggle.classList.add('active');
    },

    /**
     * Mostrar render en preview panel
     */
    showRenderPreview(renderId) {
        const render = this.pendingRenders[renderId];
        if (!render) {
            console.error('Render not found:', renderId);
            return;
        }

        let html = '';
        
        if (render.type === 'map') {
            html = this.buildMapHtml(render.title, render.data);
        } else if (render.type === 'table') {
            html = this.buildTableHtml(render.title, render.data);
        } else if (render.type === 'chart') {
            html = this.buildChartHtml(render.title, render.data);
        }

        this.elements.previewTitle.textContent = render.title;
        this.elements.previewContent.innerHTML = `<iframe sandbox="allow-scripts" srcdoc="${this.escapeHtmlAttribute(html)}" style="width:100%;height:100%;border:none;"></iframe>`;
        this.elements.previewPanel.classList.add('open');
        this.elements.previewToggle.classList.add('active');
    },

    /**
     * Build map HTML
     */
    buildMapHtml(title, data) {
        const locations = data.locations || [];
        return `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${this.escapeHtml(title)}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"><\/script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; }
        #map { width: 100%; height: 100%; }
        .custom-popup { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .custom-popup h3 { margin: 0 0 8px; color: #1a5f2a; font-size: 14px; }
        .custom-popup p { margin: 4px 0; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div id="map"></div>
    <script>
        const locations = ${JSON.stringify(locations)};
        let centerLat = -38.84, centerLng = -71.68;
        if (locations.length > 0) {
            centerLat = locations.reduce((s, l) => s + l.lat, 0) / locations.length;
            centerLng = locations.reduce((s, l) => s + l.lng, 0) / locations.length;
        }
        const map = L.map('map').setView([centerLat, centerLng], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '\u00a9 OpenStreetMap'
        }).addTo(map);
        locations.forEach(loc => {
            const marker = L.marker([loc.lat, loc.lng]).addTo(map);
            marker.bindPopup('<div class="custom-popup"><h3>' + (loc.title || 'Ubicaci\u00f3n') + '</h3><p>' + (loc.description || '') + '</p></div>');
        });
        if (locations.length > 1) {
            const bounds = L.latLngBounds(locations.map(l => [l.lat, l.lng]));
            map.fitBounds(bounds, { padding: [20, 20] });
        }
        setTimeout(() => map.invalidateSize(), 100);
        setTimeout(() => map.invalidateSize(), 500);
    <\/script>
</body>
</html>`;
    },

    /**
     * Build table HTML
     */
    buildTableHtml(title, data) {
        const headers = data.headers || [];
        const rows = data.rows || [];
        
        let tableRows = rows.map(row => {
            const cells = row.map(cell => {
                if (typeof cell === 'object' && cell.url) {
                    return `<td><a href="${this.escapeHtml(cell.url)}" target="_blank" rel="noopener">${this.escapeHtml(cell.text || 'Ver')}</a></td>`;
                }
                return `<td>${this.escapeHtml(String(cell))}</td>`;
            }).join('');
            return `<tr>${cells}</tr>`;
        }).join('');

        return `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${this.escapeHtml(title)}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; background: #f9fafb; }
        h1 { font-size: 18px; color: #1a5f2a; margin-bottom: 16px; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th { background: #1a5f2a; color: white; padding: 12px; text-align: left; font-weight: 600; font-size: 13px; white-space: nowrap; }
        td { padding: 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        tr:hover { background: #f0fdf4; }
        a { color: #1a5f2a; text-decoration: none; font-weight: 500; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>${this.escapeHtml(title)}</h1>
    <div class="table-scroll">
    <table>
        <thead><tr>${headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('')}</tr></thead>
        <tbody>${tableRows}</tbody>
    </table>
    </div>
</body>
</html>`;
    },

    /**
     * Build chart HTML
     */
    buildChartHtml(title, data) {
        return `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${this.escapeHtml(title)}</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; background: #f9fafb; }
        h1 { font-size: 18px; color: #1a5f2a; margin-bottom: 16px; }
        .chart-container { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <h1>${this.escapeHtml(title)}</h1>
    <div class="chart-container"><canvas id="chart"></canvas></div>
    <script>
        const ctx = document.getElementById('chart').getContext('2d');
        new Chart(ctx, ${JSON.stringify(data)});
    <\/script>
</body>
</html>`;
    },

    /**
     * Escape HTML for attribute
     */
    escapeHtmlAttribute(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },

    /**
     * Renderizar historial de chats en sidebar
     */
    renderChatHistory(chats, currentChatId) {
        const grouped = Storage.getChatsGrouped();
        
        this.elements.todayChats.innerHTML = '';
        this.elements.olderChats.innerHTML = '';

        const todayChats = [...grouped.today, ...grouped.yesterday];
        todayChats.forEach(chat => {
            this.elements.todayChats.appendChild(
                this.createChatHistoryItem(chat, chat.id === currentChatId)
            );
        });

        const olderChats = [...grouped.week, ...grouped.older];
        olderChats.forEach(chat => {
            this.elements.olderChats.appendChild(
                this.createChatHistoryItem(chat, chat.id === currentChatId)
            );
        });

        this.elements.todayChats.parentElement.style.display = todayChats.length ? 'block' : 'none';
        this.elements.olderChats.parentElement.style.display = olderChats.length ? 'block' : 'none';
    },

    /**
     * Crear elemento de historial de chat
     */
    createChatHistoryItem(chat, isActive) {
        const div = document.createElement('div');
        div.className = `history-item${isActive ? ' active' : ''}`;
        div.dataset.chatId = chat.id;
        div.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <span class="history-item-text">${this.escapeHtml(chat.title)}</span>
            <button class="history-item-delete" title="Eliminar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
        `;
        return div;
    },

    /**
     * Renderizar mensajes
     */
    renderMessages(messages) {
        if (messages.length === 0) {
            this.elements.welcomeScreen.classList.remove('hidden');
            this.elements.messagesList.innerHTML = '';
            return;
        }

        this.elements.welcomeScreen.classList.add('hidden');
        this.elements.messagesList.innerHTML = '';

        messages.forEach(message => {
            this.appendMessage(message);
        });

        this.scrollToBottom();
    },

    /**
     * Agregar mensaje al DOM
     */
    appendMessage(message, animate = false) {
        this.elements.welcomeScreen.classList.add('hidden');

        const div = document.createElement('div');
        div.className = `message ${message.role}`;
        div.dataset.messageId = message.id;

        const time = message.timestamp ? this.formatTime(message.timestamp) : '';
        const authorName = message.role === 'user' ? 'Tú' : 'QueBot';
        const avatarContent = message.role === 'user' 
            ? 'F' 
            : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                <path d="M2 17l10 5 10-5"/>
                <path d="M2 12l10 5 10-5"/>
               </svg>`;

        // Process content with render commands
        let bodyHtml = this.processRenderCommands(message.content);
        
        div.innerHTML = `
            <div class="message-avatar">${avatarContent}</div>
            <div class="message-content">
                <div class="message-header">
                    <span class="message-author">${authorName}</span>
                    <span class="message-time">${time}</span>
                </div>
                <div class="message-body">${bodyHtml}</div>
            </div>
        `;

        // Post-process: wrap tables for horizontal scroll
        this.wrapTablesForScroll(div);

        // Make all links open in new tab
        div.querySelectorAll('a[href^="http"]').forEach(link => {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        });

        // Attach render button handlers
        div.querySelectorAll('.render-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const renderId = btn.dataset.renderId;
                this.showRenderPreview(renderId);
            });
        });

        this.elements.messagesList.appendChild(div);
        
        if (animate) {
            this.scrollToBottom();
        }
    },

    /**
     * Wrap tables in scrollable container
     */
    wrapTablesForScroll(container) {
        container.querySelectorAll('.message-body table').forEach(table => {
            if (table.parentElement.classList.contains('table-wrapper')) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'table-wrapper';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    },

    /**
     * Process render commands in content
     */
    processRenderCommands(content) {
        const renderPattern = /:::render-(map|table|chart)\{title="([^"]+)"\}\s*\n([\s\S]*?)\n:::/g;
        
        let processedContent = content.replace(renderPattern, (match, type, title, jsonStr) => {
            try {
                const data = JSON.parse(jsonStr.trim());
                const renderId = 'render_' + (++this.renderCounter);
                
                this.pendingRenders[renderId] = { type, title, data };
                
                const icon = type === 'map' ? '🗺️' : type === 'table' ? '📊' : '📈';
                const label = type === 'map' ? 'Ver Mapa' : type === 'table' ? 'Ver Tabla' : 'Ver Gráfico';
                
                return `<button class="render-btn render-btn-${type}" data-render-id="${renderId}">${icon} ${label}: ${this.escapeHtml(title)}</button>`;
            } catch (e) {
                console.error('Error parsing render command:', e);
                return ''; // Hide broken render commands
            }
        });
        
        return this.renderMarkdown(processedContent);
    },

    /**
     * Actualizar contenido del último mensaje del asistente
     */
    updateLastAssistantMessage(content) {
        const messages = this.elements.messagesList.querySelectorAll('.message.assistant');
        if (messages.length > 0) {
            const lastMessage = messages[messages.length - 1];
            const bodyEl = lastMessage.querySelector('.message-body');
            bodyEl.innerHTML = this.processRenderCommands(content);
            
            // Wrap tables
            this.wrapTablesForScroll(lastMessage);
            
            // Make all links open in new tab
            bodyEl.querySelectorAll('a[href^="http"]').forEach(link => {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            });

            // Attach render button handlers
            bodyEl.querySelectorAll('.render-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const renderId = btn.dataset.renderId;
                    this.showRenderPreview(renderId);
                });
            });
            
            this.scrollToBottom();
        }
    },

    /**
     * Detectar tipo de consulta y generar pasos contextuales
     */
    _buildThinkingSteps(query) {
        if (!query) return this._defaultThinkingSteps();
        
        const q = query.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        
        // Extract key terms for personalization
        const location = this._extractLocation(q);
        const topic = this._extractTopic(q);
        
        // Detect vertical
        if (this._isRealEstateQuery(q)) return this._realEstateSteps(q, location);
        if (this._isLegalQuery(q)) return this._legalSteps(q, topic);
        if (this._isNewsQuery(q)) return this._newsSteps(q, topic);
        if (this._isRetailQuery(q)) return this._retailSteps(q, topic);
        if (this._isMapQuery(q)) return this._mapSteps(q, location);
        
        // General with search detection
        if (q.includes('busca') || q.includes('encuentra') || q.includes('donde') || q.includes('como') || q.includes('quien')) {
            return this._searchSteps(q, topic);
        }
        
        return this._conversationalSteps(q);
    },
    
    _isRealEstateQuery(q) {
        const t = ['parcela', 'terreno', 'casa', 'depto', 'departamento', 'propiedad', 'arriendo',
                    'arrienda', 'venta', 'inmobili', 'hectarea', 'sitio', 'lote', 'condominio',
                    'dormitorio', '3d', '2d', '4d', 'uf ', 'cabana', 'fundo', 'agricola', 'chacra'];
        return t.some(x => q.includes(x));
    },
    
    _isLegalQuery(q) {
        const t = ['ley ', 'codigo', 'articulo', 'norma', 'decreto', 'legal', 'dfl ', 'reglamento',
                    'constitucion', 'tribunal', 'copropiedad', 'urbanismo', 'procedimiento', 'derecho',
                    'contrato', 'demanda', 'recurso'];
        return t.some(x => q.includes(x));
    },
    
    _isNewsQuery(q) {
        const t = ['noticia', 'hoy', 'ayer', 'actualidad', 'ultima hora', 'reciente',
                    'paso con', 'paso en', 'crisis', 'elecciones', 'gobierno', 'economia'];
        return t.some(x => q.includes(x));
    },
    
    _isRetailQuery(q) {
        const t = ['precio', 'comprar', 'tienda', 'oferta', 'descuento', 'notebook',
                    'celular', 'telefono', 'electrodomestico', 'barato', 'mejor precio'];
        return t.some(x => q.includes(x));
    },
    
    _isMapQuery(q) {
        const t = ['mapa', 'ubicacion', 'donde queda', 'coordenada', 'muestrame el mapa'];
        return t.some(x => q.includes(x));
    },
    
    _extractLocation(q) {
        const cities = {
            'santiago': 'Santiago', 'valparaiso': 'Valparaíso', 'vina del mar': 'Viña del Mar',
            'concepcion': 'Concepción', 'la serena': 'La Serena', 'antofagasta': 'Antofagasta',
            'temuco': 'Temuco', 'rancagua': 'Rancagua', 'talca': 'Talca', 'arica': 'Arica',
            'iquique': 'Iquique', 'puerto montt': 'Puerto Montt', 'osorno': 'Osorno',
            'valdivia': 'Valdivia', 'chillan': 'Chillán', 'copiapo': 'Copiapó',
            'punta arenas': 'Punta Arenas', 'melipeuco': 'Melipeuco', 'pucon': 'Pucón',
            'villarrica': 'Villarrica', 'olmue': 'Olmué', 'limache': 'Limache',
            'curico': 'Curicó', 'linares': 'Linares', 'los angeles': 'Los Ángeles',
            'coyhaique': 'Coyhaique', 'calama': 'Calama', 'ovalle': 'Ovalle'
        };
        for (const [key, name] of Object.entries(cities)) {
            if (q.includes(key)) return name;
        }
        const m = q.match(/\ben\s+([a-z\s]{3,20}?)(?:\s+(?:de|con|por|que|a |,|$))/);
        if (m) return m[1].trim().replace(/\b\w/g, l => l.toUpperCase());
        return null;
    },
    
    _extractTopic(q) {
        const stop = ['que', 'como', 'donde', 'busca', 'buscar', 'encuentra', 'quiero', 'necesito',
                       'sobre', 'del', 'los', 'las', 'una', 'por', 'para', 'con', 'mas', 'muy',
                       'hoy', 'ayer', 'mapa', 'dame', 'dime', 'cual', 'son', 'hay', 'tiene'];
        const words = q.split(/\s+/).filter(w => w.length > 2 && !stop.includes(w));
        return words.length > 0 ? words.slice(0, 3).join(' ') : null;
    },
    
    _realEstateSteps(q, location) {
        const loc = location ? ` en ${location}` : '';
        const locShort = location || 'la zona';
        return [
            { icon: '🏠', text: `Detectando búsqueda inmobiliaria${loc}...` },
            { icon: '🔎', text: `Generando búsquedas optimizadas para ${locShort}...` },
            { icon: '🌐', text: 'Consultando portalinmobiliario.com, toctoc.com, yapo.cl...' },
            { icon: '📄', text: 'Extrayendo datos de publicaciones encontradas...' },
            { icon: '💰', text: 'Validando precios, superficies y UF...' },
            { icon: '📊', text: 'Ranking resultados por relevancia y confiabilidad...' },
            { icon: '✅', text: 'Verificando links y datos reales...' },
            { icon: '✍️', text: 'Armando tabla comparativa...' },
            { icon: '🔍', text: 'Control de calidad: verificando precisión...' }
        ];
    },
    
    _legalSteps(q, topic) {
        const lawMatch = q.match(/ley\s*(\d[\d.]*)/);
        const codeMatch = q.match(/codigo\s+(civil|penal|procedimiento|trabajo|comercio|aguas)/);
        const artMatch = q.match(/articulo\s*(\d+)/);
        let lawName = topic || 'normativa';
        if (lawMatch) lawName = `Ley ${lawMatch[1]}`;
        if (codeMatch) lawName = `Código ${codeMatch[1].charAt(0).toUpperCase() + codeMatch[1].slice(1)}`;
        return [
            { icon: '⚖️', text: `Detectando consulta legal: ${lawName}...` },
            { icon: '📚', text: 'Buscando en base de datos legal (5.344 artículos)...' },
            { icon: '🔎', text: artMatch ? `Localizando artículo ${artMatch[1]}...` : `Buscando artículos de ${lawName}...` },
            { icon: '📖', text: 'Consultando LeyChile y BCN...' },
            { icon: '🧠', text: 'Analizando texto legal aplicable...' },
            { icon: '✍️', text: 'Preparando respuesta con referencias...' }
        ];
    },
    
    _newsSteps(q, topic) {
        const t = topic || 'actualidad';
        return [
            { icon: '📰', text: `Buscando noticias: ${t}...` },
            { icon: '🌐', text: 'Consultando La Tercera, Emol, BioBio Chile...' },
            { icon: '📄', text: 'Extrayendo artículos recientes...' },
            { icon: '✅', text: 'Verificando fuentes y fechas...' },
            { icon: '✍️', text: 'Preparando resumen noticioso...' }
        ];
    },
    
    _retailSteps(q, topic) {
        const t = topic || 'productos';
        return [
            { icon: '🛒', text: `Buscando ${t}...` },
            { icon: '🌐', text: 'Consultando Solotodo, Falabella, Ripley, PCFactory...' },
            { icon: '💰', text: 'Extrayendo precios y ofertas...' },
            { icon: '📊', text: 'Comparando opciones...' },
            { icon: '✍️', text: 'Preparando comparativa...' }
        ];
    },
    
    _mapSteps(q, location) {
        const loc = location || 'la zona';
        return [
            { icon: '🗺️', text: `Preparando mapa de ${loc}...` },
            { icon: '📍', text: 'Obteniendo coordenadas verificadas...' },
            { icon: '🎨', text: 'Generando visualización interactiva...' },
            { icon: '✍️', text: 'Listo para mostrar...' }
        ];
    },
    
    _searchSteps(q, topic) {
        const t = topic || 'tu consulta';
        return [
            { icon: '🔍', text: `Analizando: ${t}...` },
            { icon: '🌐', text: 'Buscando en fuentes confiables...' },
            { icon: '📄', text: 'Revisando páginas encontradas...' },
            { icon: '📊', text: 'Procesando y verificando datos...' },
            { icon: '✍️', text: 'Preparando respuesta...' }
        ];
    },
    
    _conversationalSteps(q) {
        return [
            { icon: '🧠', text: 'Procesando tu mensaje...' },
            { icon: '💭', text: 'Pensando la mejor respuesta...' },
            { icon: '✍️', text: 'Escribiendo...' }
        ];
    },
    
    _defaultThinkingSteps() {
        return [
            { icon: '🔍', text: 'Analizando consulta...' },
            { icon: '🌐', text: 'Buscando información...' },
            { icon: '📊', text: 'Procesando resultados...' },
            { icon: '✍️', text: 'Preparando respuesta...' }
        ];
    },

    /**
     * Add thinking log (SSE mode) — real pipeline steps from backend
     */
    addThinkingLog() {
        this._streamBuffer = '';
        this._streamMessageAppended = false;

        const div = document.createElement('div');
        div.className = 'message assistant thinking-log-container';
        div.innerHTML = `
            <div class="message-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
            </div>
            <div class="message-content">
                <div class="message-header">
                    <span class="message-author">QueBot</span>
                </div>
                <div class="message-body">
                    <div class="thinking-log">
                        <div class="thinking-log-steps"></div>
                    </div>
                </div>
            </div>
        `;
        this.elements.messagesList.appendChild(div);
        this.scrollToBottom();
    },

    /**
     * Add a real pipeline step to the thinking log
     */
    addThinkingStep(stage, detail) {
        const stepsContainer = document.querySelector('.thinking-log-steps');
        if (!stepsContainer) return;

        // Complete previous active step
        const prev = stepsContainer.querySelector('.thinking-step.active');
        if (prev) {
            prev.classList.remove('active');
            prev.classList.add('completed');
            const icon = prev.querySelector('.step-icon');
            if (icon) icon.innerHTML = '✓';
        }

        const step = document.createElement('div');
        step.className = 'thinking-step active';
        step.innerHTML = `
            <span class="step-icon"><span class="step-spinner"></span></span>
            <span class="step-text">${this.escapeHtml(detail)}</span>
        `;
        stepsContainer.appendChild(step);
        this.scrollToBottom();
    },

    /**
     * Transition from thinking log to streaming response
     */
    startStreaming() {
        // Mark last step as completed
        const stepsContainer = document.querySelector('.thinking-log-steps');
        if (stepsContainer) {
            const last = stepsContainer.querySelector('.thinking-step.active');
            if (last) {
                last.classList.remove('active');
                last.classList.add('completed');
                const icon = last.querySelector('.step-icon');
                if (icon) icon.innerHTML = '✓';
            }
        }

        // Add "writing" step
        this.addThinkingStep('writing', 'Escribiendo respuesta...');

        // Small delay then remove thinking log and start message
        setTimeout(() => {
            const thinkingContainer = document.querySelector('.thinking-log-container');
            if (thinkingContainer) thinkingContainer.remove();

            // Append empty assistant message for streaming
            this._streamMessageAppended = true;
            this.appendMessage({
                id: Date.now(),
                role: 'assistant',
                content: '',
                timestamp: new Date().toISOString()
            }, true);
        }, 400);
    },

    /**
     * Append a token to the streaming response (throttled rendering)
     */
    appendStreamToken(token) {
        this._streamBuffer += token;

        // Throttle re-renders to max ~8fps for performance
        if (!this._tokenRenderPending) {
            this._tokenRenderPending = true;
            if (this._tokenRenderTimer) cancelAnimationFrame(this._tokenRenderTimer);
            this._tokenRenderTimer = requestAnimationFrame(() => {
                this._tokenRenderPending = false;
                if (this._streamMessageAppended) {
                    this.updateLastAssistantMessage(this._streamBuffer);
                }
            });
        }
    },

    /**
     * Legacy: Add typing indicator (fallback for non-SSE mode)
     */
    addTypingIndicator(query) {
        // In SSE mode, addThinkingLog() is used instead.
        // Keep this for backward compatibility.
        this.addThinkingLog();
    },

    /**
     * Remove thinking indicator / thinking log
     */
    removeTypingIndicator() {
        if (this._thinkingInterval) {
            clearInterval(this._thinkingInterval);
            this._thinkingInterval = null;
        }
        if (this._tokenRenderTimer) {
            cancelAnimationFrame(this._tokenRenderTimer);
            this._tokenRenderTimer = null;
        }
        const typing = this.elements.messagesList.querySelector('.message.typing');
        if (typing) typing.remove();
        const thinkingLog = this.elements.messagesList.querySelector('.thinking-log-container');
        if (thinkingLog) thinkingLog.remove();
    },

    /**
     * Renderizar markdown
     */
    renderMarkdown(text) {
        if (typeof marked !== 'undefined') {
            return marked.parse(text);
        }
        return this.escapeHtml(text).replace(/\n/g, '<br>');
    },

    /**
     * Escapar HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Formatear hora
     */
    formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
    },

    /**
     * Scroll al final de los mensajes
     */
    scrollToBottom() {
        this.elements.messagesArea.scrollTop = this.elements.messagesArea.scrollHeight;
    },

    /**
     * Actualizar título del chat
     */
    setChatTitle(title) {
        this.elements.chatTitle.textContent = title;
    },

    /**
     * Habilitar/deshabilitar input
     */
    setInputEnabled(enabled) {
        this.elements.messageInput.disabled = !enabled;
        this.elements.sendBtn.disabled = !enabled || this.elements.messageInput.value.trim() === '';
    },

    /**
     * Limpiar input
     */
    clearInput() {
        this.elements.messageInput.value = '';
        this.elements.messageInput.style.height = 'auto';
        this.elements.sendBtn.disabled = true;
    },

    /**
     * Auto-resize del textarea
     */
    autoResizeInput() {
        const input = this.elements.messageInput;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 200) + 'px';
        this.elements.sendBtn.disabled = input.value.trim() === '';
    },

    /**
     * Mostrar toast de notificación
     */
    showToast(message, type = 'info', duration = 5000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const iconSvg = type === 'error' 
            ? '<path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M15 9l-6 6M9 9l6 6"/>'
            : '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>';

        toast.innerHTML = `
            <svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                ${iconSvg}
            </svg>
            <span class="toast-message">${this.escapeHtml(message)}</span>
            <button class="toast-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        `;

        this.elements.toastContainer.appendChild(toast);

        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => toast.remove());

        if (duration > 0) {
            setTimeout(() => toast.remove(), duration);
        }
    },

    /**
     * Mostrar/ocultar loading
     */
    setLoading(show) {
        this.elements.loadingOverlay.classList.toggle('visible', show);
    }
};
