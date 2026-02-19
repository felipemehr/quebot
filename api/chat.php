<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/../services/legal/LegalSearch.php';

header('Content-Type: application/json');
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
        // Extract real topic from conversation history
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
    // Search keywords
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
    
    // Current info triggers - ALWAYS search
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
            
            // Scrape non-specific pages (listings and unknown)
            if ($r['type'] !== 'specific' && count($pagesToScrape) < 3) {
                $pagesToScrape[] = $r['url'];
            }
        }
        
        // Scrape listing pages for real property data
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
    $legalContext = LegalSearch::buildContext($message);
} catch (Exception $e) {
    // Legal search is non-blocking - if DB is down, continue without it
    error_log("Legal search failed: " . $e->getMessage());
}

// --- Build messages for Claude ---
$systemPrompt = SYSTEM_PROMPT . $ufContext;

$messages = [];

// Include conversation history (last 20 messages)
$history = array_slice($conversationHistory, -20);
foreach ($history as $msg) {
    if (isset($msg['role']) && isset($msg['content'])) {
        $messages[] = [
            'role' => $msg['role'],
            'content' => $msg['content']
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
    'content' => $userMessage
];

// --- Call Claude API ---
$apiData = [
    'model' => MODEL,
    'max_tokens' => MAX_TOKENS,
    'system' => $systemPrompt,
    'messages' => $messages
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POSTFIELDS => json_encode($apiData),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'API error', 'details' => $response]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? 'Sin respuesta';

echo json_encode([
    'response' => $reply,
    'searched' => $shouldSearch,
    'searchQuery' => $shouldSearch ? $searchQuery : null,
    'ufValue' => $ufData ? $ufData['formatted'] : null,
    'legalResults' => !empty($legalContext)
]);
?>