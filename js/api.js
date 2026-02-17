/**
 * QueBot - API Module
 * Comunicación con el backend PHP para Claude API
 */

const API = {
    endpoint: 'api/chat.php',
    
    /**
     * Enviar mensaje a Claude y obtener respuesta con streaming
     */
    async sendMessage(messages, onChunk, onComplete, onError) {
        try {
            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    messages: messages.map(msg => ({
                        role: msg.role,
                        content: msg.content
                    }))
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Error en la solicitud');
            }

            // Check if streaming
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('text/event-stream')) {
                // Handle SSE streaming
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let fullContent = '';
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            if (data === '[DONE]') {
                                onComplete(fullContent);
                                return;
                            }
                            try {
                                const parsed = JSON.parse(data);
                                if (parsed.content) {
                                    fullContent += parsed.content;
                                    onChunk(parsed.content, fullContent);
                                }
                                if (parsed.error) {
                                    throw new Error(parsed.error);
                                }
                            } catch (e) {
                                // Ignore JSON parse errors for incomplete data
                                if (e.message !== 'Unexpected end of JSON input') {
                                    console.error('Parse error:', e);
                                }
                            }
                        }
                    }
                }
                onComplete(fullContent);
            } else {
                // Handle regular JSON response
                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }
                onChunk(data.content, data.content);
                onComplete(data.content);
            }
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
