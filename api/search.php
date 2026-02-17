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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 10
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'results' => []];
    }
    
    $results = [];
    
    // Parse DuckDuckGo results
    preg_match_all('/<a class="result__a" href="([^"]+)"[^>]*>(.+?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    preg_match_all('/<a class="result__snippet"[^>]*>(.+?)<\/a>/s', $html, $snippetMatches);
    
    foreach (array_slice($matches, 0, $maxResults) as $i => $match) {
        $rawUrl = $match[1];
        $title = strip_tags($match[2]);
        $snippet = isset($snippetMatches[1][$i]) ? strip_tags($snippetMatches[1][$i]) : '';
        
        // Extract real URL from DuckDuckGo redirect
        $realUrl = extractRealUrl($rawUrl);
        
        $results[] = [
            'title' => html_entity_decode(trim($title), ENT_QUOTES, 'UTF-8'),
            'url' => $realUrl,
            'snippet' => html_entity_decode(trim($snippet), ENT_QUOTES, 'UTF-8')
        ];
    }
    
    return ['results' => $results, 'query' => $query];
}

/**
 * Extract real URL from DuckDuckGo redirect URL
 */
function extractRealUrl($ddgUrl) {
    // DuckDuckGo URLs look like: //duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com&rut=...
    if (preg_match('/uddg=([^&]+)/', $ddgUrl, $match)) {
        return urldecode($match[1]);
    }
    
    // If no redirect, clean up the URL
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
    $searchPatterns = [
        '/\b(busca|buscar|búsqueda|search|googlea|investiga)\b/i',
        '/\b(qué es|quién es|cuándo fue|dónde queda|cómo se llama)\b.*\?/i',
        '/\b(precio|costo|valor|cotización)\b.*\b(actual|hoy|2024|2025|2026)\b/i',
        '/\b(noticias|news|últimas|reciente)\b/i',
        '/\b(parcelas|propiedades|arriendos|ventas|departamentos|casas)\b.*\b(en|de)\b/i',
        '/\b(clima|tiempo|weather)\b.*\b(en|de|hoy)\b/i',
        '/\b(dólar|uf|euro|bitcoin)\b.*\b(hoy|actual|precio)\b/i'
    ];
    
    foreach ($searchPatterns as $pattern) {
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
    $cleaned = preg_replace('/^(busca|buscar|búsqueda de|search for|googlea|investiga)\s*/i', '', $message);
    $cleaned = preg_replace('/^(qué es|quién es|cuéntame sobre|dime sobre)\s*/i', '', $cleaned);
    $cleaned = trim($cleaned, '?!. ');
    
    return $cleaned ?: $message;
}

/**
 * Format search results for Claude
 */
function formatSearchResultsForPrompt($searchData) {
    if (empty($searchData['results'])) {
        return "No encontré resultados web para: {$searchData['query']}";
    }
    
    $formatted = "RESULTADOS DE BÚSQUEDA WEB PARA: \"{$searchData['query']}\"\n\n";
    
    foreach ($searchData['results'] as $i => $result) {
        $num = $i + 1;
        $formatted .= "{$num}. {$result['title']}\n";
        $formatted .= "   URL: {$result['url']}\n";
        if ($result['snippet']) {
            $formatted .= "   Resumen: {$result['snippet']}\n";
        }
        $formatted .= "\n";
    }
    
    return $formatted;
}
