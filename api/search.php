<?php
/**
 * QueBot - Web Search Module
 * Búsqueda web usando DuckDuckGo
 */

function searchWeb($query, $maxResults = 5) {
    $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: es-CL,es;q=0.9,en;q=0.8'
        ]
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'results' => []];
    }
    
    $results = [];
    
    // Parse DuckDuckGo results - match result links
    preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>([^<]*(?:<[^>]+>[^<]*)*)</i', $html, $titleMatches, PREG_SET_ORDER);
    
    // Match snippets
    preg_match_all('/class="result__snippet"[^>]*>([^<]*(?:<[^>]+>[^<]*)*)</i', $html, $snippetMatches);
    
    foreach (array_slice($titleMatches, 0, $maxResults) as $i => $match) {
        $rawUrl = $match[1];
        $title = strip_tags($match[2]);
        $snippet = isset($snippetMatches[1][$i]) ? strip_tags($snippetMatches[1][$i]) : '';
        
        // Extract real URL from DuckDuckGo redirect
        $realUrl = extractRealUrl($rawUrl);
        
        if (!empty($title) && !empty($realUrl)) {
            $results[] = [
                'title' => html_entity_decode(trim($title), ENT_QUOTES, 'UTF-8'),
                'url' => $realUrl,
                'snippet' => html_entity_decode(trim($snippet), ENT_QUOTES, 'UTF-8')
            ];
        }
    }
    
    return ['results' => $results, 'query' => $query];
}

/**
 * Extract real URL from DuckDuckGo redirect URL
 */
function extractRealUrl($ddgUrl) {
    // DuckDuckGo URLs: //duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com&rut=...
    if (preg_match('/uddg=([^&]+)/', $ddgUrl, $match)) {
        return urldecode($match[1]);
    }
    
    // Clean up URL
    $url = ltrim($ddgUrl, '/');
    if (strpos($url, 'http') !== 0) {
        $url = 'https://' . $url;
    }
    
    return $url;
}

/**
 * Detect if message needs web search
 */
function needsWebSearch($message) {
    $patterns = [
        '/\b(busca|buscar|búsqueda|search|googlea|investiga)\b/i',
        '/\b(qué es|quién es|cuándo|dónde|cómo)\b.*\?/i',
        '/\b(precio|costo|valor|cotización)\b.*\b(actual|hoy|202)\b/i',
        '/\b(noticias|news|últimas|reciente)\b/i',
        '/\b(parcelas|propiedades|arriendos|ventas|casas|departamentos)\b.*\b(en|de)\b/i',
        '/\b(clima|tiempo|weather)\b.*\b(en|hoy)\b/i',
        '/\b(dólar|uf|euro|bitcoin)\b/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    return false;
}

/**
 * Extract search query from message
 */
function extractSearchQuery($message) {
    $cleaned = preg_replace('/^(busca|buscar|búsqueda de|search|googlea|investiga)\s*/i', '', $message);
    $cleaned = preg_replace('/^(qué es|quién es|dime sobre)\s*/i', '', $cleaned);
    return trim($cleaned, '?!. ') ?: $message;
}

/**
 * Format search results for Claude
 */
function formatSearchResultsForPrompt($searchData) {
    if (empty($searchData['results'])) {
        return "No encontré resultados para: {$searchData['query']}";
    }
    
    $text = "RESULTADOS DE BÚSQUEDA WEB PARA: \"{$searchData['query']}\"\n\n";
    
    foreach ($searchData['results'] as $i => $r) {
        $n = $i + 1;
        $text .= "{$n}. {$r['title']}\n";
        $text .= "   Link: {$r['url']}\n";
        if ($r['snippet']) {
            $text .= "   Info: {$r['snippet']}\n";
        }
        $text .= "\n";
    }
    
    return $text;
}
