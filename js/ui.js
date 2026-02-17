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
     * Cerrar sidebar (móvil)
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

        // Mostrar/ocultar secciones vacías
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

        // Process markdown and make links open in new tab
        let bodyHtml = this.renderMarkdown(message.content);
        
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

        // Make all links open in new tab
        div.querySelectorAll('a[href^="http"]').forEach(link => {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        });

        this.elements.messagesList.appendChild(div);
        
        if (animate) {
            this.scrollToBottom();
        }
    },

    /**
     * Actualizar contenido del último mensaje del asistente
     */
    updateLastAssistantMessage(content) {
        const messages = this.elements.messagesList.querySelectorAll('.message.assistant');
        if (messages.length > 0) {
            const lastMessage = messages[messages.length - 1];
            const bodyEl = lastMessage.querySelector('.message-body');
            bodyEl.innerHTML = this.renderMarkdown(content);
            
            // Make all links open in new tab
            bodyEl.querySelectorAll('a[href^="http"]').forEach(link => {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            });
            
            this.scrollToBottom();
        }
    },

    /**
     * Agregar indicador de typing
     */
    addTypingIndicator() {
        const div = document.createElement('div');
        div.className = 'message assistant typing';
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
                    <div class="typing-indicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        `;
        this.elements.messagesList.appendChild(div);
        this.scrollToBottom();
    },

    /**
     * Remover indicador de typing
     */
    removeTypingIndicator() {
        const typing = this.elements.messagesList.querySelector('.message.typing');
        if (typing) {
            typing.remove();
        }
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
