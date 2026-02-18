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
    
    // Parse each result block individually for better accuracy
    // DuckDuckGo wraps each result in a div.result
    if (preg_match_all('/<div[^>]*class="[^"]*result[^"]*results_links[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $blocks)) {
        foreach ($blocks[0] as $i => $block) {
            if ($i >= $maxResults) break;
            
            // Extract URL and title
            if (!preg_match('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $block, $linkMatch)) {
                continue;
            }
            
            $rawUrl = html_entity_decode($linkMatch[1]);
            $title = html_entity_decode(strip_tags($linkMatch[2]));
            
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
            
            // Extract snippet - try multiple patterns
            $snippet = '';
            if (preg_match('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/is', $block, $snippetMatch)) {
                $snippet = html_entity_decode(strip_tags($snippetMatch[1]));
            } elseif (preg_match('/<div[^>]*class="result__snippet"[^>]*>(.*?)<\/div>/is', $block, $snippetMatch)) {
                $snippet = html_entity_decode(strip_tags($snippetMatch[1]));
            }
            
            // Classify URL type: specific page vs listing/search page
            $urlType = classifyUrl($url);
            
            $results[] = [
                'title' => trim($title),
                'url' => $url,
                'snippet' => trim($snippet),
                'type' => $urlType
            ];
        }
    }
    
    // Fallback: simpler regex if block parsing found nothing
    if (empty($results)) {
        if (preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $i => $match) {
                if ($i >= $maxResults) break;
                
                $rawUrl = html_entity_decode($match[1]);
                $title = html_entity_decode(strip_tags($match[2]));
                
                $url = $rawUrl;
                if (preg_match('/uddg=([^&]+)/', $rawUrl, $urlMatch)) {
                    $url = urldecode($urlMatch[1]);
                }
                
                if (strpos($url, 'duckduckgo.com') !== false) {
                    continue;
                }
                
                if (strpos($url, 'http') !== 0) {
                    $url = 'https:' . $url;
                }
                
                $results[] = [
                    'title' => trim($title),
                    'url' => $url,
                    'snippet' => '',
                    'type' => classifyUrl($url)
                ];
            }
        }
        
        // Try to add snippets
        if (preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/is', $html, $snippetMatches)) {
            foreach ($snippetMatches[1] as $i => $snippet) {
                if (isset($results[$i])) {
                    $results[$i]['snippet'] = trim(html_entity_decode(strip_tags($snippet)));
                }
            }
        }
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
        '/\/venta\/[a-z]+\/[a-z]+$/i',  // portalinmobiliario.com/venta/terreno/region
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
        '/\/[0-9]{5,}/',        // URLs with long numeric IDs
        '/\-[0-9]{5,}/',        // property-12345
        '/\/detalle\//',
        '/\/propiedad\//',
        '/\/ficha\//',
        '/\/inmueble\//',
        '/\/aviso\//',
        '/MLC\-[0-9]+/',         // MercadoLibre
        '/\/[A-Z]{2,3}[0-9]{5,}/'  // Coded IDs like PIR12345
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
