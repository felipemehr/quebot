<?php
/**
 * Search Orchestrator v1.
 * 
 * Pipeline: QueryBuilder → Cache check → Provider search → Validate → Scrape → Rank → (LLM Rerank) → Cache store → Return
 *
 * Returns normalized results with extracted data, scores, and a pre-built
 * context string for the LLM.
 */

require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/DomainPolicy.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/HeuristicRanker.php';
require_once __DIR__ . '/LLMReranker.php';
require_once __DIR__ . '/SearchCache.php';
require_once __DIR__ . '/providers/DuckDuckGoHtmlProvider.php';
require_once __DIR__ . '/providers/SerpApiProvider.php';

class SearchOrchestrator {
    private array $providers = [];
    private SearchCache $cache;
    private ?string $claudeApiKey;
    private bool $llmRerankEnabled;

    public function __construct(?string $claudeApiKey = null, bool $llmRerankEnabled = false) {
        $this->cache = new SearchCache();
        $this->claudeApiKey = $claudeApiKey;
        $this->llmRerankEnabled = $llmRerankEnabled;

        // Register default provider
        $this->registerProvider(new DuckDuckGoHtmlProvider());

        // Auto-register SERP API if key is available
        $serpApiKey = getenv('SERPAPI_KEY') ?: '';
        if (!empty($serpApiKey)) {
            $serpProvider = new SerpApiProvider($serpApiKey);
            if ($serpProvider->isAvailable()) {
                $this->registerProvider($serpProvider);
            }
        }
    }

    public function registerProvider(object $provider): void {
        $this->providers[$provider->getName()] = $provider;
    }

    /**
     * Get a registered provider by name.
     */
    public function getProvider(string $name): ?object {
        return $this->providers[$name] ?? null;
    }

    /**
     * Get the preferred provider for a vertical.
     * If SERP API is registered, prefer it for news, retail, real_estate.
     * DuckDuckGo is fallback for everything.
     */
    public function getPreferredProvider(string $vertical): object {
        // If SERP API is registered and available, prefer it for certain verticals
        if (isset($this->providers['serpapi'])) {
            $serpVerticals = ['news', 'retail', 'real_estate'];
            if (in_array($vertical, $serpVerticals)) {
                return $this->providers['serpapi'];
            }
        }
        // Default to DuckDuckGo
        return $this->providers['duckduckgo'] ?? reset($this->providers);
    }

    /**
     * Main search method.
     *
     * @param string $userMessage  Raw user message
     * @param string $vertical     'auto', 'real_estate', 'legal', 'news', 'retail', 'general'
     * @param array  $options      Additional options: max_results, scrape_pages, scrape_max_length
     *
     * @return array{
     *   results: array,
     *   vertical: string,
     *   queries_used: string[],
     *   provider_used: string,
     *   cached: bool,
     *   total_results: int,
     *   context_for_llm: string,
     *   timing_ms: float
     * }
     */
    public function search(string $userMessage, string $vertical = 'auto', array $options = []): array {
        $startTime = microtime(true);

        $maxResults = $options['max_results'] ?? 10;
        $scrapePages = $options['scrape_pages'] ?? 5;
        $scrapeMaxLen = $options['scrape_max_length'] ?? 5000;

        // 1) Build queries
        $queryData = QueryBuilder::build($userMessage, $vertical);
        $vertical = $queryData['vertical'];
        $queries = $queryData['queries'];
        $cleanedQuery = $queryData['cleaned_query'];

        // 2) Check cache (use first query as cache key)
        $cacheKey = $queries[0] ?? $cleanedQuery;
        $cached = $this->cache->get($vertical, $cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            $cached['timing_ms'] = round((microtime(true) - $startTime) * 1000, 1);
            return $cached;
        }

        // 3) Select provider
        $provider = $this->getPreferredProvider($vertical);
        $providerName = $provider->getName();

        // 4) Execute queries and merge
        $allResults = [];
        $seenUrls = [];
        foreach ($queries as $q) {
            $raw = $provider->search($q, $maxResults);
            foreach ($raw as $r) {
                $normUrl = rtrim($r['url'] ?? '', '/');
                if (!isset($seenUrls[$normUrl])) {
                    $allResults[] = $r;
                    $seenUrls[$normUrl] = true;
                }
            }
        }

        // 4b) If primary provider returned nothing, try DuckDuckGo fallback
        if (empty($allResults) && $providerName !== 'duckduckgo' && isset($this->providers['duckduckgo'])) {
            $provider = $this->providers['duckduckgo'];
            $providerName = 'duckduckgo (fallback)';
            foreach ($queries as $q) {
                $raw = $provider->search($q, $maxResults);
                foreach ($raw as $r) {
                    $normUrl = rtrim($r['url'] ?? '', '/');
                    if (!isset($seenUrls[$normUrl])) {
                        $allResults[] = $r;
                        $seenUrls[$normUrl] = true;
                    }
                }
            }
        }

        // 5) Validate each result (extract price, area, etc.)
        $validated = array_map([Validator::class, 'extract'], $allResults);

        // 6) Scrape top pages for content
        $scraped = 0;
        foreach ($validated as &$r) {
            if ($scraped >= $scrapePages) break;

            // Prioritize specific URLs and whitelisted domains
            $shouldScrape = false;
            $urlType = $r['extracted']['url_type'] ?? 'unknown';

            if ($vertical === 'real_estate') {
                $shouldScrape = true; // Scrape all for property searches
            } elseif ($urlType === 'specific') {
                $shouldScrape = true;
            } elseif (DomainPolicy::isWhitelisted($r['url'] ?? '')) {
                $shouldScrape = true;
            } elseif ($scraped < 3) {
                $shouldScrape = true; // Scrape first 3 regardless
            }

            if ($shouldScrape) {
                $content = $provider->scrapeContent($r['url'] ?? '', $scrapeMaxLen);
                if ($content) {
                    $r['scraped_content'] = $content;
                    // Re-extract with scraped content
                    $r = Validator::extract($r);
                    $scraped++;
                }
            }
        }
        unset($r);

        // 7) Rank results
        $ranked = HeuristicRanker::rank($validated, $cleanedQuery, $vertical);

        // 8) Optional LLM rerank
        if ($this->llmRerankEnabled && $this->claudeApiKey && HeuristicRanker::needsLLMRerank($ranked)) {
            $ranked = LLMReranker::rerank($ranked, $cleanedQuery, $vertical, $this->claudeApiKey);
        }

        // 9) Limit results
        $ranked = array_slice($ranked, 0, $maxResults);

        // 10) Build LLM context
        $contextForLLM = $this->buildLLMContext($ranked, $userMessage, $vertical);

        // 11) Build response
        $response = [
            'results' => $this->cleanResultsForOutput($ranked),
            'vertical' => $vertical,
            'queries_used' => $queries,
            'provider_used' => $providerName,
            'cached' => false,
            'total_results' => count($ranked),
            'context_for_llm' => $contextForLLM,
            'timing_ms' => round((microtime(true) - $startTime) * 1000, 1),
        ];

        // 12) Cache
        $this->cache->set($vertical, $cacheKey, $response);

        return $response;
    }

    /**
     * Build pre-formatted context string for Claude.
     */
    private function buildLLMContext(array $results, string $query, string $vertical): string {
        if (empty($results)) {
            return "\n\n🔍 BÚSQUEDA para \"{$query}\": No se encontraron resultados. "
                 . "Informa al usuario que la búsqueda no arrojó resultados y sugiere "
                 . "portales donde buscar directamente: portalinmobiliario.com, yapo.cl, toctoc.com\n";
        }

        $ctx = "\n\n🔍 RESULTADOS DE BÚSQUEDA para \"{$query}\" (vertical: {$vertical}):\n";
        $ctx .= "⚠️ INSTRUCCIÓN: Los siguientes son TODOS los resultados encontrados. "
              . "NO agregues propiedades, precios, sectores ni datos que NO estén aquí. "
              . "Si necesitas más datos, di que no los encontraste.\n\n";

        foreach ($results as $i => $r) {
            $num = $i + 1;
            $urlType = $r['extracted']['url_type'] ?? 'unknown';
            $domain = parse_url($r['url'] ?? '', PHP_URL_HOST) ?: 'unknown';
            $tier = DomainPolicy::getTier($r['url'] ?? '', $vertical);
            $tierLabel = $tier !== 'none' ? " [Tier {$tier}]" : '';

            $ctx .= "{$num}. [{$urlType}]{$tierLabel} {$r['title']}\n";
            $ctx .= "   URL: {$r['url']}\n";
            $ctx .= "   Dominio: {$domain}\n";

            if (!empty($r['snippet'])) {
                $ctx .= "   Extracto: {$r['snippet']}\n";
            }

            // Show extracted data
            $ext = $r['extracted'] ?? [];
            $dataPoints = [];
            if (!empty($ext['price_uf'])) $dataPoints[] = "Precio: UF " . number_format($ext['price_uf'], 0, ',', '.');
            if (!empty($ext['price_clp'])) $dataPoints[] = "Precio: $" . number_format($ext['price_clp'], 0, ',', '.');
            if (!empty($ext['area_m2'])) $dataPoints[] = "Superficie: " . number_format($ext['area_m2'], 0, ',', '.') . " m²";
            if (!empty($ext['price_per_m2'])) $dataPoints[] = "Precio/m²: $" . number_format($ext['price_per_m2'], 0, ',', '.');
            if (!empty($ext['bedrooms'])) $dataPoints[] = $ext['bedrooms'] . " dormitorios";
            if (!empty($ext['bathrooms'])) $dataPoints[] = $ext['bathrooms'] . " baños";

            if (!empty($dataPoints)) {
                $ctx .= "   📊 Datos extraídos: " . implode(' | ', $dataPoints) . "\n";
            }

            // Scraped content (truncated for LLM)
            if (!empty($r['scraped_content'])) {
                $content = substr($r['scraped_content'], 0, 2000);
                $ctx .= "   📄 Contenido: {$content}\n";
            }

            $ctx .= "\n";
        }

        $ctx .= "⚠️ FIN DE RESULTADOS. Toda información en tu respuesta DEBE provenir "
              . "exclusivamente de los datos anteriores. Si el usuario pidió algo que no "
              . "aparece aquí, dilo explícitamente. NO inventes datos adicionales.\n";

        return $ctx;
    }

    /**
     * Clean results for JSON API output (remove scraped_content to reduce size).
     */
    private function cleanResultsForOutput(array $results): array {
        return array_map(function ($r) {
            unset($r['scraped_content']);
            unset($r['llm_justification']);
            unset($r['score_breakdown']);
            return $r;
        }, $results);
    }
}
