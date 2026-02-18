<?php
/**
 * DuckDuckGo Search with enhanced snippet extraction
 */

function performWebSearch($query, $maxResults = 10) {
    $searchUrl = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || empty($html)) {
        return ['results' => [], 'error' => $error ?: 'Empty response'];
    }
    
    $results = [];
    
    // ============================================
    // Strategy: Extract titles/URLs and snippets separately, then pair by index
    // The block-based approach misses snippets because DuckDuckGo's HTML
    // has the snippet OUTSIDE the inner div nesting that block regex captures
    // ============================================
    
    // Step 1: Extract all title links (result__a)
    $titles = [];
    if (preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $titleMatches, PREG_SET_ORDER)) {
        foreach ($titleMatches as $match) {
            $rawUrl = html_entity_decode($match[1]);
            $title = html_entity_decode(strip_tags($match[2]));
            
            // Extract actual URL from DuckDuckGo redirect
            $url = $rawUrl;
            if (preg_match('/uddg=([^&]+)/', $rawUrl, $urlMatch)) {
                $url = urldecode($urlMatch[1]);
            }
            
            // Skip DuckDuckGo internal links
            if (strpos($url, 'duckduckgo.com') !== false) {
                continue;
            }
            
            // Ensure https
            if (strpos($url, 'http') !== 0) {
                $url = 'https:' . $url;
            }
            
            $titles[] = [
                'title' => trim($title),
                'url' => $url
            ];
        }
    }
    
    // Step 2: Extract all snippets (result__snippet)
    $snippets = [];
    if (preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/is', $html, $snippetMatches)) {
        foreach ($snippetMatches[1] as $snippet) {
            $snippets[] = trim(html_entity_decode(strip_tags($snippet)));
        }
    }
    // Also try div-based snippets as fallback
    if (empty($snippets)) {
        if (preg_match_all('/<div[^>]*class="result__snippet"[^>]*>(.*?)<\/div>/is', $html, $snippetMatches)) {
            foreach ($snippetMatches[1] as $snippet) {
                $snippets[] = trim(html_entity_decode(strip_tags($snippet)));
            }
        }
    }
    
    // Step 3: Pair titles with snippets by index
    foreach ($titles as $i => $titleData) {
        if ($i >= $maxResults) break;
        
        $snippet = isset($snippets[$i]) ? $snippets[$i] : '';
        $urlType = classifyUrl($titleData['url']);
        
        $results[] = [
            'title' => $titleData['title'],
            'url' => $titleData['url'],
            'snippet' => $snippet,
            'type' => $urlType
        ];
    }
    
    return ['results' => $results];
}

/**
 * Classify URL as 'specific' (individual page) or 'listing' (search/category page)
 */
function classifyUrl($url) {
    // Patterns that indicate a LISTING/SEARCH page (not a specific item)
    $listingPatterns = [
        '/\/buscar\//',
        '/\/search\//',
        '/\/venta\/[a-z]+\/[a-z]+$/i',
        '/\/arriendo\/[a-z]+\/[a-z]+$/i',
        '/\/categoria\//',
        '/\/tag\//',
        '/[?&]q=/',
        '/[?&]search=/',
        '/[?&]query=/',
        '/\/results\//',
        '/\/listings\/?$/'
    ];
    
    // Patterns that indicate a SPECIFIC page (individual property/item)
    $specificPatterns = [
        '/\/[0-9]{5,}/',
        '/\-[0-9]{5,}/',
        '/\/detalle\//',
        '/\/propiedad\//',
        '/\/ficha\//',
        '/\/inmueble\//',
        '/\/aviso\//',
        '/MLC\-[0-9]+/',
        '/\/[A-Z]{2,3}[0-9]{5,}/'
    ];
    
    foreach ($specificPatterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return 'specific';
        }
    }
    
    foreach ($listingPatterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return 'listing';
        }
    }
    
    return 'unknown';
}
