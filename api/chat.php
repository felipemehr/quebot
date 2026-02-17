<?php
// Suppress errors to prevent HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(120); // Allow up to 2 minutes

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Handle status check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    echo json_encode(['configured' => !empty(ANTHROPIC_API_KEY), 'version' => '2.1', 'features' => ['search', 'visualization']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['message']) || empty(trim($input['message']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required']);
        exit;
    }

    $userMessage = trim($input['message']);
    $conversationHistory = $input['history'] ?? [];

    // Check if this looks like a search query
    $searchKeywords = ['busca', 'buscar', 'encuentra', 'encontrar', 'parcelas', 'propiedades', 'terrenos', 'casas', 'noticias', 'precio', 'dolar', 'clima'];
    $shouldSearch = false;
    foreach ($searchKeywords as $keyword) {
        if (stripos($userMessage, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }

    // Perform search with error handling
    $searchResults = [];
    $searchContext = '';
    $searchError = '';
    
    if ($shouldSearch) {
        try {
            require_once 'search.php';
            $searchResults = performSearch($userMessage, 6);
            
            if (!empty($searchResults)) {
                $searchContext = "\n\n=== RESULTADOS DE BUSQUEDA WEB ===\n";
                foreach ($searchResults as $i => $result) {
                    $searchContext .= ($i + 1) . ". {$result['title']}\n";
                    $searchContext .= "   URL: {$result['url']}\n";
                    $searchContext .= "   {$result['snippet']}\n\n";
                }
                $searchContext .= "=== FIN ===\nIMPORTANTE: Usa SOLO estos URLs reales.\n";
            }
        } catch (Exception $e) {
            $searchError = $e->getMessage();
        }
    }

    // Build messages array
    $messages = [];
    foreach ($conversationHistory as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }

    // Add current message
    $finalMessage = $userMessage;
    if (!empty($searchContext)) {
        $finalMessage .= $searchContext;
    } elseif ($shouldSearch && empty($searchResults)) {
        $finalMessage .= "\n\n(La busqueda web no arrojó resultados. Responde con tu conocimiento general.)";
    }
    $messages[] = ['role' => 'user', 'content' => $finalMessage];

    // Prepare API request
    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => MAX_TOKENS,
        'system' => SYSTEM_PROMPT,
        'messages' => $messages
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 90
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        http_response_code(500);
        echo json_encode(['error' => 'API request failed: ' . $error]);
        exit;
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['error' => $result['error']['message'] ?? 'API error']);
        exit;
    }

    // Extract response text
    $responseText = '';
    if (isset($result['content']) && is_array($result['content'])) {
        foreach ($result['content'] as $block) {
            if ($block['type'] === 'text') {
                $responseText .= $block['text'];
            }
        }
    }

    echo json_encode([
        'response' => $responseText,
        'visualization' => null,
        'searchResults' => $searchResults,
        'usage' => $result['usage'] ?? null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
