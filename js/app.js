/**
 * QueBot - Main Application
 * Lógica principal de la aplicación
 */

const App = {
    currentChatId: null,
    isProcessing: false,

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
            UI.setChatTitle(chat.title);
            UI.renderMessages(chat.messages);
        } else {
            UI.setChatTitle('Nueva conversación');
            UI.renderMessages([]);
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
     * Enviar mensaje
     */
    async sendMessage() {
        const content = UI.elements.messageInput.value.trim();
        if (!content || this.isProcessing) return;

        this.isProcessing = true;
        UI.setInputEnabled(false);

        // Agregar mensaje del usuario
        const userMessage = {
            role: 'user',
            content: content
        };
        Storage.addMessage(this.currentChatId, userMessage);
        UI.appendMessage({ ...userMessage, id: Date.now(), timestamp: new Date().toISOString() }, true);
        UI.clearInput();

        // Actualizar título si es el primer mensaje
        const chat = Storage.getChat(this.currentChatId);
        if (chat.messages.length === 1) {
            UI.setChatTitle(chat.title);
            this.renderChatHistory();
        }

        // Mostrar indicador de typing
        UI.addTypingIndicator();

        // Preparar mensajes para la API (solo los últimos 20 para contexto)
        const messagesForApi = chat.messages.slice(-20);

        // Crear mensaje placeholder para el asistente
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
            (chunk, fullContent) => {
                if (!messageAppended) {
                    UI.removeTypingIndicator();
                    UI.appendMessage({ ...assistantMessage, content: fullContent }, true);
                    messageAppended = true;
                } else {
                    UI.updateLastAssistantMessage(fullContent);
                }
                assistantContent = fullContent;
            },
            // onComplete
            (finalContent) => {
                UI.removeTypingIndicator();
                if (!messageAppended) {
                    UI.appendMessage({ ...assistantMessage, content: finalContent }, true);
                }
                // Guardar mensaje del asistente
                Storage.addMessage(this.currentChatId, {
                    role: 'assistant',
                    content: finalContent
                });
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
            }
        );
    },

    /**
     * Manejar selección de archivos
     */
    handleFileSelect(event) {
        const files = event.target.files;
        if (files.length === 0) return;

        // Por ahora solo mostrar los nombres
        // La funcionalidad completa de archivos requiere más lógica
        Array.from(files).forEach(file => {
            UI.showToast(`Archivo adjuntado: ${file.name}`, 'success', 3000);
        });

        // Limpiar input
        event.target.value = '';
    }
};

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => App.init());
