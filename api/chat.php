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
    echo json_encode(['error' => 'Mensaje vac\u00edo']);
    exit;
}

if (!isApiConfigured()) {
    http_response_code(500);
    echo json_encode(['error' => 'API key no configurada']);
    exit;
}

// ============================================
// SMART SEARCH DECISION
// ============================================

$searchResults = null;
$shouldSearch = false;
$messageLower = mb_strtolower(trim($userMessage), 'UTF-8');
$wordCount = str_word_count($messageLower);

// Messages that are FOLLOW-UPS (never search for these)
$followUpPatterns = [
    '/^(sigue|continua|contin\x{fa}a|dale|ok|ya|listo|bien|bueno|gracias|thanks|si|s\x{ed}|no|vale|eso|claro|perfecto|genial|bacán|bac\x{e1}n|cachay|cacha|oka|okey|okay|wena|buena|ta bien|entiendo|sipo|nopo|y\?|m\x{e1}s|mas|otro|otra|sigue nomás|sigue nomas|dale nomas|vamos|venga|muestrame|mu\x{e9}strame|explica|explícame|expl\x{ed}came)$/iu',
    '/^y (lo|la|el|los|las|que|como|donde|cuando|eso)\b/iu',
    '/^(que|qué|como|cómo) (más|mas|sigue|otro)/iu',
    '/^(cuéntame|cuentame|dime) (más|mas)/iu'
];

$isFollowUp = false;
foreach ($followUpPatterns as $pattern) {
    if (preg_match($pattern, $messageLower)) {
        $isFollowUp = true;
        break;
    }
}

// Only search if:
// 1. NOT a follow-up message
// 2. Message has at least 3 words (avoids typo-only searches)
// 3. Contains a search-triggering keyword
if (!$isFollowUp && $wordCount >= 3) {
    $searchKeywords = [
        'busca', 'buscar', 'encuentra', 'encontrar', 'search',
        'parcela', 'parcelas', 'terreno', 'terrenos', 'propiedad', 'propiedades',
        'casa', 'casas', 'departamento', 'departamentos', 'arriendo', 'venta',
        'precio', 'precios', 'costo', 'costos', 'valor',
        'noticias', 'news', 'actualidad', 'hoy',
        'd\x{f3}lar', 'dolar', 'uf', 'utm', 'moneda', 'cambio',
        'clima', 'tiempo', 'weather',
        'restaurante', 'restaurantes', 'hotel', 'hoteles',
        'vuelo', 'vuelos', 'pasaje', 'pasajes',
        'donde', 'd\x{f3}nde', 'ubicaci\x{f3}n', 'direcci\x{f3}n',
        'tel\x{e9}fono', 'contacto', 'horario',
        'informaci\x{f3}n sobre', 'datos de', 'info de',
        'sitio', 'p\x{e1}gina', 'web', 'link', 'url',
        'mejor', 'mejores', 'top', 'ranking',
        'comparar', 'comparaci\x{f3}n', 'versus', 'vs',
        'cu\x{e1}nto', 'cuanto', 'cu\x{e1}l', 'cual', 'qui\x{e9}n', 'quien',
        'c\x{f3}mo llegar', 'como llegar', 'ruta', 'mapa',
        'empresa', 'empresas', 'compa\x{f1}\x{ed}a', 'negocio',
        'producto', 'productos', 'servicio', 'servicios',
        'oferta', 'ofertas', 'descuento', 'promoci\x{f3}n',
        'evento', 'eventos', 'concierto', 'show',
        'curso', 'cursos', 'carrera', 'universidad',
        'trabajo', 'empleo', 'vacante', 'sueldo',
        'ley', 'legal', 'tr\x{e1}mite', 'documento',
        'melipeuco', 'temuco', 'santiago', 'valpara\x{ed}so', 'chile',
        'inmobiliaria', 'corredora', 'corredor'
    ];

    foreach ($searchKeywords as $keyword) {
        if (mb_strpos($messageLower, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }
}

// Also search if message is 2 words but EXPLICITLY asking to search
if (!$isFollowUp && $wordCount >= 2 && !$shouldSearch) {
    if (preg_match('/^(busca|buscar|encuentra|search|google)\b/iu', $messageLower)) {
        $shouldSearch = true;
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
    $fullUserMessage .= "\n\n---\nRESULTADOS DE B\x{da}SQUEDA WEB:\n";
    foreach ($searchResults['results'] as $i => $result) {
        $num = $i + 1;
        $type = isset($result['type']) ? $result['type'] : 'unknown';
        $typeLabel = '';
        if ($type === 'specific') {
            $typeLabel = ' [P\x{c1}GINA ESPEC\x{cd}FICA]';
        } elseif ($type === 'listing') {
            $typeLabel = ' [P\x{c1}GINA DE LISTADO]';
        }
        
        $fullUserMessage .= "\n{$num}. {$result['title']}{$typeLabel}\n";
        $fullUserMessage .= "   URL: {$result['url']}\n";
        if (!empty($result['snippet'])) {
            $fullUserMessage .= "   Info: {$result['snippet']}\n";
        }
    }
    $fullUserMessage .= "\n---\nREGLAS: Usa SOLO URLs de arriba. URLs [ESPEC\x{cd}FICA] van directo al item. URLs [LISTADO] son paginas con multiples resultados, NO las presentes como una propiedad individual.";
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
    echo json_encode(['error' => 'Error de conexi\x{f3}n: ' . $error]);
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
    echo json_encode(['error' => 'Respuesta inv\x{e1}lida de Claude']);
    exit;
}

$assistantResponse = $data['content'][0]['text'];

// Return response
echo json_encode([
    'response' => $assistantResponse,
    'model' => CLAUDE_MODEL,
    'searched' => $shouldSearch
]);
