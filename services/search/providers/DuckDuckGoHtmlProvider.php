<?php
require_once __DIR__ . '/SearchProviderInterface.php';

/**
 * DuckDuckGo HTML scraping provider.
 * Encapsulates the existing DDG search logic with normalized output.
 */
class DuckDuckGoHtmlProvider implements SearchProviderInterface {
    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    public function getName(): string {
        return 'duckduckgo';
    }

    public function search(string $query, int $maxResults = 10): array {
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: {$this->userAgent}\r\n"
            ]
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if (!$html) return [];

        $results = [];

        // Extract titles and URLs
        preg_match_all('/class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $titleMatches, PREG_SET_ORDER);

        // Extract snippets
        preg_match_all('/class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snippetMatches, PREG_SET_ORDER);

        $count = min(count($titleMatches), $maxResults);

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

            $results[] = [
                'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'url' => $rawUrl,
                'snippet' => $snippet,
                'position' => $i + 1,
                'source_provider' => 'duckduckgo',
            ];
        }

        return $results;
    }

    public function scrapeContent(string $url, int $maxLength = 3000): ?string {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: {$this->userAgent}\r\n",
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

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '...';
        }

        return strlen($text) > 50 ? $text : null;
    }
}
