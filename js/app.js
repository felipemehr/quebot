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

        // Try to load conversations from Firestore
        const cloudConversations = await queBotAuth.loadConversations();
        if (cloudConversations) {
            console.log('Loaded conversations from Firebase');
        }
    },

    /**
     * Configurar event listeners
     */
    setupEventListeners() {
        // Sidebar toggle
        UI.elements.sidebarToggle.addEventListener('click', () => UI.toggleSidebar());
        UI.elements.mobileMenuBtn.addEventListener('click', () => UI.toggleSidebar());

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

        // Suggestion cards
        document.querySelectorAll('.suggestion-card').forEach(card => {
            card.addEventListener('click', () => {
                const prompt = card.dataset.prompt;
                UI.elements.messageInput.value = prompt;
                UI.autoResizeInput();
                this.sendMessage();
            });
        });

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

        // Click outside sidebar to close (mobile)
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!UI.elements.sidebar.contains(e.target) && 
                    !UI.elements.mobileMenuBtn.contains(e.target) &&
                    UI.elements.sidebar.classList.contains('open')) {
                    UI.closeSidebar();
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K - Nueva conversación
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.newChat();
            }
            // Escape - Cerrar preview
            if (e.key === 'Escape') {
                UI.closePreview();
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
        
        // Cerrar sidebar en móvil
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
        
        // Update message count for this chat
        const chat = Storage.getChat(chatId);
        this.messageCount = chat ? Math.floor(chat.messages.length / 2) : 0;
        
        // Cerrar sidebar en móvil
        if (window.innerWidth <= 768) {
            UI.closeSidebar();
        }
    },

    /**
     * Eliminar conversación
     */
    deleteChat(chatId) {
        if (confirm('¿Eliminar esta conversación?')) {
            Storage.deleteChat(chatId);
            
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
            UI.renderChat(chat);
        } else {
            // Create empty chat object for rendering
            UI.renderChat({ title: 'Nueva conversación', messages: [] });
        }
    },

    /**
     * Renderizar historial de chats
     */
    renderChatHistory() {
        const chats = Storage.getChats();
        UI.renderChatHistory(chats, this.currentChatId);
    },

    /**
     * Renderizar visualización (mapa, gráfico, etc)
     */
    renderVisualization(vizData, messageElement) {
        if (!vizData || !messageElement) return;

        const vizContainer = document.createElement('div');
        vizContainer.className = 'viz-container';

        if (vizData.type === 'map' && vizData.locations && vizData.locations.length > 0) {
            // Create map container
            const mapId = 'map-' + Date.now();
            vizContainer.innerHTML = `
                <div style="margin-top: 15px; background: var(--bg-secondary); border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                    <div style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-weight: 600;">
                        🗺️ ${vizData.title || 'Mapa de Propiedades'}
                    </div>
                    <div id="${mapId}" class="viz-map"></div>
                </div>
            `;
            messageElement.appendChild(vizContainer);

            // Initialize map after DOM update
            setTimeout(() => {
                if (typeof Viz !== 'undefined' && typeof L !== 'undefined') {
                    Viz.createMap(mapId, vizData.locations);
                }
            }, 100);
        }

        if (vizData.type === 'chart' && vizData.data) {
            const chartId = 'chart-' + Date.now();
            vizContainer.innerHTML = `
                <div style="margin-top: 15px; background: var(--bg-secondary); border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                    <div style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-weight: 600;">
                        📊 ${vizData.title || 'Gráfico'}
                    </div>
                    <div class="viz-chart"><canvas id="${chartId}"></canvas></div>
                </div>
            `;
            messageElement.appendChild(vizContainer);

            setTimeout(() => {
                if (typeof Viz !== 'undefined' && typeof Chart !== 'undefined') {
                    Viz.createChart(chartId, vizData.chartType || 'bar', vizData.data);
                }
            }, 100);
        }
    },

    /**
     * Enviar mensaje
     */
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
            // Try to extract user info from message
            queBotAuth.processRegistrationFromChat(content);
        }

        // Agregar mensaje del usuario
        const userMessage = {
            role: 'user',
            content: content
        };
        Storage.addMessage(this.currentChatId, userMessage);
        UI.addMessage(content, true);
        UI.clearInput();

        // Actualizar título si es el primer mensaje
        const chat = Storage.getChat(this.currentChatId);
        if (chat.messages.length === 1) {
            UI.elements.chatTitle.textContent = chat.title;
            this.renderChatHistory();
        }

        // Mostrar indicador de typing
        UI.showTypingIndicator();

        // Preparar mensajes para la API (solo los últimos 20 para contexto)
        const messagesForApi = chat.messages.slice(-20);

        // Crear mensaje placeholder para el asistente
        let assistantContent = '';
        let messageAppended = false;

        // Enviar a la API
        await API.sendMessage(
            messagesForApi,
            // onChunk
            (chunk, fullContent, vizData) => {
                if (!messageAppended) {
                    UI.hideTypingIndicator();
                    UI.addMessage(fullContent, false);
                    messageAppended = true;
                } else {
                    UI.updateLastAssistantMessage(fullContent);
                }
                assistantContent = fullContent;
                if (vizData) this.lastVizData = vizData;
            },
            // onComplete
            (finalContent, vizData) => {
                UI.hideTypingIndicator();
                if (!messageAppended) {
                    UI.addMessage(finalContent, false);
                } else {
                    UI.updateLastAssistantMessage(finalContent);
                }
                
                // Render visualization if available
                if (vizData || this.lastVizData) {
                    const messages = document.querySelectorAll('.message.assistant');
                    const lastMsg = messages[messages.length - 1];
                    if (lastMsg) {
                        const contentEl = lastMsg.querySelector('.message-content');
                        this.renderVisualization(vizData || this.lastVizData, contentEl);
                    }
                }

                // Guardar mensaje del asistente
                Storage.addMessage(this.currentChatId, {
                    role: 'assistant',
                    content: finalContent
                });

                // Save to Firebase if authenticated
                this.saveToFirebase();

                this.isProcessing = false;
                UI.setInputEnabled(true);
                UI.elements.messageInput.focus();
            },
            // onError
            (error) => {
                UI.hideTypingIndicator();
                UI.showToast(error, 'error');
                this.isProcessing = false;
                UI.setInputEnabled(true);
            }
        );
    },

    /**
     * Save current chat to Firebase
     */
    async saveToFirebase() {
        if (typeof queBotAuth === 'undefined' || !queBotAuth.initialized) return;
        
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

        // Por ahora solo mostrar los nombres
        Array.from(files).forEach(file => {
            UI.showToast(`Archivo adjuntado: ${file.name}`, 'success', 3000);
        });

        // Limpiar input
        event.target.value = '';
    }
};

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => App.init());
