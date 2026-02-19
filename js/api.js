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
            const lastMessage = messages[messages.length - 1];
            const history = messages.slice(0, -1);

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
                    userContext: context,
                    user_profile: (typeof queBotAuth !== 'undefined') ? queBotAuth.getSearchProfile() : null
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

            const textContent = data.response || data.content || '';
            const vizData = data.visualization || null;

            // Extract metadata for run tracking
            const metadata = data.metadata || {};
            metadata.searched = data.searched || false;
            metadata.legal_used = data.legalResults || false;
            metadata.profile_update = data.profile_update || null;

            // Call chunk callback with full content
            onChunk(textContent, textContent, vizData);
            
            // Call complete callback with content, viz data, and metadata
            onComplete(textContent, vizData, metadata);

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
