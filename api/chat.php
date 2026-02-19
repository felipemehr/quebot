<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/../services/legal/LegalSearch.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Status check endpoint (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    echo json_encode([
        'configured' => !empty(CLAUDE_API_KEY),
        'status' => 'ok'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// === START TIMING ===
$startTime = microtime(true);

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$userId = $input['userId'] ?? 'anonymous';
$userName = $input['userName'] ?? '';

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

/**
 * Sanitize text for JSON encoding - remove invalid UTF-8 sequences
 */
function sanitizeUtf8(string $text): string {
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    return $text;
}

// --- Determine if we should search ---
$shouldSearch = false;
$searchQuery = $message;

$messageWords = str_word_count($message);
$messageLower = mb_strtolower($message);

// Typo patterns - NOT search triggers
$typoPatterns = ['/^ylo\b/', '/^ylos\b/', '/^yla\b/', '/^ysus\b/'];
$isTypo = false;
foreach ($typoPatterns as $tp) {
    if (preg_match($tp, $messageLower)) $isTypo = true;
}

// Follow-up patterns - repeat previous search
$followUpPatterns = ['busca otra vez', 'repite', 'repítelo', 'busca de nuevo', 'otra vez', 'intenta de nuevo', 'vuelve a buscar'];
$isFollowUp = false;
foreach ($followUpPatterns as $fp) {
    if (strpos($messageLower, $fp) !== false) {
        $isFollowUp = true;
        for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
            if (isset($conversationHistory[$i]['role']) && $conversationHistory[$i]['role'] === 'user') {
                $prevMsg = $conversationHistory[$i]['content'] ?? '';
                if (strlen($prevMsg) > 5 && !preg_match('/^(sigue|dale|continua|busca otra)/i', $prevMsg)) {
                    $searchQuery = $prevMsg;
                    break;
                }
            }
        }
        $shouldSearch = true;
        break;
    }
}

// Short messages (1-2 words) - don't search
if (!$isFollowUp && !$isTypo && $messageWords >= 3) {
    $searchKeywords = ['busca', 'buscar', 'encuentra', 'encontrar', 'dónde', 'donde', 'cuánto', 'cuanto', 
                       'precio', 'costo', 'valor', 'noticias', 'clima', 'tiempo', 'dólar', 'uf ',
                       'parcela', 'casa', 'depto', 'departamento', 'terreno', 'arriendo', 'venta',
                       'comprar', 'qué es', 'quién es', 'cómo', 'cuál', 'información sobre',
                       'hospital', 'clínica', 'municipalidad', 'notaría', 'registro civil'];
    
    foreach ($searchKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }
    
    $currentInfoTriggers = ['hoy', 'ahora', 'actual', 'últimas', 'recientes', 'esta semana', 'este mes'];
    foreach ($currentInfoTriggers as $trigger) {
        if (strpos($messageLower, $trigger) !== false) {
            $shouldSearch = true;
            break;
        }
    }
}

// --- Get UF value ---
$ufData = getUFValue();
$ufContext = '';
if ($ufData) {
    $ufContext = "\n\n📊 VALOR UF HOY ({$ufData['date']}): \${$ufData['formatted']} CLP (fuente: {$ufData['source']})\n";
    $ufContext .= "Para convertir: Precio_UF × {$ufData['value']} = Precio_CLP\n";
    $ufContext .= "Conversiones: 1 hectárea (ha) = 10.000 m². 1 cuadra = 1,57 ha = 15.700 m².\n";
}

// --- Perform web search if needed ---
$searchContext = '';
$ragStartTime = microtime(true);
if ($shouldSearch) {
    $results = searchDuckDuckGo($searchQuery);
    
    if (!empty($results)) {
        $searchContext = "\n\n🔍 RESULTADOS DE BÚSQUEDA para \"{$searchQuery}\":\n";
        $pagesToScrape = [];
        
        foreach ($results as $i => $r) {
            $num = $i + 1;
            $searchContext .= "{$num}. [{$r['type']}] {$r['title']}\n";
            $searchContext .= "   URL: {$r['url']}\n";
            if (!empty($r['snippet'])) {
                $searchContext .= "   Extracto: {$r['snippet']}\n";
            }
            if ($r['type'] !== 'specific' && count($pagesToScrape) < 3) {
                $pagesToScrape[] = $r['url'];
            }
        }
        
        if (!empty($pagesToScrape)) {
            $searchContext .= "\n📄 CONTENIDO EXTRAÍDO DE PÁGINAS:\n";
            foreach ($pagesToScrape as $pageUrl) {
                $content = scrapePageContent($pageUrl);
                if ($content && strlen($content) > 100) {
                    $searchContext .= "\n--- Contenido de: {$pageUrl} ---\n";
                    $searchContext .= $content . "\n";
                }
            }
        }
    }
}

// --- Legal library search (PostgreSQL RAG) ---
$legalContext = '';
try {
    $rawLegal = LegalSearch::buildContext($message);
    if (!empty($rawLegal)) {
        $legalContext = sanitizeUtf8($rawLegal);
    }
} catch (Exception $e) {
    error_log("Legal search failed: " . $e->getMessage());
}
$ragEndTime = microtime(true);

// --- Build messages for Claude ---
$systemPrompt = SYSTEM_PROMPT . $ufContext;

$messages = [];

$history = array_slice($conversationHistory, -20);
foreach ($history as $msg) {
    if (isset($msg['role']) && isset($msg['content'])) {
        $messages[] = [
            'role' => $msg['role'],
            'content' => sanitizeUtf8($msg['content'])
        ];
    }
}

// Add current message with search + legal context
$userMessage = $message;
if (!empty($searchContext)) {
    $userMessage .= $searchContext;
}
if (!empty($legalContext)) {
    $userMessage .= $legalContext;
}

$messages[] = [
    'role' => 'user',
    'content' => sanitizeUtf8($userMessage)
];

// --- Call Claude API ---
$llmStartTime = microtime(true);

$apiData = [
    'model' => MODEL,
    'max_tokens' => MAX_TOKENS,
    'system' => sanitizeUtf8($systemPrompt),
    'messages' => $messages
];

$jsonPayload = json_encode($apiData, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
if ($jsonPayload === false) {
    error_log("json_encode failed: " . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['error' => 'Internal encoding error']);
    exit;
}

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$llmEndTime = microtime(true);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'API error', 'details' => $response]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? 'Sin respuesta';

// === TIMING & METADATA ===
$endTime = microtime(true);
$timingTotal = round(($endTime - $startTime) * 1000);
$timingRag = round(($ragEndTime - $ragStartTime) * 1000);
$timingLlm = round(($llmEndTime - $llmStartTime) * 1000);

$inputTokens = $data['usage']['input_tokens'] ?? 0;
$outputTokens = $data['usage']['output_tokens'] ?? 0;

// Cost estimate (Claude Sonnet pricing: $3/MTok input, $15/MTok output)
$costEstimate = ($inputTokens * 3 / 1000000) + ($outputTokens * 15 / 1000000);

echo json_encode([
    'response' => $reply,
    'searched' => $shouldSearch,
    'searchQuery' => $shouldSearch ? $searchQuery : null,
    'ufValue' => $ufData ? $ufData['formatted'] : null,
    'legalResults' => !empty($legalContext),
    'metadata' => [
        'model' => MODEL,
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_estimate' => round($costEstimate, 6),
        'timing_total' => $timingTotal,
        'timing_rag' => $timingRag,
        'timing_llm' => $timingLlm
    ]
], JSON_UNESCAPED_UNICODE);
?>
