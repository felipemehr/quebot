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

// ============================================
// SMART SEARCH DECISION
// ============================================

$searchResults = null;
$shouldSearch = false;
$searchQuery = $userMessage;
$messageLower = mb_strtolower(trim($userMessage), 'UTF-8');
$wordCount = str_word_count($messageLower);

// ============================================
// STEP 1: Detect follow-up / short messages
// ============================================

$followUpWords = [
    'sigue', 'continua', 'continúa', 'dale', 'ok', 'ya', 'listo',
    'bien', 'bueno', 'gracias', 'thanks', 'si', 'sí', 'no', 'vale',
    'eso', 'claro', 'perfecto', 'genial', 'bacán', 'cachay', 'cacha',
    'oka', 'okey', 'okay', 'wena', 'buena', 'entiendo', 'sipo', 'nopo',
    'más', 'mas', 'otro', 'otra', 'venga', 'vamos', 'repite', '2', '1', '3'
];

$isFollowUp = false;

if ($wordCount <= 1) {
    if (in_array($messageLower, $followUpWords)) {
        $isFollowUp = true;
    }
    $typoFollowUps = ['ylo', 'ylos', 'yla', 'ylas', 'yel', 'ylos', 'qmas', 'yq', 'oq'];
    if (in_array($messageLower, $typoFollowUps)) {
        $isFollowUp = true;
    }
}

if (!$isFollowUp && $wordCount <= 3) {
    $followUpPhrases = [
        'sigue nomas', 'sigue nomás', 'dale nomas', 'dale nomás',
        'y lo', 'y la', 'y el', 'y los', 'y las', 'y que', 'y como',
        'y donde', 'y cuando', 'y eso', 'que mas', 'qué más',
        'como sigue', 'cómo sigue', 'dime mas', 'dime más',
        'cuentame mas', 'cuéntame más', 'algo mas', 'algo más',
        'ta bien', 'esta bien', 'está bien', 'muy bien',
        'que otro', 'que otra', 'qué más', 'muéstrame', 'muestrame',
        'y los links', 'y el link', 'y las url', 'los links',
        'el mapa', 'la tabla', 'el grafico'
    ];
    foreach ($followUpPhrases as $phrase) {
        if (mb_strpos($messageLower, $phrase) === 0) {
            $isFollowUp = true;
            break;
        }
    }
}

// ============================================
// STEP 2: Detect "search again" requests
// ============================================

$isRepeatSearch = false;
if (!$isFollowUp) {
    $repeatPatterns = [
        'busca otra vez', 'busca de nuevo', 'busca denuevo',
        'repite la busqueda', 'repite la búsqueda', 'repite busqueda',
        'vuelve a buscar', 'haz la busqueda', 'haz la búsqueda',
        'intenta otra vez', 'intenta de nuevo', 'otra busqueda',
        'misma busqueda', 'la misma busqueda'
    ];
    foreach ($repeatPatterns as $pattern) {
        if (mb_strpos($messageLower, $pattern) !== false) {
            $isRepeatSearch = true;
            break;
        }
    }
    if (!$isRepeatSearch && $wordCount <= 3) {
        if (preg_match('/^(repite|otra vez|de nuevo|busca otra|busca again)$/iu', $messageLower)) {
            $isRepeatSearch = true;
        }
    }
}

if ($isRepeatSearch && !empty($history)) {
    $extractedTopic = '';
    for ($i = count($history) - 1; $i >= 0; $i--) {
        if (isset($history[$i]['role']) && $history[$i]['role'] === 'user') {
            $histMsg = mb_strtolower(trim($history[$i]['content']), 'UTF-8');
            $histWordCount = str_word_count($histMsg);
            if ($histWordCount >= 3 && !preg_match('/^(busca otra|repite|continua|sigue|dale|y lo|y los)/iu', $histMsg)) {
                $extractedTopic = trim($history[$i]['content']);
                break;
            }
        }
    }
    if (!empty($extractedTopic)) {
        $shouldSearch = true;
        $searchQuery = $extractedTopic;
        $isFollowUp = false;
    } else {
        $isFollowUp = true;
    }
}

// ============================================
// STEP 3: Determine if new search needed
// ============================================

if (!$isFollowUp && !$shouldSearch && $wordCount >= 3) {
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

    foreach ($searchKeywords as $keyword) {
        if (mb_strpos($messageLower, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }
}

if (!$isFollowUp && $wordCount >= 2 && !$shouldSearch) {
    if (preg_match('/^(busca|buscar|encuentra|search|google)\b/iu', $messageLower)) {
        $shouldSearch = true;
    }
}

// ============================================
// STEP 4: Perform search
// ============================================

if ($shouldSearch) {
    $searchResults = performWebSearch($searchQuery);
}

// ============================================
// STEP 5: Scrape listing pages for details
// ============================================

$scrapedListings = [];
if ($shouldSearch && $searchResults && !empty($searchResults['results'])) {
    $listingUrls = [];
    foreach ($searchResults['results'] as $result) {
        if (isset($result['type']) && $result['type'] === 'listing') {
            $listingUrls[] = $result['url'];
        }
    }
    // Scrape top 2 listing pages for detailed content
    foreach (array_slice($listingUrls, 0, 2) as $listUrl) {
        $scraped = scrapeListingPage($listUrl);
        if ($scraped) {
            $scrapedListings[] = $scraped;
        }
    }
}

// ============================================
// Build messages for Claude
// ============================================

$messages = [];

foreach ($history as $msg) {
    if (isset($msg['role']) && isset($msg['content'])) {
        $messages[] = [
            'role' => $msg['role'],
            'content' => $msg['content']
        ];
    }
}

$fullUserMessage = $userMessage;

if ($searchResults && !empty($searchResults['results'])) {
    $fullUserMessage .= "\n\n---\nRESULTADOS DE BÚSQUEDA WEB (búsqueda: \"" . $searchQuery . "\"):\n";
    foreach ($searchResults['results'] as $i => $result) {
        $num = $i + 1;
        $type = isset($result['type']) ? $result['type'] : 'unknown';
        $typeLabel = '';
        if ($type === 'specific') {
            $typeLabel = ' [PÁGINA ESPECÍFICA]';
        } elseif ($type === 'listing') {
            $typeLabel = ' [PÁGINA DE LISTADO]';
        }
        
        $fullUserMessage .= "\n{$num}. {$result['title']}{$typeLabel}\n";
        $fullUserMessage .= "   URL: {$result['url']}\n";
        if (!empty($result['snippet'])) {
            $fullUserMessage .= "   Info: {$result['snippet']}\n";
        }
    }
    $fullUserMessage .= "\n---\nREGLAS ESTRICTAS:\n";
    $fullUserMessage .= "- Usa SOLO las URLs de arriba. NUNCA inventes URLs.\n";
    $fullUserMessage .= "- URLs [ESPECÍFICA] van directo al item. URLs [LISTADO] son páginas con múltiples resultados.\n";
    $fullUserMessage .= "- NO inventes nombres de propiedades, precios ni superficies que NO aparezcan en estos resultados.\n";
    $fullUserMessage .= "- Si estos resultados NO tienen relación con la conversación, IGNÓRALOS y responde desde el contexto.\n";
}

// Add scraped listing page content
if (!empty($scrapedListings)) {
    $fullUserMessage .= "\n\n---\nCONTENIDO EXTRAÍDO DE PÁGINAS DE LISTADO:\n";
    foreach ($scrapedListings as $scraped) {
        $fullUserMessage .= "\n📄 PÁGINA: {$scraped['url']}\n";
        $fullUserMessage .= "CONTENIDO:\n{$scraped['content']}\n";
        if (!empty($scraped['links'])) {
            $fullUserMessage .= "\nLINKS INTERNOS:\n{$scraped['links']}\n";
        }
        $fullUserMessage .= "\n---\n";
    }
    $fullUserMessage .= "\nEXTRAE las propiedades individuales de este contenido.\n";
    $fullUserMessage .= "Presenta en tabla: Link, Superficie, Precio, Precio/m², Atractivos, Contras, Rating (⭐1-5).\n";
    $fullUserMessage .= "Solo datos reales del contenido. Si falta dato, pon 'N/E'. NO inventes.\n";
}

if ($isRepeatSearch && !empty($extractedTopic)) {
    $fullUserMessage .= "\n[NOTA: El usuario pidió repetir la búsqueda anterior sobre: \"" . $extractedTopic . "\"]";
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

echo json_encode([
    'response' => $assistantResponse,
    'model' => CLAUDE_MODEL,
    'searched' => $shouldSearch,
    'searchQuery' => $shouldSearch ? $searchQuery : null,
    'scraped' => count($scrapedListings)
]);
