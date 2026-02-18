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
    
    // Table structure: each row has 3 pairs of (day, value)
    // <th><strong>1</strong></th><td>39.703,50</td>
    // <th><strong>11</strong></th><td>39.694,31</td>
    // <th><strong>21</strong></th><td>39.750,94</td>
    
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
            // Convert "39.733,94" to float 39733.94
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