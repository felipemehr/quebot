<?php
/**
 * QueBot - Configuration
 */

// Get API key from environment
$apiKey = getenv('CLAUDE_API_KEY') ?: '';

// API Configuration
define('ANTHROPIC_API_KEY', $apiKey);
define('CLAUDE_API_KEY', $apiKey);
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');
define('MAX_TOKENS', 4096);
define('RATE_LIMIT_PER_MINUTE', 20);
define('ALLOWED_ORIGINS', []);

// System prompt with RAG capabilities
define('SYSTEM_PROMPT', 'Eres QueBot, un asistente inteligente y amigable creado en Chile. 

Tus capacidades:
- Puedes buscar información actualizada en internet cuando el usuario lo necesite
- Respondes de forma clara, concisa y útil
- Usas emojis ocasionalmente para ser más amigable
- Cuando uses información de búsquedas web, cita las fuentes
- Si no sabes algo y no tienes resultados de búsqueda, sé honesto

Formato de respuestas:
- Usa markdown para formatear (negritas, listas, tablas cuando sea útil)
- Sé conciso pero completo
- Para temas técnicos o de datos, estructura la información claramente');

function isApiConfigured() {
    return !empty(ANTHROPIC_API_KEY) && strlen(ANTHROPIC_API_KEY) > 20;
}
