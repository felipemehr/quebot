<?php
/**
 * DuckDuckGo Search with timeout handling
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
    
    // Pattern: class="result__a" comes before href
    if (preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $i => $match) {
            if ($i >= $maxResults) break;
            
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
            
            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => ''
            ];
        }
    }
    
    // Try to get snippets
    if (preg_match_all('/<a[^>]*class="result__snippet"[^>]*>([^<]+)/i', $html, $snippetMatches)) {
        foreach ($snippetMatches[1] as $i => $snippet) {
            if (isset($results[$i])) {
                $results[$i]['snippet'] = html_entity_decode(strip_tags($snippet));
            }
        }
    }
    
    return ['results' => $results];
}
