/**
 * QueBot - Storage Module
 * Manejo de localStorage para persistencia de datos
 */

const Storage = {
    KEYS: {
        CHATS: 'quebot_chats',
        CURRENT_CHAT: 'quebot_current_chat',
        THEME: 'quebot_theme',
        USER: 'quebot_user'
    },

    /**
     * Obtener item del localStorage
     */
    get(key, defaultValue = null) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : defaultValue;
        } catch (e) {
            console.error('Storage get error:', e);
            return defaultValue;
        }
    },

    /**
     * Guardar item en localStorage
     */
    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Storage set error:', e);
            return false;
        }
    },

    /**
     * Eliminar item del localStorage
     */
    remove(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Storage remove error:', e);
            return false;
        }
    },

    /**
     * Obtener todos los chats
     */
    getChats() {
        return this.get(this.KEYS.CHATS, []);
    },

    /**
     * Guardar todos los chats
     */
    saveChats(chats) {
        return this.set(this.KEYS.CHATS, chats);
    },

    /**
     * Obtener un chat por ID
     */
    getChat(chatId) {
        const chats = this.getChats();
        return chats.find(chat => chat.id === chatId) || null;
    },

    /**
     * Crear nuevo chat
     */
    createChat(title = 'Nuevo caso') {
        const chats = this.getChats();
        const newChat = {
            id: this.generateId(),
            title: title,
            messages: [],
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString()
        };
        chats.unshift(newChat);
        this.saveChats(chats);
        this.setCurrentChat(newChat.id);
        return newChat;
    },

    /**
     * Actualizar chat
     */
    updateChat(chatId, updates) {
        const chats = this.getChats();
        const index = chats.findIndex(chat => chat.id === chatId);
        if (index !== -1) {
            chats[index] = {
                ...chats[index],
                ...updates,
                updatedAt: new Date().toISOString()
            };
            this.saveChats(chats);
            return chats[index];
        }
        return null;
    },

    /**
     * Agregar mensaje a un chat
     */
    addMessage(chatId, message) {
        const chats = this.getChats();
        const index = chats.findIndex(chat => chat.id === chatId);
        if (index !== -1) {
            const newMessage = {
                id: this.generateId(),
                ...message,
                timestamp: new Date().toISOString()
            };
            chats[index].messages.push(newMessage);
            chats[index].updatedAt = new Date().toISOString();
            
            // Auto-generate title from first user message
            if (chats[index].messages.length === 1 && message.role === 'user') {
                chats[index].title = message.content.substring(0, 50) + (message.content.length > 50 ? '...' : '');
            }
            
            // Move to top of list
            const chat = chats.splice(index, 1)[0];
            chats.unshift(chat);
            
            this.saveChats(chats);
            return newMessage;
        }
        return null;
    },

    /**
     * Eliminar chat
     */
    deleteChat(chatId) {
        let chats = this.getChats();
        chats = chats.filter(chat => chat.id !== chatId);
        this.saveChats(chats);
        
        // Si era el chat actual, limpiar
        if (this.getCurrentChatId() === chatId) {
            this.setCurrentChat(null);
        }
        return true;
    },

    /**
     * Obtener ID del chat actual
     */
    getCurrentChatId() {
        return this.get(this.KEYS.CURRENT_CHAT, null);
    },

    /**
     * Establecer chat actual
     */
    setCurrentChat(chatId) {
        return this.set(this.KEYS.CURRENT_CHAT, chatId);
    },

    /**
     * Obtener tema
     */
    getTheme() {
        return this.get(this.KEYS.THEME, 'light');
    },

    /**
     * Guardar tema
     */
    setTheme(theme) {
        return this.set(this.KEYS.THEME, theme);
    },

    /**
     * Generar ID único
     */
    generateId() {
        return 'id_' + Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
    },

    /**
     * Obtener chats agrupados por fecha
     */
    getChatsGrouped() {
        const chats = this.getChats();
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        const weekAgo = new Date(today);
        weekAgo.setDate(weekAgo.getDate() - 7);

        return {
            today: chats.filter(chat => new Date(chat.updatedAt) >= today),
            yesterday: chats.filter(chat => {
                const date = new Date(chat.updatedAt);
                return date >= yesterday && date < today;
            }),
            week: chats.filter(chat => {
                const date = new Date(chat.updatedAt);
                return date >= weekAgo && date < yesterday;
            }),
            older: chats.filter(chat => new Date(chat.updatedAt) < weekAgo)
        };
    }
};
