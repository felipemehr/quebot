<?php
require_once __DIR__ . '/SearchProviderInterface.php';

/**
 * SERP API provider (Google results via serpapi.com).
 * Requires env var SERPAPI_KEY.
 *
 * If SERPAPI_KEY is not set, this provider will return empty results
 * (the Orchestrator will fall back to DuckDuckGo).
 */
class SerpApiProvider implements SearchProviderInterface {
    private string $apiKey;
    private string $engine;
    private string $location;
    private string $gl;
    private string $hl;

    public function __construct(
        ?string $apiKey = null,
        string $engine = 'google',
        string $location = 'Chile',
        string $gl = 'cl',
        string $hl = 'es'
    ) {
        $this->apiKey = $apiKey ?: (getenv('SERPAPI_KEY') ?: '');
        $this->engine = getenv('SERPAPI_ENGINE') ?: $engine;
        $this->location = getenv('SERPAPI_LOCATION') ?: $location;
        $this->gl = $gl;
        $this->hl = $hl;
    }

    public function getName(): string {
        return 'serpapi';
    }

    public function isAvailable(): bool {
        return !empty($this->apiKey);
    }

    public function search(string $query, int $maxResults = 10): array {
        if (!$this->isAvailable()) return [];

        $params = http_build_query([
            'engine' => $this->engine,
            'q' => $query,
            'location' => $this->location,
            'gl' => $this->gl,
            'hl' => $this->hl,
            'num' => min($maxResults, 10),
            'api_key' => $this->apiKey,
        ]);

        $url = "https://serpapi.com/search.json?{$params}";

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 12,
                'header' => "User-Agent: QueBot/1.0\r\n",
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if (!$response) {
            error_log("SerpAPI request failed for query: {$query}");
            return [];
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['organic_results'])) {
            error_log("SerpAPI invalid response for query: {$query}");
            return [];
        }

        $results = [];
        foreach ($data['organic_results'] as $i => $item) {
            if ($i >= $maxResults) break;

            $results[] = [
                'title' => $item['title'] ?? 'Sin título',
                'url' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'position' => $item['position'] ?? ($i + 1),
                'source_provider' => 'serpapi',
                'displayed_link' => $item['displayed_link'] ?? '',
                'date' => $item['date'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Scrape page content. SerpAPI doesn't provide page content,
     * so we use basic HTTP scraping (same as DuckDuckGo provider).
     */
    public function scrapeContent(string $url, int $maxLength = 3000): ?string {
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
