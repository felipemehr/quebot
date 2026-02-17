/**
 * QueBot - API Module
 * Comunicación con el backend PHP para Claude API
 */

const API = {
    endpoint: 'api/chat.php',
    
    /**
     * Enviar mensaje a Claude y obtener respuesta
     */
    async sendMessage(messages, onChunk, onComplete, onError, userContext = '') {
        try {
            // Get the last message content for the API
            const lastMessage = messages[messages.length - 1];
            const history = messages.slice(0, -1);

            // Get user context from Firebase auth if available
            let context = userContext;
            if (typeof queBotAuth !== 'undefined' && queBotAuth.initialized) {
                context = queBotAuth.getUserContext();
            }

            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    message: lastMessage.content,
                    history: history.map(msg => ({
                        role: msg.role,
                        content: msg.content
                    })),
                    userContext: context
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Error en la solicitud');
            }

            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }

            // Handle response with optional visualization
            const textContent = data.response || data.content || '';
            const vizData = data.visualization || null;

            // Call chunk callback with full content
            onChunk(textContent, textContent, vizData);
            
            // Call complete callback with content and viz data
            onComplete(textContent, vizData);

        } catch (error) {
            console.error('API Error:', error);
            onError(error.message || 'Error de conexión');
        }
    },

    /**
     * Verificar si la API está configurada
     */
    async checkStatus() {
        try {
            const response = await fetch(this.endpoint + '?status=1');
            const data = await response.json();
            return data;
        } catch (error) {
            return { configured: false, error: error.message };
        }
    }
};
