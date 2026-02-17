<?php
/**
 * QueBot - Configuración
 * 
 * IMPORTANTE: Coloca aquí tu API key de Claude/Anthropic
 * Obtén tu API key en: https://console.anthropic.com
 * 
 * SEGURIDAD: Este archivo NO debe ser accesible públicamente.
 * El .htaccess incluido protege este directorio.
 */

// ============================================
// CONFIGURACIÓN DE LA API
// ============================================

// Tu API key de Anthropic/Claude
// Reemplaza 'TU_API_KEY_AQUI' con tu key real
define('ANTHROPIC_API_KEY', 'TU_API_KEY_AQUI');

// Modelo a usar
// Opciones: claude-sonnet-4-20250514, claude-3-5-sonnet-20241022, claude-3-opus-20240229
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');

// Máximo de tokens en la respuesta
define('MAX_TOKENS', 4096);

// ============================================
// CONFIGURACIÓN DEL SISTEMA
// ============================================

// Prompt del sistema (personaliza según tu caso de uso)
define('SYSTEM_PROMPT', '
Eres QueBot, un asistente inteligente amigable y profesional.

Tu personalidad:
- Eres conciso y directo, pero amable
- Usas un tono profesional pero accesible
- Cuando es apropiado, agregas un toque de humor sutil
- Respondes en español por defecto, pero puedes usar otros idiomas si el usuario lo prefiere

Capacidades:
- Puedes ayudar con análisis, escritura, programación, y tareas generales
- Formateas tus respuestas con Markdown cuando es útil
- Usas bloques de código con el lenguaje especificado para código
- Creas tablas cuando ayudan a organizar información

Limitaciones que reconoces:
- No tienes acceso a internet en tiempo real
- Tu conocimiento tiene fecha de corte
- Puedes cometer errores, invitas al usuario a verificar información importante
');

// ============================================
// CONFIGURACIÓN DE CORS (si es necesario)
// ============================================

// Dominios permitidos para CORS (dejar vacío para mismo origen)
// Ejemplo: ['https://tudominio.com', 'https://www.tudominio.com']
define('ALLOWED_ORIGINS', []);

// ============================================
// RATE LIMITING (básico)
// ============================================

// Máximo de requests por minuto por IP
define('RATE_LIMIT_PER_MINUTE', 20);

// ============================================
// NO MODIFICAR DEBAJO DE ESTA LÍNEA
// ============================================

// Verificar si la API está configurada
function isApiConfigured() {
    return ANTHROPIC_API_KEY !== 'TU_API_KEY_AQUI' && 
           ANTHROPIC_API_KEY !== '' && 
           strlen(ANTHROPIC_API_KEY) > 20;
}
