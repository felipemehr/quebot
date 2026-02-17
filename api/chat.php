<?php
// Suppress errors to prevent HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Handle status check (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    echo json_encode([
        'configured' => defined('ANTHROPIC_API_KEY') && !empty(ANTHROPIC_API_KEY) && ANTHROPIC_API_KEY !== 'your-api-key-here',
        'version' => '2.0',
        'features' => ['search', 'visualization']
    ]);
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
    $searchKeywords = ['busca', 'buscar', 'encuentra', 'encontrar', 'parcelas', 'propiedades', 'terrenos', 'casas', 'departamentos', 'noticias', 'precio', 'dolar', 'clima', 'actualidad', 'informacion', 'datos'];
    $shouldSearch = false;
    foreach ($searchKeywords as $keyword) {
        if (stripos($userMessage, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }

    // Perform search if needed
    $searchResults = [];
    $searchContext = '';
    if ($shouldSearch) {
        require_once 'search.php';
        $searchResults = performSearch($userMessage, 8);
        
        if (!empty($searchResults)) {
            $searchContext = "\n\n=== RESULTADOS DE BUSQUEDA WEB (DATOS REALES) ===\n";
            foreach ($searchResults as $i => $result) {
                $searchContext .= ($i + 1) . ". {$result['title']}\n";
                $searchContext .= "   URL: {$result['url']}\n";
                $searchContext .= "   {$result['snippet']}\n\n";
            }
            $searchContext .= "=== FIN DE RESULTADOS ===\n";
            $searchContext .= "\nIMPORTANTE: Usa SOLO estos URLs reales. NO inventes links.\n";
        }
    }

    // Build messages array
    $messages = [];

    // Add conversation history
    foreach ($conversationHistory as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
    }

    // Add current message with search context
    $messages[] = [
        'role' => 'user',
        'content' => $userMessage . $searchContext
    ];

    // Enhanced system prompt for visualizations
    $systemPrompt = SYSTEM_PROMPT . "\n\n" . 
    "CAPACIDADES DE VISUALIZACION:\n" .
    "Cuando respondas sobre propiedades o busquedas geograficas, puedes incluir datos para mostrar un mapa.\n" .
    "Para hacerlo, incluye al final de tu respuesta un bloque JSON con el formato:\n" .
    "\n" .
    "```json:viz\n" .
    "{\n" .
    "  \"type\": \"map\",\n" .
    "  \"title\": \"Parcelas en Melipeuco\",\n" .
    "  \"locations\": [\n" .
    "    {\"lat\": -38.85, \"lng\": -71.60, \"title\": \"Parcela 1\", \"price\": 18000000, \"url\": \"https://...\"},\n" .
    "    ...\n" .
    "  ]\n" .
    "}\n" .
    "```\n" .
    "\n" .
    "COORDENADAS DE REFERENCIA EN CHILE:\n" .
    "- Melipeuco: -38.85, -71.60\n" .
    "- Pucon: -39.28, -71.95\n" .
    "- Villarrica: -39.28, -72.22\n" .
    "- Cunco: -38.93, -72.03\n" .
    "- Curarrehue: -39.36, -71.59\n" .
    "- Temuco: -38.74, -72.60\n" .
    "\n" .
    "Usa coordenadas aproximadas variando ligeramente para cada propiedad.\n" .
    "Los URLs DEBEN ser los reales de los resultados de busqueda.";

    // Prepare API request
    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => MAX_TOKENS,
        'system' => $systemPrompt,
        'messages' => $messages
    ];

    // Make request to Claude API
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
        CURLOPT_TIMEOUT => 60
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
        echo json_encode(['error' => $result['error']['message'] ?? 'API error', 'details' => $result]);
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

    // Parse visualization data from response
    $vizData = null;
    if (preg_match('/```json:viz\s*([\s\S]*?)```/i', $responseText, $matches)) {
        $vizData = json_decode(trim($matches[1]), true);
        // Remove viz block from visible response
        $responseText = preg_replace('/```json:viz\s*[\s\S]*?```/i', '', $responseText);
        $responseText = trim($responseText);
    }

    echo json_encode([
        'response' => $responseText,
        'visualization' => $vizData,
        'searchResults' => $searchResults,
        'usage' => $result['usage'] ?? null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
