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
        CURLOPT_TIMEOUT => 15
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'results' => []];
    }
    
    $results = [];
    
    // Pattern: <a rel="nofollow" class="result__a" href="URL">TITLE</a>
    preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>([^<]+)</i', $html, $matches, PREG_SET_ORDER);
    
    // Get snippets separately
    preg_match_all('/class="result__snippet"[^>]*>([^<]+)</i', $html, $snippetMatches);
    
    foreach (array_slice($matches, 0, $maxResults) as $i => $match) {
        $rawUrl = html_entity_decode($match[1]);
        $title = trim($match[2]);
        $snippet = isset($snippetMatches[1][$i]) ? trim($snippetMatches[1][$i]) : '';
        
        // Extract real URL from DuckDuckGo redirect
        $realUrl = extractRealUrl($rawUrl);
        
        if (!empty($title) && !empty($realUrl)) {
            $results[] = [
                'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'url' => $realUrl,
                'snippet' => html_entity_decode($snippet, ENT_QUOTES, 'UTF-8')
            ];
        }
    }
    
    return ['results' => $results, 'query' => $query];
}

function extractRealUrl($ddgUrl) {
    // Extract from: //duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com&rut=...
    if (preg_match('/uddg=([^&]+)/', $ddgUrl, $match)) {
        return urldecode($match[1]);
    }
    return ltrim($ddgUrl, '/');
}

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
    
    foreach ($patterns as $p) {
        if (preg_match($p, $message)) return true;
    }
    return false;
}

function extractSearchQuery($message) {
    $cleaned = preg_replace('/^(busca|buscar|búsqueda de|search|googlea|investiga)\s*/i', '', $message);
    $cleaned = preg_replace('/^(qué es|quién es|dime sobre)\s*/i', '', $cleaned);
    return trim($cleaned, '?!. ') ?: $message;
}

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
