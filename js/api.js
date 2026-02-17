/**
 * QueBot - API Module
 * Funciones para comunicación con el backend
 */

const API = {
    baseUrl: 'api/',

    /**
     * Enviar mensaje al chat
     * @param {Array} messages - Array de mensajes del historial
     * @param {Function} onChunk - Callback para chunks (no usado, pero mantenemos compatibilidad)
     * @param {Function} onComplete - Callback cuando termina
     * @param {Function} onError - Callback para errores
     */
    async sendMessage(messages, onChunk, onComplete, onError) {
        try {
            // Get user context if available
            const userContext = typeof queBotAuth !== 'undefined' ? queBotAuth.getUserContext() : '';
            
            // Get last user message
            const lastUserMessage = messages.filter(m => m.role === 'user').pop();
            if (!lastUserMessage) {
                if (onError) onError('No hay mensaje para enviar');
                return;
            }

            // Build history (all messages except the last user message)
            const history = messages.slice(0, -1).map(m => ({
                role: m.role,
                content: m.content
            }));

            const response = await fetch(this.baseUrl + 'chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: lastUserMessage.content,
                    history: history,
                    userContext: userContext
                })
            });

            // Check content type before parsing
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('API returned non-JSON response:', text.substring(0, 200));
                if (onError) onError('Error del servidor: respuesta inválida');
                return;
            }

            const data = await response.json();

            if (!response.ok) {
                if (onError) onError(data.error || 'Error al enviar mensaje');
                return;
            }

            // Track message count for registration prompts
            if (typeof queBotAuth !== 'undefined') {
                queBotAuth.incrementMessageCount();
                queBotAuth.processRegistrationFromChat(lastUserMessage.content);
            }

            // Call onComplete with the response
            if (onComplete) {
                onComplete(data.response, null);
            }

        } catch (error) {
            console.error('API Error:', error);
            if (onError) onError(error.message || 'Error de conexión');
        }
    },

    /**
     * Verificar estado del API
     */
    async checkStatus() {
        try {
            const response = await fetch(this.baseUrl + 'chat.php?status=1');
            
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
