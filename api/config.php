<?php
// Get API key from environment
$apiKey = getenv('CLAUDE_API_KEY') ?: '';

// Define constants used by chat.php
define('ANTHROPIC_API_KEY', $apiKey);
define('CLAUDE_API_KEY', $apiKey);
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');
define('MAX_TOKENS', 4096);
define('SYSTEM_PROMPT', 'Eres QueBot, un asistente inteligente y amigable. Responde de forma clara y útil.');
define('RATE_LIMIT_PER_MINUTE', 20);
define('ALLOWED_ORIGINS', []);

// Function to check if API is configured
function isApiConfigured() {
    return !empty(ANTHROPIC_API_KEY);
}
