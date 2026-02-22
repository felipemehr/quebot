/**
 * QueBot - API Module (SSE Streaming)
 * Reads Server-Sent Events from backend for real-time pipeline steps and token streaming.
 */

const API = {
    endpoint: 'api/chat.php',

    /**
     * Send message and process SSE stream.
     *
     * Callbacks:
     *   onStep(stage, detail)          – pipeline step (real from backend)
     *   onToken(token, fullSoFar)      – incremental text token
     *   onComplete(fullText, metadata) – final: all metadata, profile, etc.
     *   onError(message)               – error
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
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: lastMessage.content,
                    history: history.map(m => ({ role: m.role, content: m.content })),
                    userContext: context,
                    userId: (typeof queBotAuth !== 'undefined' && queBotAuth.currentUser)
                        ? queBotAuth.currentUser.uid : 'anonymous',
                    caseId: window.currentCaseId || null,
                    user_profile: (typeof queBotAuth !== 'undefined')
                        ? queBotAuth.getSearchProfile() : null
                })
            });

            // Check for non-SSE error responses (rate limit, etc.)
            const ct = response.headers.get('content-type') || '';
            if (ct.includes('application/json')) {
                const data = await response.json();
                throw new Error(data.error || data.details || 'Error en la solicitud');
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            // === Parse SSE stream ===
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let currentEvent = '';
            let fullText = '';
            let gotFirstToken = false;
            let metadata = {};

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // Process complete lines
                const lines = buffer.split('\n');
                buffer = lines.pop(); // keep last (potentially incomplete) line

                for (const line of lines) {
                    const trimmed = line.trim();

                    if (trimmed.startsWith('event: ')) {
                        currentEvent = trimmed.substring(7);
                    } else if (trimmed.startsWith('data: ')) {
                        let data;
                        try {
                            data = JSON.parse(trimmed.substring(6));
                        } catch (e) {
                            continue;
                        }

                        switch (currentEvent) {
                            case 'step':
                                // Real pipeline step from backend
                                if (typeof this._onStep === 'function') {
                                    this._onStep(data.stage, data.detail);
                                }
                                break;

                            case 'token':
                                // Incremental LLM token
                                fullText += data.t;
                                if (!gotFirstToken) {
                                    gotFirstToken = true;
                                    // Signal transition from thinking to streaming
                                    if (typeof this._onStreamStart === 'function') {
                                        this._onStreamStart();
                                    }
                                }
                                onChunk(data.t, fullText, null);
                                break;

                            case 'response':
                                // Full response text (backup for non-streaming clients)
                                if (!fullText && data.text) {
                                    fullText = data.text;
                                    gotFirstToken = true;
                                    if (typeof this._onStreamStart === 'function') {
                                        this._onStreamStart();
                                    }
                                    onChunk(fullText, fullText, null);
                                }
                                break;

                            case 'done':
                                // Extract metadata
                                metadata = data.metadata || {};
                                metadata.searched = data.searched || false;
                                metadata.legal_used = data.legalResults || false;
                                metadata.profile_update = data.profile_update || null;
                                metadata.mode = data.mode || null;
                                metadata.mode_label = data.mode_label || null;
                                metadata.search_vertical = data.searchVertical || null;
                                metadata.search_intent = data.search_intent || null;
                                break;

                            case 'error':
                                throw new Error(data.message || 'Error del servidor');
                        }
                    }
                }
            }

            // Stream complete
            onComplete(fullText, null, metadata);

        } catch (error) {
            console.error('API SSE Error:', error);
            onError(error.message || 'Error de conexión');
        }
    },

    /** Register step callback (called by App) */
    onStep(fn) { this._onStep = fn; },

    /** Register stream-start callback (called by App) */
    onStreamStart(fn) { this._onStreamStart = fn; },

    /**
     * Check API status (remains JSON)
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
