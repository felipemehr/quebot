<?php
// Suppress errors to prevent HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(120);

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    echo json_encode(['configured' => !empty(ANTHROPIC_API_KEY), 'version' => '2.2', 'features' => ['search', 'visualization']]);
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

    // Expanded search detection - be more aggressive about searching
    $searchKeywords = [
        // Verbos de búsqueda
        'busca', 'buscar', 'encuentra', 'encontrar', 'muestra', 'mostrar', 'dame', 'quiero ver',
        // Propiedades
        'parcela', 'parcelas', 'terreno', 'terrenos', 'propiedad', 'propiedades', 'casa', 'casas',
        'departamento', 'depto', 'arriendo', 'venta', 'inmobiliario',
        // Información actual
        'noticias', 'precio', 'dolar', 'dólar', 'clima', 'tiempo', 'cotización',
        // Lugares específicos
        'melipeuco', 'santiago', 'chile',
        // Comparaciones
        'mejor', 'mejores', 'comparar', 'comparación', 'top', 'ranking',
        // Links
        'link', 'links', 'url', 'página', 'sitio', 'web',
        // Características
        'agua', 'luz', 'electricidad', 'pozo', 'vertiente', 'estero',
        // Precios
        'millones', 'uf', 'precio', 'costo', 'valor', 'barato', 'económico'
    ];
    
    $shouldSearch = false;
    $lowerMessage = strtolower($userMessage);
    foreach ($searchKeywords as $keyword) {
        if (stripos($lowerMessage, $keyword) !== false) {
            $shouldSearch = true;
            break;
        }
    }

    // Perform search
    $searchResults = [];
    $searchContext = '';
    
    if ($shouldSearch) {
        try {
            require_once 'search.php';
            $searchResults = performSearch($userMessage, 10); // Get more results
            
            if (!empty($searchResults)) {
                $searchContext = "\n\n=== RESULTADOS DE BÚSQUEDA WEB (DATOS REALES) ===\n";
                $searchContext .= "Fecha de búsqueda: " . date('Y-m-d H:i') . "\n\n";
                foreach ($searchResults as $i => $result) {
                    $searchContext .= "RESULTADO " . ($i + 1) . ":\n";
                    $searchContext .= "  Título: {$result['title']}\n";
                    $searchContext .= "  URL: {$result['url']}\n";
                    $searchContext .= "  Descripción: {$result['snippet']}\n\n";
                }
                $searchContext .= "=== FIN DE RESULTADOS ===\n\n";
                $searchContext .= "INSTRUCCIONES: Usa ÚNICAMENTE las URLs listadas arriba. NO inventes links.\n";
                $searchContext .= "Si el usuario pide 'los 3 mejores', selecciona los más relevantes de estos resultados.\n";
            } else {
                $searchContext = "\n\n[BÚSQUEDA REALIZADA - SIN RESULTADOS]\n";
                $searchContext .= "La búsqueda no encontró resultados relevantes. ";
                $searchContext .= "Informa al usuario y sugiere términos alternativos.\n";
            }
        } catch (Exception $e) {
            $searchContext = "\n\n[ERROR EN BÚSQUEDA: " . $e->getMessage() . "]\n";
            $searchContext .= "Informa al usuario que hubo un problema técnico con la búsqueda.\n";
        }
    }

    // Build messages array
    $messages = [];
    foreach ($conversationHistory as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }

    // Add current message with search context
    $finalMessage = $userMessage;
    if (!empty($searchContext)) {
        $finalMessage .= $searchContext;
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
        'searchPerformed' => $shouldSearch,
        'searchResultsCount' => count($searchResults),
        'usage' => $result['usage'] ?? null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
