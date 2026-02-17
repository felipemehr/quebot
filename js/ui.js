/**
 * QueBot - UI Module
 * Funciones de interfaz de usuario
 */

const UI = {
    elements: {},

    /**
     * Inicializar referencias a elementos del DOM
     */
    init() {
        this.elements = {
            sidebar: document.getElementById('sidebar'),
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
     * Alternar sidebar
     */
    toggleSidebar() {
        this.elements.sidebar.classList.toggle('collapsed');
        this.elements.sidebar.classList.toggle('open');
    },

    /**
     * Cerrar sidebar (movil)
     */
    closeSidebar() {
        this.elements.sidebar.classList.remove('open');
        this.elements.sidebar.classList.add('collapsed');
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
     * Renderizar historial de chats en sidebar
     */
    renderChatHistory(chats, currentChatId) {
        const grouped = Storage.getChatsGrouped();
        
        // Limpiar contenedores
        this.elements.todayChats.innerHTML = '';
        this.elements.olderChats.innerHTML = '';

        // Renderizar chats de hoy
        const todayChats = [...grouped.today, ...grouped.yesterday];
        todayChats.forEach(chat => {
            this.elements.todayChats.appendChild(
                this.createChatHistoryItem(chat, chat.id === currentChatId)
            );
        });

        // Renderizar chats anteriores
        const olderChats = [...grouped.week, ...grouped.older];
        olderChats.forEach(chat => {
            this.elements.olderChats.appendChild(
                this.createChatHistoryItem(chat, chat.id === currentChatId)
            );
        });
    },

    /**
     * Crear elemento de historial de chat
     */
    createChatHistoryItem(chat, isActive) {
        const div = document.createElement('div');
        div.className = `history-item ${isActive ? 'active' : ''}`;
        div.dataset.chatId = chat.id;
        
        const title = chat.title || 'Nueva conversación';
        div.innerHTML = `
            <svg class="history-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <span class="history-item-title">${this.escapeHtml(title)}</span>
            <button class="history-item-delete" title="Eliminar conversación">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
        `;
        
        return div;
    },

    /**
     * Renderizar mensaje individual
     */
    renderMessage(message, isUser = false) {
        const div = document.createElement('div');
        div.className = `message ${isUser ? 'user' : 'assistant'}`;
        
        // Process content for renders first
        let processedContent = message.content;
        let renderButtons = '';
        
        if (!isUser && typeof Renders !== 'undefined') {
            const result = Renders.processMessage(processedContent);
            processedContent = result.content;
            renderButtons = result.buttons;
        }
        
        const renderedContent = this.renderMarkdown(processedContent);
        
        div.innerHTML = `
            <div class="message-avatar">
                ${isUser ? 
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' :
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>'
                }
            </div>
            <div class="message-content">
                ${renderedContent}
                ${renderButtons}
            </div>
        `;

        // Make all external links open in new tab
        div.querySelectorAll('a[href^="http"]').forEach(link => {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        });

        return div;
    },

    /**
     * Añadir mensaje al chat
     */
    addMessage(content, isUser = false) {
        // Ocultar pantalla de bienvenida
        this.elements.welcomeScreen.style.display = 'none';
        this.elements.messagesList.style.display = 'flex';

        const message = { content, role: isUser ? 'user' : 'assistant' };
        const messageEl = this.renderMessage(message, isUser);
        this.elements.messagesList.appendChild(messageEl);
        this.scrollToBottom();
        
        return messageEl;
    },

    /**
     * Actualizar último mensaje del asistente
     */
    updateLastAssistantMessage(content) {
        const messages = this.elements.messagesList.querySelectorAll('.message.assistant');
        const lastMessage = messages[messages.length - 1];
        if (lastMessage) {
            const contentEl = lastMessage.querySelector('.message-content');
            
            // Process content for renders
            let processedContent = content;
            let renderButtons = '';
            
            if (typeof Renders !== 'undefined') {
                const result = Renders.processMessage(processedContent);
                processedContent = result.content;
                renderButtons = result.buttons;
            }
            
            contentEl.innerHTML = this.renderMarkdown(processedContent) + renderButtons;
            
            // Make all external links open in new tab
            contentEl.querySelectorAll('a[href^="http"]').forEach(link => {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            });
            
            this.scrollToBottom();
        }
    },

    /**
     * Mostrar indicador de carga
     */
    showTypingIndicator() {
        const div = document.createElement('div');
        div.className = 'message assistant typing-indicator-message';
        div.innerHTML = `
            <div class="message-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
            </div>
            <div class="message-content">
                <div class="typing-indicator">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;
        this.elements.messagesList.appendChild(div);
        this.scrollToBottom();
    },

    /**
     * Ocultar indicador de carga
     */
    hideTypingIndicator() {
        const indicator = this.elements.messagesList.querySelector('.typing-indicator-message');
        if (indicator) {
            indicator.remove();
        }
    },

    /**
     * Scroll al final del chat
     */
    scrollToBottom() {
        this.elements.messagesArea.scrollTop = this.elements.messagesArea.scrollHeight;
    },

    /**
     * Renderizar Markdown a HTML
     */
    renderMarkdown(text) {
        if (typeof marked !== 'undefined') {
            return marked.parse(text);
        }
        // Fallback básico
        return text
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
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
     * Mostrar toast notification
     */
    showToast(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                ${type === 'error' ? 
                    '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>' :
                    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'}
            </svg>
            <span class="toast-message">${this.escapeHtml(message)}</span>
        `;
        
        this.elements.toastContainer.appendChild(toast);
        
        // Forzar reflow para animación
        toast.offsetHeight;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    /**
     * Auto-resize del input
     */
    autoResizeInput() {
        const input = this.elements.messageInput;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 200) + 'px';
    },

    /**
     * Limpiar input
     */
    clearInput() {
        this.elements.messageInput.value = '';
        this.elements.messageInput.style.height = 'auto';
    },

    /**
     * Habilitar/deshabilitar input
     */
    setInputEnabled(enabled) {
        this.elements.messageInput.disabled = !enabled;
        this.elements.sendBtn.disabled = !enabled;
        this.elements.attachBtn.disabled = !enabled;
    },

    /**
     * Renderizar chat completo
     */
    renderChat(chat) {
        // Actualizar título
        this.elements.chatTitle.textContent = chat.title || 'Nueva conversación';
        
        // Limpiar mensajes
        this.elements.messagesList.innerHTML = '';
        
        if (chat.messages.length === 0) {
            // Mostrar pantalla de bienvenida
            this.elements.welcomeScreen.style.display = 'flex';
            this.elements.messagesList.style.display = 'none';
        } else {
            // Mostrar mensajes
            this.elements.welcomeScreen.style.display = 'none';
            this.elements.messagesList.style.display = 'flex';
            
            chat.messages.forEach(msg => {
                const messageEl = this.renderMessage(msg, msg.role === 'user');
                this.elements.messagesList.appendChild(messageEl);
            });
            
            this.scrollToBottom();
        }
    },

    /**
     * Show loading overlay
     */
    showLoading() {
        this.elements.loadingOverlay.classList.add('visible');
    },

    /**
     * Hide loading overlay
     */
    hideLoading() {
        this.elements.loadingOverlay.classList.remove('visible');
    }
};
