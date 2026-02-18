<?php
/**
 * QueBot - Chat API Endpoint
 * Handles chat requests to Claude API with web search capability
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'search.php';

// Status check
if (isset($_GET['status'])) {
    echo json_encode([
        'configured' => isApiConfigured(),
        'model' => CLAUDE_MODEL
    ]);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensaje requerido']);
    exit;
}

$userMessage = trim($input['message']);
$history = isset($input['history']) ? $input['history'] : [];
$userContext = isset($input['userContext']) ? trim($input['userContext']) : '';

if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensaje vacío']);
    exit;
}

if (!isApiConfigured()) {
    http_response_code(500);
    echo json_encode(['error' => 'API key no configurada']);
    exit;
}

// Check if message needs web search
$searchResults = null;
$searchKeywords = [
    'busca', 'buscar', 'encuentra', 'encontrar', 'search',
    'parcela', 'parcelas', 'terreno', 'terrenos', 'propiedad', 'propiedades',
    'casa', 'casas', 'departamento', 'departamentos', 'arriendo', 'venta',
    'precio', 'precios', 'costo', 'costos', 'valor',
    'noticias', 'news', 'actualidad', 'hoy',
    'dólar', 'dolar', 'uf', 'utm', 'moneda', 'cambio',
    'clima', 'tiempo', 'weather',
    'restaurante', 'restaurantes', 'hotel', 'hoteles',
    'vuelo', 'vuelos', 'pasaje', 'pasajes',
    'donde', 'dónde', 'ubicación', 'dirección',
    'teléfono', 'contacto', 'horario',
    'información sobre', 'datos de', 'info de',
    'sitio', 'página', 'web', 'link', 'url',
    'mejor', 'mejores', 'top', 'ranking',
    'comparar', 'comparación', 'versus', 'vs',
    'cuánto', 'cuanto', 'cuál', 'cual', 'quién', 'quien',
    'cómo llegar', 'como llegar', 'ruta', 'mapa',
    'empresa', 'empresas', 'compañía', 'negocio',
    'producto', 'productos', 'servicio', 'servicios',
    'oferta', 'ofertas', 'descuento', 'promoción',
    'evento', 'eventos', 'concierto', 'show',
    'curso', 'cursos', 'carrera', 'universidad',
    'trabajo', 'empleo', 'vacante', 'sueldo',
    'ley', 'legal', 'trámite', 'documento',
    'melipeuco', 'temuco', 'santiago', 'valparaíso', 'chile',
    'inmobiliaria', 'corredora', 'corredor'
];

$messageLower = mb_strtolower($userMessage, 'UTF-8');
$shouldSearch = false;
foreach ($searchKeywords as $keyword) {
    if (strpos($messageLower, $keyword) !== false) {
        $shouldSearch = true;
        break;
    }
}

// Perform web search if needed
if ($shouldSearch) {
    $searchResults = performWebSearch($userMessage);
}

// Build messages array
$messages = [];

// Add history
foreach ($history as $msg) {
    if (isset($msg['role']) && isset($msg['content'])) {
        $messages[] = [
            'role' => $msg['role'],
            'content' => $msg['content']
        ];
    }
}

// Build user message with search results and context
$fullUserMessage = $userMessage;

if ($searchResults && !empty($searchResults['results'])) {
    $fullUserMessage .= "\n\n---\nRESULTADOS DE BÚSQUEDA WEB:\n";
    foreach ($searchResults['results'] as $i => $result) {
        $num = $i + 1;
        $type = isset($result['type']) ? $result['type'] : 'unknown';
        $typeLabel = '';
        if ($type === 'specific') {
            $typeLabel = ' [PÁGINA ESPECÍFICA]';
        } elseif ($type === 'listing') {
            $typeLabel = ' [PÁGINA DE LISTADO/BÚSQUEDA]';
        }
        
        $fullUserMessage .= "\n{$num}. {$result['title']}{$typeLabel}\n";
        $fullUserMessage .= "   URL: {$result['url']}\n";
        if (!empty($result['snippet'])) {
            $fullUserMessage .= "   Info: {$result['snippet']}\n";
        }
    }
    $fullUserMessage .= "\n---\nINSTRUCCIONES PARA LINKS:\n";
    $fullUserMessage .= "- Las URLs marcadas [PÁGINA ESPECÍFICA] llevan a una propiedad/item individual - úsalas directamente\n";
    $fullUserMessage .= "- Las URLs marcadas [PÁGINA DE LISTADO/BÚSQUEDA] llevan a un listado con MÚLTIPLES resultados - NO las presentes como si fueran de una propiedad específica\n";
    $fullUserMessage .= "- Para listados, usa el texto 'Ver opciones en [nombre del sitio]' como link\n";
    $fullUserMessage .= "- NUNCA inventes URLs. SOLO usa las URLs exactas de arriba\n";
    $fullUserMessage .= "- Cada propiedad/item que menciones DEBE tener un hipervínculo si tienes su URL real";
}

$messages[] = [
    'role' => 'user',
    'content' => $fullUserMessage
];

// Build system prompt with user context
$systemPrompt = SYSTEM_PROMPT;
if (!empty($userContext)) {
    $systemPrompt .= "\n\n## CONTEXTO DEL USUARIO ACTUAL\n" . $userContext;
}

// Call Claude API
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => CLAUDE_MODEL,
        'max_tokens' => MAX_TOKENS,
        'system' => $systemPrompt,
        'messages' => $messages
    ]),
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $errorMessage = isset($errorData['error']['message']) 
        ? $errorData['error']['message'] 
        : 'Error del servidor (HTTP ' . $httpCode . ')';
    http_response_code($httpCode);
    echo json_encode(['error' => $errorMessage]);
    exit;
}

$data = json_decode($response, true);

if (!isset($data['content'][0]['text'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Respuesta inválida de Claude']);
    exit;
}

$assistantResponse = $data['content'][0]['text'];

// Return response
echo json_encode([
    'response' => $assistantResponse,
    'model' => CLAUDE_MODEL,
    'searched' => $shouldSearch
]);
