<?php
/**
 * search.php — Search library + JSON API endpoint.
 *
 * When accessed via HTTP GET with ?q= parameter, returns JSON results via SearchOrchestrator.
 * When included via require_once, provides backward-compatible functions.
 *
 * API Usage:
 *   GET /api/search.php?q=parcelas+melipeuco&vertical=real_estate
 *   GET /api/search.php?q=ley+19628&vertical=legal
 */

// --- Load Orchestrator ---
require_once __DIR__ . '/../services/search/SearchOrchestrator.php';

// ================================================================
// BACKWARD-COMPATIBLE FUNCTIONS (used by chat.php legacy flow)
// ================================================================

function getUFValue() {
    $year = date('Y');
    $month = (int)date('m');
    $day = (int)date('d');
    
    $url = "https://www.sii.cl/valores_y_fechas/uf/uf{$year}.htm";
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "User-Agent: Mozilla/5.0\r\n"
        ]
    ]);
    
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return null;
    
    $monthNames = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    ];
    
    $monthId = $monthNames[$month];
    
    $pattern = "/id='mes_{$monthId}'>(.*?)<\/div>\s*<\/div>/s";
    if (!preg_match($pattern, $html, $monthMatch)) return null;
    
    $monthHtml = $monthMatch[1];
    
    $values = [];
    preg_match_all('/<strong>(\d+)<\/strong><\/th>\s*<td[^>]*>([^<]*)<\/td>/s', $monthHtml, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $d = (int)$m[1];
        $v = trim($m[2]);
        if ($v !== '') {
            $values[$d] = $v;
        }
    }
    
    for ($d = $day; $d >= 1; $d--) {
        if (isset($values[$d])) {
            $numStr = str_replace('.', '', $values[$d]);
            $numStr = str_replace(',', '.', $numStr);
            return [
                'value' => (float)$numStr,
                'formatted' => $values[$d],
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $d),
                'source' => 'SII.cl'
            ];
        }
    }
    
    if ($month > 1) {
        $prevMonth = $monthNames[$month - 1];
        $pattern2 = "/id='mes_{$prevMonth}'>(.*?)<\/div>\s*<\/div>/s";
        if (preg_match($pattern2, $html, $prevMatch)) {
            $prevHtml = $prevMatch[1];
            preg_match_all('/<strong>(\d+)<\/strong><\/th>\s*<td[^>]*>([^<]*)<\/td>/s', $prevHtml, $matches2, PREG_SET_ORDER);
            $prevValues = [];
            foreach ($matches2 as $m) {
                $d = (int)$m[1];
                $v = trim($m[2]);
                if ($v !== '') $prevValues[$d] = $v;
            }
            if (!empty($prevValues)) {
                $lastDay = max(array_keys($prevValues));
                $numStr = str_replace('.', '', $prevValues[$lastDay]);
                $numStr = str_replace(',', '.', $numStr);
                return [
                    'value' => (float)$numStr,
                    'formatted' => $prevValues[$lastDay],
                    'date' => sprintf('%04d-%02d-%02d', $year, $month - 1, $lastDay),
                    'source' => 'SII.cl'
                ];
            }
        }
    }
    
    return null;
}

/** @deprecated Use Validator::classifyUrl() */
function classifyUrl($url) {
    return Validator::classifyUrl($url);
}

/** @deprecated Use QueryBuilder::build() */
function cleanPropertyQuery(string $query): string {
    $result = QueryBuilder::build($query, 'real_estate');
    return $result['cleaned_query'];
}

/** @deprecated Use DomainPolicy::detectVertical() */
function isPropertySearch(string $messageLower): bool {
    return DomainPolicy::detectVertical($messageLower) === 'real_estate';
}

/** @deprecated Use QueryBuilder::build() */
function generatePropertyQueries(string $cleanedQuery): array {
    $result = QueryBuilder::build($cleanedQuery, 'real_estate');
    return $result['queries'];
}

/** @deprecated Use DuckDuckGoHtmlProvider->search() */
function searchDuckDuckGo($query, $numResults = 8) {
    $provider = new DuckDuckGoHtmlProvider();
    $results = $provider->search($query, $numResults);
    return array_map(function($r) {
        return [
            'title' => $r['title'],
            'url' => $r['url'],
            'snippet' => $r['snippet'],
            'type' => Validator::classifyUrl($r['url']),
        ];
    }, $results);
}

/** @deprecated Use mergeSearchResults in Orchestrator */
function mergeSearchResults(array ...$resultSets): array {
    $merged = [];
    $seenUrls = [];
    foreach ($resultSets as $results) {
        foreach ($results as $result) {
            $normalizedUrl = rtrim($result['url'], '/');
            if (!in_array($normalizedUrl, $seenUrls)) {
                $merged[] = $result;
                $seenUrls[] = $normalizedUrl;
            }
        }
    }
    return $merged;
}

/** @deprecated Use DuckDuckGoHtmlProvider->scrapeContent() */
function scrapePageContent($url, $maxLength = 3000) {
    $provider = new DuckDuckGoHtmlProvider();
    return $provider->scrapeContent($url, $maxLength);
}


// ================================================================
// JSON API ENDPOINT (only when accessed directly via HTTP)
// ================================================================

if (php_sapi_name() !== 'cli' && isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // CORS
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
        $allowed = $config['ALLOWED_ORIGINS'] ?? [];
        if (in_array($origin, $allowed)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
    }
    
    $query = trim($_GET['q']);
    $vertical = $_GET['vertical'] ?? 'auto';
    
    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing q parameter'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $validVerticals = ['auto', 'real_estate', 'legal', 'news', 'retail', 'general'];
    if (!in_array($vertical, $validVerticals)) {
        $vertical = 'auto';
    }
    
    try {
        $apiKey = getenv('CLAUDE_API_KEY') ?: null;
        $orchestrator = new SearchOrchestrator($apiKey, false);
        
        $result = $orchestrator->search($query, $vertical, [
            'max_results' => 10,
            'scrape_pages' => 5,
            'scrape_max_length' => 5000,
        ]);
        
        // Remove context_for_llm from API output (internal use only)
        unset($result['context_for_llm']);
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Search failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
