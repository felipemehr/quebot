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
    
    // Strategy: Extract titles/URLs and snippets separately, then pair by index
    
    // Step 1: Extract all title links (result__a)
    $titles = [];
    if (preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $titleMatches, PREG_SET_ORDER)) {
        foreach ($titleMatches as $match) {
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

/**
 * Scrape a listing page to extract individual property details and links
 */
function scrapeListingPage($url, $maxTextLength = 6000) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (empty($html) || $httpCode !== 200) return null;
    
    $parsed = parse_url($url);
    $baseHost = $parsed['scheme'] . '://' . $parsed['host'];
    
    // Extract internal links with text (property links)
    $links = [];
    if (preg_match_all('/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $linkMatches, PREG_SET_ORDER)) {
        foreach ($linkMatches as $match) {
            $href = trim($match[1]);
            $text = trim(strip_tags($match[2]));
            if (empty($text) || mb_strlen($text) < 5) continue;
            if (strpos($href, '#') === 0 || strpos($href, 'javascript') === 0 || strpos($href, 'mailto') === 0) continue;
            
            // Make absolute
            if (strpos($href, 'http') !== 0) {
                if (strpos($href, '/') === 0) {
                    $href = $baseHost . $href;
                } else {
                    $href = $baseHost . '/' . $href;
                }
            }
            
            // Only same domain, skip navigation links
            if (strpos($href, $parsed['host']) !== false && mb_strlen($text) > 5) {
                $skipTexts = ['inicio', 'home', 'contacto', 'login', 'registro', 'ayuda', 'acerca',
                              'términos', 'privacidad', 'ver más', 'ver todos', 'siguiente', 'anterior',
                              'cookie', 'suscrib', 'newsletter', 'facebook', 'twitter', 'instagram'];
                $skip = false;
                foreach ($skipTexts as $skipText) {
                    if (mb_stripos($text, $skipText) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $links[] = $href . ' | ' . mb_substr($text, 0, 120);
                }
            }
        }
    }
    
    // Strip non-content elements
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);
    $html = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $html);
    $html = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    
    // Convert to text
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);
    
    if (mb_strlen($text) > $maxTextLength) {
        $text = mb_substr($text, 0, $maxTextLength) . '...';
    }
    
    $links = array_values(array_unique($links));
    $linksText = implode("\n", array_slice($links, 0, 30));
    
    return [
        'url' => $url,
        'content' => $text,
        'links' => $linksText
    ];
}
