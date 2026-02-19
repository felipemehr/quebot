<?php
require_once __DIR__ . '/SearchProviderInterface.php';

/**
 * SERP API provider (Google results via serpapi.com).
 * Supports parallel multi-query search via curl_multi.
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

    /**
     * Single query search (existing behavior).
     */
    public function search(string $query, int $maxResults = 10): array {
        if (!$this->isAvailable()) return [];
        $results = $this->searchParallel([$query], $maxResults);
        return $results;
    }

    /**
     * Parallel multi-query search using curl_multi.
     * Runs all queries simultaneously, cutting total time to ~max(single_query_time).
     *
     * @param string[] $queries Array of search queries
     * @param int $maxResults Max results per query
     * @return array Deduplicated merged results
     */
    public function searchParallel(array $queries, int $maxResults = 10): array {
        if (!$this->isAvailable() || empty($queries)) return [];

        $multiHandle = curl_multi_init();
        $handles = [];

        // Create curl handles for all queries
        foreach ($queries as $i => $query) {
            $params = http_build_query([
                'engine' => $this->engine,
                'q' => $query,
                'location' => $this->location,
                'gl' => $this->gl,
                'hl' => $this->hl,
                'num' => min($maxResults, 10),
                'api_key' => $this->apiKey,
            ]);

            $ch = curl_init("https://serpapi.com/search.json?{$params}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'QueBot/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$i] = $ch;
        }

        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0);

        // Collect results
        $allResults = [];
        $seenUrls = [];

        foreach ($handles as $i => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if (!$response || $httpCode !== 200) {
                error_log("SerpAPI parallel query {$i} failed: HTTP {$httpCode}");
                continue;
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['organic_results'])) {
                error_log("SerpAPI parallel query {$i}: invalid response");
                continue;
            }

            foreach ($data['organic_results'] as $j => $item) {
                if ($j >= $maxResults) break;
                $url = rtrim($item['link'] ?? '', '/');
                if (isset($seenUrls[$url])) continue;
                $seenUrls[$url] = true;

                $allResults[] = [
                    'title' => $item['title'] ?? 'Sin título',
                    'url' => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'position' => $item['position'] ?? ($j + 1),
                    'source_provider' => 'serpapi',
                    'displayed_link' => $item['displayed_link'] ?? '',
                    'date' => $item['date'] ?? null,
                ];
            }
        }

        curl_multi_close($multiHandle);

        return $allResults;
    }

    /**
     * Scrape page content for enrichment.
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
