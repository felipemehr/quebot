<?php
/**
 * QueBot - Web Search Module
 * Búsqueda web usando DuckDuckGo
 */

function searchWeb($query, $maxResults = 5) {
    // DuckDuckGo HTML search (no API key needed)
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
    
    // Parse results
    $results = [];
    
    // Match result blocks
    preg_match_all('/<a class="result__a" href="([^"]+)"[^>]*>([^<]+)<\/a>/', $html, $titles, PREG_SET_ORDER);
    preg_match_all('/<a class="result__snippet"[^>]*>([^<]+)</', $html, $snippets);
    
    for ($i = 0; $i < min(count($titles), $maxResults); $i++) {
        $results[] = [
            'title' => html_entity_decode(strip_tags($titles[$i][2] ?? ''), ENT_QUOTES, 'UTF-8'),
            'url' => $titles[$i][1] ?? '',
            'snippet' => html_entity_decode(strip_tags($snippets[1][$i] ?? ''), ENT_QUOTES, 'UTF-8')
        ];
    }
    
    // Fallback: try alternative parsing
    if (empty($results)) {
        preg_match_all('/<div class="result[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>([^<]+)<.*?<\/div>/s', $html, $matches, PREG_SET_ORDER);
        foreach (array_slice($matches, 0, $maxResults) as $match) {
            $results[] = [
                'title' => html_entity_decode(strip_tags($match[2] ?? ''), ENT_QUOTES, 'UTF-8'),
                'url' => $match[1] ?? '',
                'snippet' => ''
            ];
        }
    }
    
    return ['results' => $results, 'query' => $query];
}

/**
 * Detect if message needs web search
 */
function needsWebSearch($message) {
    $searchPatterns = [
        '/\b(busca|buscar|búsqueda|search|googlea|investiga)\b/i',
        '/\b(qué es|quién es|cuándo|dónde|cómo se llama)\b.*\?/i',
        '/\b(precio|costo|valor|cotización)\b.*\b(actual|hoy|2024|2025|2026)\b/i',
        '/\b(noticias|news|últimas|reciente)\b/i',
        '/\b(parcelas|propiedades|arriendos|ventas)\b.*\b(en|de)\b/i',
        '/\b(clima|tiempo|weather)\b.*\b(en|de|hoy)\b/i'
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
    // Remove common prefixes
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
    
    $formatted = "**Resultados de búsqueda web para: \"{$searchData['query']}\"**\n\n";
    
    foreach ($searchData['results'] as $i => $result) {
        $num = $i + 1;
        $formatted .= "{$num}. **{$result['title']}**\n";
        if ($result['snippet']) {
            $formatted .= "   {$result['snippet']}\n";
        }
        $formatted .= "   URL: {$result['url']}\n\n";
    }
    
    return $formatted;
}
