<?php
/**
 * QueBot - Chat API Endpoint
 * Proxy seguro para la API de Claude/Anthropic con RAG
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search.php';

header('Content-Type: application/json');

// CORS
if (!empty(ALLOWED_ORIGINS)) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, ALLOWED_ORIGINS)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (isset($_GET['status'])) {
    echo json_encode([
        'configured' => isApiConfigured(),
        'model' => CLAUDE_MODEL,
        'features' => ['rag' => true]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

if (!isApiConfigured()) {
    http_response_code(500);
    echo json_encode(['error' => 'API no configurada. Edita api/config.php']);
    exit;
}

// Rate limiting
$rateLimitFile = sys_get_temp_dir() . '/quebot_rate_' . md5($_SERVER['REMOTE_ADDR']);
$currentMinute = floor(time() / 60);
$rateData = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) ?? [] : [];

if (isset($rateData['minute']) && $rateData['minute'] === $currentMinute) {
    if ($rateData['count'] >= RATE_LIMIT_PER_MINUTE) {
        http_response_code(429);
        echo json_encode(['error' => 'Demasiadas solicitudes. Intenta en un momento.']);
        exit;
    }
    $rateData['count']++;
} else {
    $rateData = ['minute' => $currentMinute, 'count' => 1];
}
file_put_contents($rateLimitFile, json_encode($rateData));

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['messages']) || !is_array($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato de mensaje inválido']);
    exit;
}

$messages = array_map(function($msg) {
    return [
        'role' => in_array($msg['role'], ['user', 'assistant']) ? $msg['role'] : 'user',
        'content' => substr(trim($msg['content'] ?? ''), 0, 100000)
    ];
}, array_filter($input['messages'], function($msg) {
    return !empty($msg['content']);
}));

if (empty($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'No hay mensajes válidos']);
    exit;
}

// === RAG: Check if last message needs web search ===
$lastMessage = end($messages);
$searchContext = '';
$searchPerformed = false;

if ($lastMessage['role'] === 'user' && needsWebSearch($lastMessage['content'])) {
    $searchQuery = extractSearchQuery($lastMessage['content']);
    $searchResults = searchWeb($searchQuery, 5);
    
    if (!empty($searchResults['results'])) {
        $searchContext = formatSearchResultsForPrompt($searchResults);
        $searchPerformed = true;
    }
}

// Build system prompt with search context
$systemPrompt = SYSTEM_PROMPT;
if ($searchContext) {
    $systemPrompt .= "\n\n---\n\n**INFORMACIÓN DE BÚSQUEDA WEB (usa esto para responder):**\n\n" . $searchContext;
    $systemPrompt .= "\n\n**IMPORTANTE:** Basa tu respuesta en estos resultados de búsqueda. Cita las fuentes cuando sea relevante. Si la información no es suficiente, indícalo.";
}

// Prepare Claude request
$requestBody = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => MAX_TOKENS,
    'system' => $systemPrompt,
    'messages' => array_values($messages),
    'stream' => true
];

// Make request to Claude
$ch = curl_init('https://api.anthropic.com/v1/messages');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestBody),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function($ch, $data) use ($searchPerformed) {
        static $headersSent = false;
        static $firstChunk = true;
        
        if (!$headersSent) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            $headersSent = true;
            
            // Send search indicator
            if ($searchPerformed) {
                echo "data: " . json_encode(['meta' => 'search_performed']) . "\n\n";
                flush();
            }
        }
        
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, 'data: ') === 0) {
                $jsonData = substr($line, 6);
                
                if ($jsonData === '[DONE]') {
                    echo "data: [DONE]\n\n";
                    flush();
                    continue;
                }
                
                $parsed = json_decode($jsonData, true);
                
                if ($parsed && isset($parsed['type'])) {
                    switch ($parsed['type']) {
                        case 'content_block_delta':
                            if (isset($parsed['delta']['text'])) {
                                echo "data: " . json_encode(['content' => $parsed['delta']['text']]) . "\n\n";
                                flush();
                            }
                            break;
                        case 'message_stop':
                            echo "data: [DONE]\n\n";
                            flush();
                            break;
                        case 'error':
                            echo "data: " . json_encode(['error' => $parsed['error']['message'] ?? 'Error desconocido']) . "\n\n";
                            flush();
                            break;
                    }
                }
            }
        }
        
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 120
]);

$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión: ' . $error]);
}
