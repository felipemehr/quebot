/**
 * QueBot - Main Application
 * L\u00f3gica principal de la aplicaci\u00f3n
 */

const App = {
    currentChatId: null,
    isProcessing: false,
    lastVizData: null,
    messageCount: 0,

    /**
     * Inicializar aplicaci\u00f3n
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

        // Nueva conversaci\u00f3n
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
        document.querySelectorAll('.action-card').forEach(card => {
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
     * Crear nueva conversaci\u00f3n
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
     * Cargar conversaci\u00f3n
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
     * Eliminar conversaci\u00f3n
     */
    deleteChat(chatId) {
        if (confirm('\u00bfEliminar este caso?')) {
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
            UI.setChatTitle('Nueva Misi\u00f3n');
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
            grid.innerHTML = '<p class="no-cases-msg">A\u00fan no tienes casos activos.</p>';
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

        // Actualizar t\u00edtulo si es el primer mensaje
        const chat = Storage.getChat(this.currentChatId);
        if (chat.messages.length === 1) {
            UI.setChatTitle(chat.title);
            this.renderChatHistory();
            // Update case title in Firestore
            if (typeof queBotAuth !== 'undefined' && queBotAuth.caseMap[this.currentChatId]) {
                queBotDB.updateCase(queBotAuth.caseMap[this.currentChatId], { title: chat.title });
            }
        }

        // Mostrar indicador de typing contextual
        UI.addTypingIndicator(content);

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
            // onChunk
            (chunk, fullContent, vizData) => {
                if (!messageAppended) {
                    UI.removeTypingIndicator();
                    UI.appendMessage({ ...assistantMessage, content: fullContent }, true);
                    messageAppended = true;
                } else {
                    UI.updateLastAssistantMessage(fullContent);
                }
                assistantContent = fullContent;
                if (vizData) this.lastVizData = vizData;
            },
            // onComplete
            (finalContent, vizData, metadata) => {
                UI.removeTypingIndicator();
                if (!messageAppended) {
                    UI.appendMessage({ ...assistantMessage, content: finalContent }, true);
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
     * Manejar selecci\u00f3n de archivos
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

// Inicializar cuando el DOM est\u00e9 listo
document.addEventListener('DOMContentLoaded', () => App.init());
