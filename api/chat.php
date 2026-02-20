<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/../services/legal/LegalSearch.php';
require_once __DIR__ . '/../services/ProfileBuilder.php';
require_once __DIR__ . '/../services/FirestoreAudit.php';

header('Content-Type: application/json; charset=utf-8');

// === CORS VALIDATION ===
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

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

// === RATE LIMITING (file-based with flock) ===
function checkRateLimit(string $identifier): bool {
    $dir = sys_get_temp_dir() . '/quebot_ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    
    $file = $dir . '/' . md5($identifier);
    $now = time();
    $window = 60;
    
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $timestamps = $content ? (json_decode($content, true) ?: []) : [];
    
    $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $now - $window));
    
    if (count($timestamps) >= RATE_LIMIT_PER_MINUTE) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    
    $timestamps[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($timestamps));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$clientIp = trim(explode(',', $clientIp)[0]);

if (!checkRateLimit($clientIp)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Max ' . RATE_LIMIT_PER_MINUTE . ' requests per minute.']);
    exit;
}

// === START TIMING ===
$startTime = microtime(true);


set_time_limit(120); // Allow 120s for search + LLM + profile pipeline

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$userId = $input['userId'] ?? 'anonymous';
$userName = $input['userName'] ?? '';
$userProfile = $input['user_profile'] ?? null;
$caseId = $input['caseId'] ?? null;

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

function sanitizeUtf8(string $text): string {
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    return $text;
}

/**
 * Build profile context string for Claude's system prompt.
 * Only includes fields with actual data.
 */
function buildProfileContext(?array $profile): string {
    if (empty($profile)) return '';

    $lines = [];

    $locs = $profile['locations'] ?? [];
    if (!empty($locs)) {
        $lines[] = "- Zonas de interés: " . implode(', ', $locs);
    }

    $types = $profile['property_types'] ?? [];
    if (!empty($types)) {
        $lines[] = "- Busca: " . implode(', ', $types);
    }

    $bed = $profile['bedrooms'] ?? null;
    $bath = $profile['bathrooms'] ?? null;
    if ($bed || $bath) {
        $parts = [];
        if ($bed) $parts[] = "{$bed} dormitorios";
        if ($bath) $parts[] = "{$bath} baños";
        $lines[] = "- Requerimiento: " . implode(', ', $parts);
    }

    $budget = $profile['budget'] ?? [];
    if (!empty($budget)) {
        $min = $budget['min'] ?? null;
        $max = $budget['max'] ?? null;
        $unit = $budget['unit'] ?? 'UF';
        if ($max) {
            $budgetStr = $min ? "{$min}-{$max} {$unit}" : "hasta {$max} {$unit}";
            $lines[] = "- Presupuesto: {$budgetStr}";
        }
    }

    $area = $profile['min_area_m2'] ?? null;
    if ($area) {
        $lines[] = "- Superficie mínima: {$area} m²";
    }

    $purpose = $profile['purpose'] ?? null;
    if ($purpose) {
        $lines[] = "- Propósito: {$purpose}";
    }

    $family = $profile['family_info'] ?? [];
    if (!empty($family)) {
        $size = $family['size'] ?? null;
        $ages = $family['children_ages'] ?? [];
        if ($size) {
            $familyStr = "Familia de {$size}";
            if (!empty($ages)) {
                $familyStr .= " (hijos: " . implode(', ', $ages) . " años)";
            }
            $lines[] = "- Familia: {$familyStr}";
        }
    }

    $reqs = $profile['key_requirements'] ?? [];
    if (!empty($reqs)) {
        $lines[] = "- Necesidades: " . implode(', ', $reqs);
    }

    $exp = $profile['experience'] ?? null;
    if ($exp) {
        $lines[] = "- Experiencia: {$exp}";
    }

    if (empty($lines)) return '';

    $context = "\n\n👤 PERFIL DEL USUARIO (aprendido de conversaciones anteriores):\n";
    $context .= implode("\n", $lines) . "\n";
    $context .= "Usa esta información para personalizar búsquedas y recomendaciones. ";
    $context .= "No repitas preguntas sobre datos que ya conoces.\n";

    return $context;
}

/**
 * POST-PROCESS URL VALIDATOR
 * 
 * Catches fabricated URLs in Claude's response by comparing against
 * the actual URLs from search results. This is the safety net that
 * catches fabrication even when prompt instructions fail.
 * 
 * Strategy:
 * - Extract all markdown links [text](url) from response
 * - Check each URL against the whitelist from search results
 * - Allow: search result URLs, homepage URLs, legal/government sites
 * - Replace: any deep link NOT in search results → domain homepage
 */
function validateResponseURLs(string $response, array $allowedURLs): array {
    $fabricatedCount = 0;
    
    // Normalize allowed URLs for comparison
    $normalizedAllowed = [];
    foreach ($allowedURLs as $url) {
        $normalizedAllowed[] = rtrim($url, '/');
    }
    
    // Safe domains where any URL is OK (legal, government)
    $alwaysSafeDomains = [
        'leychile.cl', 'bcn.cl', 'sii.cl', 'contraloria.cl',
        'diariooficial.interior.gob.cl', 'pjud.cl', 'minvu.gob.cl',
        'mop.gob.cl', 'dga.mop.gob.cl', 'tesoreria.cl',
        'google.com', 'maps.google.com'
    ];
    
    // Find all markdown links: [text](url)
    $pattern = '/\[([^\]]*)\]\((https?:\/\/[^\)]+)\)/';
    
    $response = preg_replace_callback($pattern, function($match) use ($normalizedAllowed, $alwaysSafeDomains, &$fabricatedCount) {
        $linkText = $match[1];
        $url = $match[2];
        $normalizedUrl = rtrim($url, '/');
        
        // 1. URL is in the search results whitelist → OK
        if (in_array($normalizedUrl, $normalizedAllowed)) {
            return $match[0]; // Keep as-is
        }
        
        // 2. Parse the URL
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        
        if (!$host) return $match[0]; // Malformed, leave it
        
        // 3. Homepage / root URL → OK (recommending a portal)
        if (empty($path) || $path === '/' || $path === '') {
            return $match[0];
        }
        
        // 4. Always-safe domain (legal, government) → OK
        foreach ($alwaysSafeDomains as $safe) {
            if (str_contains($host, $safe)) {
                return $match[0];
            }
        }
        
        // 5. Check if it's a partial match (URL starts with an allowed URL)
        // This handles cases where search result is a listing page and link is to same page with anchor
        foreach ($normalizedAllowed as $allowed) {
            if (str_starts_with($normalizedUrl, $allowed)) {
                return $match[0];
            }
        }
        
        // 6. THIS URL IS FABRICATED — replace with domain homepage
        $fabricatedCount++;
        $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'https';
        $safeUrl = "{$scheme}://{$host}";
        
        error_log("URL fabricada detectada y reemplazada: {$url} → {$safeUrl}");
        
        return "[{$linkText}]({$safeUrl})";
    }, $response);
    
    // Also catch bare URLs that aren't in markdown links
    // Pattern: URLs not inside parentheses (already handled above)
    // We focus on markdown links since that's how Claude formats them
    
    return [
        'response' => $response,
        'fabricated_count' => $fabricatedCount
    ];
}

// --- Determine if we should search ---
$shouldSearch = false;
$searchQuery = $message;

$messageWords = str_word_count($message);
$messageLower = mb_strtolower($message);

$typoPatterns = ['/^ylo\b/', '/^ylos\b/', '/^yla\b/', '/^ysus\b/'];
$isTypo = false;
foreach ($typoPatterns as $tp) {
    if (preg_match($tp, $messageLower)) $isTypo = true;
}

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

if (!$isFollowUp && !$isTypo && $messageWords >= 2) {
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

// === SEARCH VIA ORCHESTRATOR ===
$searchContext = '';
$searchVertical = null;
$searchProviderUsed = null;
$searchValidURLs = []; // URLs allowed in response
$searchIntent = null;
$searchDiagnostics = null;
$searchValidListings = 0;
$searchInsufficient = false;
$searchExpansionSuggestions = [];
$searchQueriesUsed = [];
$ragStartTime = microtime(true);

if ($shouldSearch) {
    try {
        $orchestrator = new SearchOrchestrator(CLAUDE_API_KEY, false);

        $vertical = DomainPolicy::detectVertical($searchQuery);
        $isPropertyQuery = ($vertical === 'real_estate');

        $options = [
            'max_results' => 10,
            'scrape_pages' => $isPropertyQuery ? 5 : 3,
            'scrape_max_length' => $isPropertyQuery ? 5000 : 3000,
        ];

        $searchResult = $orchestrator->search($searchQuery, $vertical, $options);

        $searchContext = $searchResult['context_for_llm'] ?? '';
        $searchVertical = $searchResult['vertical'] ?? null;
        $searchProviderUsed = $searchResult['provider_used'] ?? null;
        $searchValidURLs = $searchResult['valid_urls'] ?? [];
        $searchIntent = $searchResult['intent'] ?? null;
        $searchDiagnostics = $searchResult['diagnostics'] ?? null;
        $searchValidListings = $searchResult['valid_listings'] ?? 0;
        $searchInsufficient = $searchResult['insufficient'] ?? false;
        $searchExpansionSuggestions = $searchResult['expansion_suggestions'] ?? [];
        $searchQueriesUsed = $searchResult['queries_used'] ?? [];
        
        // === FIRESTORE AUDIT: Log search run ===
        try {
            $searchRunId = FirestoreAudit::logSearchRun([
                'case_id' => $caseId ?? '',
                'user_id' => $userId,
                'user_query' => $searchQuery,
                'vertical' => $searchVertical,
                'intent' => $searchIntent,
                'queries_built' => $searchQueriesUsed,
                'provider' => $searchProviderUsed,
                'raw_results_count' => $searchDiagnostics['total_raw_results'] ?? 0,
                'valid_listings' => $searchValidListings,
                'insufficient' => $searchInsufficient,
                'expansion_suggestions' => $searchExpansionSuggestions,
                'top_results' => $searchResult['results'] ?? [],
                'timing_ms' => $searchResult['timing_ms'] ?? 0,
                'diagnostics' => $searchDiagnostics ?? [],
            ]);
            
            // Log search events
            FirestoreAudit::logEvent('SEARCH_COMPLETED', [
                'vertical' => $searchVertical ?? 'unknown',
                'provider' => $searchProviderUsed ?? 'unknown',
                'results_count' => count($searchResult['results'] ?? []),
                'valid_listings' => $searchValidListings,
                'insufficient' => $searchInsufficient,
                'timing_ms' => $searchResult['timing_ms'] ?? 0,
            ], $caseId, $searchRunId);
            
            // Update case with search metadata
            if ($caseId && $searchIntent) {
                FirestoreAudit::updateCaseSearchMeta($caseId, [
                    'vertical' => $searchVertical,
                    'location' => $searchIntent['ubicacion'] ?? '',
                    'property_type' => $searchIntent['tipo_propiedad'] ?? '',
                    'budget' => !empty($searchIntent['presupuesto']) 
                        ? $searchIntent['presupuesto']['raw'] ?? '' 
                        : '',
                ]);
            }
        } catch (\Throwable $e) {
            error_log("FirestoreAudit error: " . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("SearchOrchestrator error: " . $e->getMessage());
        $searchContext = "\n\n🔍 BÚSQUEDA para \"{$searchQuery}\": Error en la búsqueda. Informa al usuario que hubo un problema técnico buscando y sugiere buscar directamente en portalinmobiliario.com, yapo.cl, toctoc.com\n";
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
$systemPrompt = SYSTEM_PROMPT . $ufContext . buildProfileContext($userProfile);

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

// === POST-PROCESS: VALIDATE URLs IN RESPONSE ===
$fabricatedCount = 0;
if ($shouldSearch && !empty($searchValidURLs)) {
    $validation = validateResponseURLs($reply, $searchValidURLs);
    $reply = $validation['response'];
    $fabricatedCount = $validation['fabricated_count'];
    
    if ($fabricatedCount > 0) {
        error_log("⚠️ URLs fabricadas detectadas y corregidas: {$fabricatedCount}");
    }
}

// === PROFILE EXTRACTION (Haiku) ===
$profileUpdate = null;
$profileTimingMs = 0;
try {
    $profileBuilder = new ProfileBuilder(CLAUDE_API_KEY);
    if ($profileBuilder->shouldExtract($message)) {
        $profileStart = microtime(true);
        $profileUpdate = $profileBuilder->extractProfile($message, $reply, $userProfile);
        $profileTimingMs = round((microtime(true) - $profileStart) * 1000);
        if ($profileUpdate) {
            error_log("ProfileBuilder: Profile updated (" . count(array_filter($profileUpdate, fn($v) => $v !== null && $v !== [])) . " fields) in {$profileTimingMs}ms");
        }
    }
} catch (Exception $e) {
    error_log("ProfileBuilder error: " . $e->getMessage());
}

// === TIMING & METADATA ===
$endTime = microtime(true);
$timingTotal = round(($endTime - $startTime) * 1000);
$timingRag = round(($ragEndTime - $ragStartTime) * 1000);
$timingLlm = round(($llmEndTime - $llmStartTime) * 1000);

$inputTokens = $data['usage']['input_tokens'] ?? 0;
$outputTokens = $data['usage']['output_tokens'] ?? 0;

$costEstimate = ($inputTokens * 3 / 1000000) + ($outputTokens * 15 / 1000000);

echo json_encode([
    'response' => $reply,
    'searched' => $shouldSearch,
    'searchQuery' => $shouldSearch ? $searchQuery : null,
    'searchVertical' => $searchVertical,
    'searchProvider' => $searchProviderUsed,
    'ufValue' => $ufData ? $ufData['formatted'] : null,
    'legalResults' => !empty($legalContext),
    'metadata' => [
        'model' => MODEL,
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_estimate' => round($costEstimate, 6),
        'timing_total' => $timingTotal,
        'timing_rag' => $timingRag,
        'timing_llm' => $timingLlm,
        'fabricated_urls_caught' => $fabricatedCount,
        'timing_profile' => $profileTimingMs,
        'search_valid_listings' => $searchValidListings,
        'search_insufficient' => $searchInsufficient,
    ],
    'search_intent' => $searchIntent,
    'profile_update' => $profileUpdate
], JSON_UNESCAPED_UNICODE);
?>
