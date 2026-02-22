/**
 * QueBot - Main Application
 * Lógica principal de la aplicación
 */

const App = {
    currentChatId: null,
    isProcessing: false,
    lastVizData: null,
    messageCount: 0,

    /**
     * Inicializar aplicación
     */
    async init() {
        // Inicializar UI
        UI.init();

        // Set initial sidebar state based on screen width
        if (window.innerWidth <= 768) {
            UI.elements.sidebar.classList.remove('open');
        } else {
            UI.elements.sidebar.classList.remove('collapsed');
        }

        // Aplicar tema guardado
        const savedTheme = Storage.getTheme();
        UI.setTheme(savedTheme);

        // Cargar chat actual o crear uno nuevo
        this.currentChatId = Storage.getCurrentChatId();
        if (!this.currentChatId) {
            const chat = Storage.createChat();
            this.currentChatId = chat.id;
        }

        // Renderizar interfaz
        this.renderCurrentChat();
        this.renderChatHistory();

        // Configurar event listeners
        this.setupEventListeners();

        // Register SSE streaming callbacks
        API.onStep((stage, detail) => {
            UI.addThinkingStep(stage, detail);
        });
        API.onStreamStart(() => {
            UI.startStreaming();
        });

        // Verificar estado de la API
        this.checkApiStatus();

        // Wait for Firebase to initialize
        setTimeout(() => {
            this.syncWithFirebase();
        }, 2000);
    },

    /**
     * Sync with Firebase if available
     */
    async syncWithFirebase() {
        if (typeof queBotAuth === 'undefined' || !queBotAuth.initialized) {
            console.log('Firebase not initialized, using localStorage only');
            return;
        }

        const cloudConversations = await queBotAuth.loadConversations();
        if (cloudConversations) {
            console.log('Loaded', cloudConversations.length, 'cases from Firebase');
        }
    },

    /**
     * Configurar event listeners
     */
    setupEventListeners() {
        // Sidebar toggle (desktop)
        UI.elements.sidebarToggle.addEventListener('click', () => UI.toggleSidebar());
        
        // Mobile hamburger menu
        UI.elements.mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            UI.toggleSidebar();
        });

        // Sidebar overlay (mobile) - close sidebar when clicking overlay
        if (UI.elements.sidebarOverlay) {
            UI.elements.sidebarOverlay.addEventListener('click', () => UI.closeSidebar());
        }

        // Nueva conversación
        UI.elements.newChatBtn.addEventListener('click', () => this.newChat());

        // Theme toggle
        UI.elements.themeToggle.addEventListener('click', () => UI.toggleTheme());

        // Preview panel
        UI.elements.previewToggle.addEventListener('click', () => UI.togglePreview());
        UI.elements.closePreview.addEventListener('click', () => UI.closePreview());

        // Input
        UI.elements.messageInput.addEventListener('input', () => UI.autoResizeInput());
        UI.elements.messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        // Send button
        UI.elements.sendBtn.addEventListener('click', () => this.sendMessage());

        // File attachment
        UI.elements.attachBtn.addEventListener('click', () => UI.elements.fileInput.click());
        UI.elements.fileInput.addEventListener('change', (e) => this.handleFileSelect(e));

        // Interactive card flow — multi-step selection
        document.querySelectorAll('.action-card').forEach(card => {
            card.addEventListener('click', () => {
                const cardType = card.dataset.cardType;
                this.handleCardClick(cardType);
            });
        });

        // B3: Personalize action cards with user profile
        this.personalizeActionCards();

        // Chat history clicks
        UI.elements.chatHistory.addEventListener('click', (e) => {
            const historyItem = e.target.closest('.history-item');
            const deleteBtn = e.target.closest('.history-item-delete');

            if (deleteBtn && historyItem) {
                e.stopPropagation();
                this.deleteChat(historyItem.dataset.chatId);
            } else if (historyItem) {
                this.loadChat(historyItem.dataset.chatId);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.newChat();
            }
            if (e.key === 'Escape') {
                UI.closePreview();
                if (window.innerWidth <= 768) {
                    UI.closeSidebar();
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                if (UI.elements.sidebarOverlay) {
                    UI.elements.sidebarOverlay.classList.remove('visible');
                }
            }
        });

        // User info click (for auth modal)
        const userInfo = document.getElementById('userInfo');
        if (userInfo) {
            userInfo.addEventListener('click', () => {
                if (typeof queBotAuth !== 'undefined') {
                    queBotAuth.showAuthModal();
                }
            });
        }
    },

    /**
     * Verificar estado de la API
     */
    async checkApiStatus() {
        const status = await API.checkStatus();
        if (!status.configured) {
            UI.showToast(
                'API no configurada. Edita api/config.php con tu API key de Claude.',
                'error',
                0
            );
        }
    },

    /**
     * Crear nueva conversación
     */
    newChat() {
        const chat = Storage.createChat();
        this.currentChatId = chat.id;
        this.messageCount = 0;
        this.renderCurrentChat();
        this.renderChatHistory();
        UI.elements.messageInput.focus();
        
        if (window.innerWidth <= 768) {
            UI.closeSidebar();
        }
    },

    /**
     * Cargar conversación
     */
    loadChat(chatId) {
        this.currentChatId = chatId;
        Storage.setCurrentChat(chatId);
        this.renderCurrentChat();
        this.renderChatHistory();
        
        const chat = Storage.getChat(chatId);
        this.messageCount = chat ? Math.floor(chat.messages.length / 2) : 0;
        
        if (window.innerWidth <= 768) {
            UI.closeSidebar();
        }
    },

    /**
     * Eliminar conversación
     */
    deleteChat(chatId) {
        if (confirm('¿Eliminar este caso?')) {
            Storage.deleteChat(chatId);
            
            // Also delete from Firestore
            if (typeof queBotAuth !== 'undefined' && queBotAuth.initialized) {
                queBotAuth.deleteConversation(chatId);
            }
            
            if (chatId === this.currentChatId) {
                const chats = Storage.getChats();
                if (chats.length > 0) {
                    this.loadChat(chats[0].id);
                } else {
                    this.newChat();
                }
            } else {
                this.renderChatHistory();
            }
        }
    },

    /**
     * Renderizar chat actual
     */
    renderCurrentChat() {
        const chat = Storage.getChat(this.currentChatId);
        if (chat) {
            UI.setChatTitle(chat.title);
            UI.renderMessages(chat.messages);
        } else {
            UI.setChatTitle('Nueva Misión');
            UI.renderMessages([]);
        }
    },

    /**
     * Renderizar historial de chats
     */
    renderChatHistory() {
        const chats = Storage.getChats();
        UI.renderChatHistory(chats, this.currentChatId);
        this.updateRecentCases(chats);
    },

    /**
     * Actualizar casos recientes en dashboard
     */
    updateRecentCases(chats) {
        const grid = document.getElementById('recentCasesGrid');
        const section = document.getElementById('recentCasesSection');
        if (!grid || !section) return;

        // Filter chats that have messages
        const activeCases = (chats || Storage.getChats())
            .filter(c => c.messages && c.messages.length > 0)
            .slice(0, 3);

        if (activeCases.length === 0) {
            grid.innerHTML = '<p class="no-cases-msg">Aún no tienes casos activos.</p>';
            return;
        }

        grid.innerHTML = activeCases.map(chat => {
            const lastMsg = chat.messages[chat.messages.length - 1];
            const preview = lastMsg ? lastMsg.content.substring(0, 80) + (lastMsg.content.length > 80 ? '...' : '') : '';
            const date = chat.updatedAt ? new Date(chat.updatedAt).toLocaleDateString('es-CL', { day: 'numeric', month: 'short' }) : '';
            const status = chat.messages.length > 0 ? 'Activo' : 'Nuevo';
            
            return `
                <div class="case-card" data-chat-id="${chat.id}">
                    <div class="case-card-title">${this.escapeHtml(chat.title)}</div>
                    <div class="case-card-preview">${this.escapeHtml(preview)}</div>
                    <div class="case-card-meta">
                        <span>${date}</span>
                        <span class="case-card-status">${status}</span>
                    </div>
                </div>
            `;
        }).join('');

        // Click handlers
        grid.querySelectorAll('.case-card').forEach(card => {
            card.addEventListener('click', () => {
                this.loadChat(card.dataset.chatId);
            });
        });
    },

    /**
     * Escape HTML
     */
    escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },


    /**
     * Card configurations for the interactive flow
     */
    cardConfigs: {
        news: {
            intro: 'Resumen de noticias relevantes con fuentes verificadas. ¿Qué tema te interesa?',
            filters: ['Chile', 'Internacional', 'Economía', 'Política', 'Tecnología']
        },
        prices: {
            intro: 'Dólar, UF, euro y más. Datos en tiempo real para decisiones financieras.',
            filters: ['Dólar', 'UF', 'Euro', 'Bitcoin', 'Indicadores']
        },
        property: {
            intro: 'Trabajo con propiedades reales en Chile.\nPuedo ayudarte a comprar, vender o evaluar una inversión.',
            filters: ['Comprar', 'Vender', 'Arrendar', 'Invertir', 'Tasar'],
            subFilters: {
                'Comprar': {
                    options: ['Casa', 'Departamento', 'Parcela', 'Oficina', 'Comercial'],
                    followUp: '¿En qué comuna y rango de presupuesto estás mirando?'
                }
            }
        },
        analysis: {
            intro: 'Analizo datos, comparo opciones y construyo estrategias. ¿Qué necesitas evaluar?',
            filters: ['Comparar', 'Evaluar', 'Investigar', 'Resumir']
        },
        code: {
            intro: 'PHP, JavaScript, Python, SQL y más. ¿Qué necesitas resolver?',
            filters: ['Debug', 'Refactor', 'Nuevo código', 'Explicar', 'SQL']
        }
    },

    /**
     * Active card flow state
     */
    _activeCardType: null,
    _activeFilters: [],

    /**
     * Handle card click — interactive multi-step flow
     */
    handleCardClick(cardType) {
        const config = this.cardConfigs[cardType];
        if (!config) return;

        this._activeCardType = cardType;
        this._activeFilters = [];

        // Hide the welcome screen
        UI.elements.welcomeScreen.classList.add('hidden');

        // Create assistant message with intro text
        const introMessage = {
            id: Date.now(),
            role: 'assistant',
            content: config.intro,
            timestamp: new Date().toISOString()
        };
        UI.appendMessage(introMessage, true);

        // Add filter buttons below the last message
        this._renderFilterButtons(config.filters, cardType, false);
    },

    /**
     * Render filter buttons as a row in the chat
     */
    _renderFilterButtons(filters, cardType, isSubFilter) {
        const container = document.createElement('div');
        container.className = 'filter-buttons-row';
        container.dataset.cardType = cardType;
        if (isSubFilter) container.dataset.subFilter = 'true';

        filters.forEach(label => {
            const btn = document.createElement('button');
            btn.className = 'filter-btn';
            btn.textContent = label;
            btn.addEventListener('click', () => {
                this._handleFilterClick(btn, label, cardType, isSubFilter, container);
            });
            container.appendChild(btn);
        });

        // Append to the messages list (after the last message)
        UI.elements.messagesList.appendChild(container);
        UI.scrollToBottom();
    },

    /**
     * Handle filter button click
     */
    _handleFilterClick(btn, label, cardType, isSubFilter, container) {
        // Mark button as active, deactivate siblings
        container.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        this._activeFilters.push(label);

        const config = this.cardConfigs[cardType];

        // Check if there are sub-filters for this selection
        if (!isSubFilter && config.subFilters && config.subFilters[label]) {
            const sub = config.subFilters[label];

            // Remove any existing sub-filter rows
            UI.elements.messagesList.querySelectorAll('.filter-buttons-row[data-sub-filter="true"]').forEach(el => el.remove());

            // Show sub-filter row
            this._renderFilterButtons(sub.options, cardType, true);

            // If there's a followUp prompt, set it in the input after sub-selection
            if (sub.followUp) {
                this._pendingFollowUp = sub.followUp;
            }
        } else if (isSubFilter && this._pendingFollowUp) {
            // Sub-filter selected — show followUp as assistant message
            const followUpMsg = {
                id: Date.now(),
                role: 'assistant',
                content: this._pendingFollowUp,
                timestamp: new Date().toISOString()
            };
            UI.appendMessage(followUpMsg, true);
            this._pendingFollowUp = null;

            // Pre-fill input with context
            const filterContext = this._activeFilters.join(' → ');
            UI.elements.messageInput.value = '';
            UI.elements.messageInput.focus();
            UI.elements.messageInput.placeholder = filterContext + ' — escribe tu búsqueda...';
        } else {
            // Final filter selected (no sub-filters) — put in input
            const filterContext = this._activeFilters.join(' → ');
            UI.elements.messageInput.value = '';
            UI.elements.messageInput.focus();
            UI.elements.messageInput.placeholder = filterContext + ' — escribe tu consulta...';
        }
    },

    _pendingFollowUp: null,

    /**
     * Enviar mensaje
     */

    /**
     * B3: Personalize action cards based on user's search profile.
     * Called on init and when profile updates.
     */
    personalizeActionCards() {
        const profile = (typeof queBotAuth !== 'undefined') ? queBotAuth.getSearchProfile() : null;
        if (!profile) return;

        // --- Property card ---
        const propertyCard = document.querySelector('.action-card[data-card-type="property"]');
        if (propertyCard) {
            // v2 compat: extract names from weighted objects or flat strings
            const extractNames = (arr) => (arr || []).map(i => typeof i === 'string' ? i : (i.name || '')).filter(Boolean);
            const locations = extractNames(profile.locations);
            const types = extractNames(profile.property_types);
            const budget = profile.budget || {};
            
            if (locations.length > 0 || types.length > 0) {
                // Build personalized prompt
                const parts = [];
                if (types.length > 0) {
                    parts.push(types.slice(0, 2).join(' o '));
                } else {
                    parts.push('propiedades');
                }
                if (locations.length > 0) {
                    parts.push('en ' + locations.slice(0, 2).join(' y '));
                }
                
                let prompt = 'Busca ' + parts.join(' ');
                
                // Add budget hint if available
                if (budget.max) {
                    const unit = budget.unit || 'UF';
                    prompt += ' hasta ' + budget.max.toLocaleString('es-CL') + ' ' + unit;
                }

                // Update card
                propertyCard.dataset.prompt = prompt;
                const label = propertyCard.querySelector('.action-card-label');
                if (label) {
                    // Short label for the card
                    let shortLabel = '';
                    if (types.length > 0) {
                        const typeNames = {
                            'parcela': 'Parcelas',
                            'casa': 'Casas',
                            'departamento': 'Deptos',
                            'terreno': 'Terrenos',
                            'oficina': 'Oficinas'
                        };
                        shortLabel = types.map(t => typeNames[t] || t).slice(0, 2).join(' y ');
                    } else {
                        shortLabel = 'Propiedades';
                    }
                    if (locations.length > 0) {
                        shortLabel += ' en ' + locations[0];
                    }
                    label.textContent = shortLabel;
                }
            }
        }

        // --- Prices card: could personalize with UF preferences ---
        // (future: if profile shows interest in specific currencies)

        // --- News card: could personalize with UF preferences ---
        // (future: "Noticias inmobiliarias en Temuco")
    },

    async sendMessage() {
        const content = UI.elements.messageInput.value.trim();
        if (!content || this.isProcessing) return;

        this.isProcessing = true;
        this.lastVizData = null;
        this.messageCount++;
        UI.setInputEnabled(false);

        // Increment message count in Firebase auth
        if (typeof queBotAuth !== 'undefined') {
            queBotAuth.incrementMessageCount();
            queBotAuth.processRegistrationFromChat(content);
        }

        // Agregar mensaje del usuario
        const userMessage = {
            role: 'user',
            content: content
        };
        Storage.addMessage(this.currentChatId, userMessage);
        UI.appendMessage({ ...userMessage, id: Date.now(), timestamp: new Date().toISOString() }, true);
        UI.clearInput();

        // Ensure Firestore case exists and save user message
        if (typeof queBotAuth !== 'undefined' && queBotAuth.initialized) {
            const chat = Storage.getChat(this.currentChatId);
            await queBotAuth.ensureCase(this.currentChatId, chat ? chat.title : null);
            const msgId = await queBotAuth.saveMessageToCase(this.currentChatId, 'user', content);
            // Store message ID for run tracking
            this._lastUserMessageId = msgId;
        }

        // Actualizar título si es el primer mensaje
        const chat = Storage.getChat(this.currentChatId);
        if (chat.messages.length === 1) {
            UI.setChatTitle(chat.title);
            this.renderChatHistory();
            // Update case title in Firestore
            if (typeof queBotAuth !== 'undefined' && queBotAuth.caseMap[this.currentChatId]) {
                queBotDB.updateCase(queBotAuth.caseMap[this.currentChatId], { title: chat.title });
            }
        }

        // Show thinking log (SSE will populate with real steps)
        UI.addThinkingLog();

        // Preparar mensajes para la API
        const messagesForApi = chat.messages.slice(-20);

        let assistantContent = '';
        const assistantMessage = {
            id: Date.now() + 1,
            role: 'assistant',
            content: '',
            timestamp: new Date().toISOString()
        };

        let messageAppended = false;

        // Enviar a la API
        await API.sendMessage(
            messagesForApi,
            // onChunk (SSE: called per token)
            (chunk, fullContent, vizData) => {
                // Always buffer tokens — UI renders when message bubble is ready
                UI.appendStreamToken(chunk);
                assistantContent = fullContent;
                if (vizData) this.lastVizData = vizData;
            },
            // onComplete
            (finalContent, vizData, metadata) => {
                UI.removeTypingIndicator();
                if (!messageAppended && !UI._streamMessageAppended) {
                    UI.appendMessage({ ...assistantMessage, content: finalContent }, true);
                } else {
                    // Final render with complete content (ensures markdown is correct)
                    UI.updateLastAssistantMessage(finalContent);
                }

                // Guardar mensaje del asistente en localStorage
                Storage.addMessage(this.currentChatId, {
                    role: 'assistant',
                    content: finalContent
                });

                // Save to Firebase: message + run + events
                this.saveToFirebase(finalContent, metadata);

                this.isProcessing = false;
                UI.setInputEnabled(true);
                UI.elements.messageInput.focus();

                // Show quick-reply suggestions based on mode
                if (metadata && metadata.mode) {
                    UI.showQuickReplies(metadata.mode, metadata.search_intent);
                }
            },
            // onError
            (error) => {
                UI.removeTypingIndicator();
                UI.showToast(error, 'error');
                this.isProcessing = false;
                UI.setInputEnabled(true);

                // Log error event
                if (typeof queBotAuth !== 'undefined' && queBotAuth.initialized) {
                    const caseId = queBotAuth.caseMap[this.currentChatId];
                    if (caseId && typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
                        queBotDB.logEvent(caseId, null, 'ERROR', { message: error });
                    }
                }
            }
        );
    },

    /**
     * Save assistant response + run metadata to Firebase
     */
    async saveToFirebase(assistantContent, metadata) {
        if (typeof queBotAuth === 'undefined' || !queBotAuth.initialized) return;
        
        // Save assistant message
        await queBotAuth.saveMessageToCase(this.currentChatId, 'assistant', assistantContent);

                // Save search profile if backend extracted new preferences
                if (metadata && metadata.profile_update) {
                    queBotAuth.saveSearchProfile(metadata.profile_update);
                    this.personalizeActionCards();
                }

        
        // Log run with metadata (timing, tokens, cost)
        if (metadata && Object.keys(metadata).length > 0) {
            await queBotAuth.logRun(this.currentChatId, this._lastUserMessageId, metadata);
        }

        // Legacy save
        const chat = Storage.getChat(this.currentChatId);
        if (chat) {
            await queBotAuth.saveConversation(
                this.currentChatId,
                chat.messages,
                chat.title
            );
        }
    },

    /**
     * Manejar selección de archivos
     */
    handleFileSelect(event) {
        const files = event.target.files;
        if (files.length === 0) return;

        Array.from(files).forEach(file => {
            UI.showToast(`Archivo adjuntado: ${file.name}`, 'success', 3000);
        });

        event.target.value = '';
    }
};

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => App.init());
