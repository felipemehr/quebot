<?php
// search.php - Web search via DuckDuckGo HTML + UF value from SII.cl

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
    
    // Month names in Spanish for section IDs
    $monthNames = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    ];
    
    $monthId = $monthNames[$month];
    
    // Extract the month section
    $pattern = "/id='mes_{$monthId}'>(.*?)<\/div>\s*<\/div>/s";
    if (!preg_match($pattern, $html, $monthMatch)) return null;
    
    $monthHtml = $monthMatch[1];
    
    // Extract all day-value pairs
    $values = [];
    preg_match_all('/<strong>(\d+)<\/strong><\/th>\s*<td[^>]*>([^<]*)<\/td>/s', $monthHtml, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $d = (int)$m[1];
        $v = trim($m[2]);
        if ($v !== '') {
            $values[$d] = $v;
        }
    }
    
    // Try today, then go backwards to find most recent value
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
    
    // If current month has no values yet, try previous month
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

/**
 * Clean a search query by expanding abbreviations and removing bot instructions
 */
function cleanPropertyQuery(string $query): string {
    // Expand common abbreviations
    $abbreviations = [
        '/\b1d\b/i' => '1 dormitorio',
        '/\b2d\b/i' => '2 dormitorios',
        '/\b3d\b/i' => '3 dormitorios',
        '/\b4d\b/i' => '4 dormitorios',
        '/\b5d\b/i' => '5 dormitorios',
        '/\b1b\b/i' => '1 baño',
        '/\b2b\b/i' => '2 baños',
        '/\b3b\b/i' => '3 baños',
        '/\b4b\b/i' => '4 baños',
    ];
    $query = preg_replace(array_keys($abbreviations), array_values($abbreviations), $query);
    
    // Remove bot instructions that pollute search
    $instructionPatterns = [
        '/datos?\s*reales?/i',
        '/tabla\s+link/i',
        '/m[ií]nimo\s+\d+\s+propiedades?/i',
        '/caracter[ií]sticas?\s*(y\s+)?rating/i',
        '/val\s+m2\s+construido/i',
        '/m2\s+terreno/i',
        '/m2\s+casa/i',
        '/\+[\-\/]\s*\d+\s*uf/i',
        '/\brating\b/i',
        '/\benlace\b/i',
        '/\blink\b/i',
        '/\bcon\s+links?\b/i',
    ];
    $query = preg_replace($instructionPatterns, '', $query);
    
    // Clean up extra spaces and commas
    $query = preg_replace('/,\s*,/', ',', $query);
    $query = preg_replace('/\s+/', ' ', $query);
    $query = trim($query, ' ,.');
    
    return $query;
}

/**
 * Detect if a query is about property/real estate search
 */
function isPropertySearch(string $messageLower): bool {
    $propertyTerms = ['casa', 'depto', 'departamento', 'parcela', 'terreno', 
                      'sitio', 'propiedad', 'lote', 'campo', 'arriendo',
                      'inmueble', 'condominio', 'cabaña'];
    $actionTerms = ['busca', 'encuentra', 'venta', 'comprar', 'precio', 'uf '];
    
    $hasProperty = false;
    $hasAction = false;
    
    foreach ($propertyTerms as $term) {
        if (strpos($messageLower, $term) !== false) {
            $hasProperty = true;
            break;
        }
    }
    foreach ($actionTerms as $term) {
        if (strpos($messageLower, $term) !== false) {
            $hasAction = true;
            break;
        }
    }
    
    return $hasProperty && $hasAction;
}

/**
 * Generate multiple search queries for property searches
 */
function generatePropertyQueries(string $cleanedQuery): array {
    $queries = [];
    
    // Query 1: Direct search
    $queries[] = $cleanedQuery . ' venta';
    
    // Query 2: Portal-specific (portalinmobiliario)
    $queries[] = $cleanedQuery . ' portalinmobiliario.com';
    
    // Query 3: Alternative portals
    $queries[] = $cleanedQuery . ' toctoc.com yapo.cl';
    
    return $queries;
}

function classifyUrl($url) {
    $url_lower = strtolower($url);
    
    // Specific property patterns
    $specificPatterns = [
        '/\/(propiedad|property|listing|ficha|detalle|aviso)\//',
        '/\/[A-Z0-9]{5,}\/?$/',
        '/id=\d+/',
        '/\d{6,}/',
        '/-(casa|depto|departamento|parcela|terreno|sitio)-.*-\d+/',
    ];
    
    foreach ($specificPatterns as $pattern) {
        if (preg_match($pattern, $url_lower)) {
            return 'specific';
        }
    }
    
    // Listing/portal patterns
    $listingPatterns = [
        '/\/(venta|arriendo|comprar|buscar|search|results|listings)\//',
        '/\/(parcelas|casas|departamentos|terrenos|propiedades)\//',
        '/category|region|comuna|sector/',
    ];
    
    foreach ($listingPatterns as $pattern) {
        if (preg_match($pattern, $url_lower)) {
            return 'listing';
        }
    }
    
    return 'unknown';
}

function searchDuckDuckGo($query, $numResults = 8) {
    $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
        ]
    ]);
    
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return [];
    
    $results = [];
    
    // Extract titles and URLs
    preg_match_all('/class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $titleMatches, PREG_SET_ORDER);
    
    // Extract snippets  
    preg_match_all('/class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snippetMatches, PREG_SET_ORDER);
    
    $count = min(count($titleMatches), $numResults);
    
    for ($i = 0; $i < $count; $i++) {
        $rawUrl = $titleMatches[$i][1];
        $title = strip_tags($titleMatches[$i][2]);
        
        // DDG redirects - extract actual URL
        if (preg_match('/uddg=([^&]+)/', $rawUrl, $urlMatch)) {
            $rawUrl = urldecode($urlMatch[1]);
        }
        
        $snippet = '';
        if (isset($snippetMatches[$i])) {
            $snippet = strip_tags($snippetMatches[$i][1]);
            $snippet = html_entity_decode($snippet, ENT_QUOTES, 'UTF-8');
            $snippet = preg_replace('/\s+/', ' ', trim($snippet));
        }
        
        $type = classifyUrl($rawUrl);
        
        $results[] = [
            'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'url' => $rawUrl,
            'snippet' => $snippet,
            'type' => $type
        ];
    }
    
    return $results;
}

/**
 * Merge multiple search result sets, deduplicating by URL
 */
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

function scrapePageContent($url, $maxLength = 3000) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
            'follow_location' => true,
            'max_redirects' => 3
        ]
    ]);
    
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return null;
    
    // Remove scripts, styles, nav, header, footer
    $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
    $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
    $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
    $html = preg_replace('/<header[^>]*>.*?<\/header>/si', '', $html);
    $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);
    
    // Get text content
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength) . '...';
    }
    
    return $text;
}
?>
