/**
 * QueBot - API Module
 * Funciones para comunicación con el backend
 */

const API = {
    baseUrl: 'api/',

    /**
     * Enviar mensaje al chat
     */
    async sendMessage(message, history = []) {
        // Get user context if available
        const userContext = typeof queBotAuth !== 'undefined' ? queBotAuth.getUserContext() : '';
        
        const response = await fetch(this.baseUrl + 'chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                message: message,
                history: history,
                userContext: userContext
            })
        });

        // Check content type before parsing
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('API returned non-JSON response:', text.substring(0, 200));
            throw new Error('Error del servidor: respuesta inválida');
        }

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Error al enviar mensaje');
        }

        // Track message count for registration prompts
        if (typeof queBotAuth !== 'undefined') {
            queBotAuth.incrementMessageCount();
            // Try to extract user info from messages
            queBotAuth.processRegistrationFromChat(message);
        }

        return data;
    },

    /**
     * Verificar estado del API
     */
    async checkStatus() {
        try {
            const response = await fetch(this.baseUrl + 'chat.php?status=1');
            
            // Check content type before parsing
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.error('Status check returned non-JSON');
                return { configured: false };
            }
            
            return await response.json();
        } catch (error) {
            console.error('Status check error:', error);
            return { configured: false };
        }
    }
};
