<?php
/**
 * Search Orchestrator v1.
 * 
 * Pipeline: QueryBuilder → Cache check → Provider search → Validate → Scrape → Rank → (LLM Rerank) → Cache store → Return
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

        $this->registerProvider(new DuckDuckGoHtmlProvider());

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

    public function getProvider(string $name): ?object {
        return $this->providers[$name] ?? null;
    }

    public function getPreferredProvider(string $vertical): object {
        if (isset($this->providers['serpapi'])) {
            $serpVerticals = ['news', 'retail', 'real_estate'];
            if (in_array($vertical, $serpVerticals)) {
                return $this->providers['serpapi'];
            }
        }
        return $this->providers['duckduckgo'] ?? reset($this->providers);
    }

    /**
     * Main search method.
     */
    public function search(string $userMessage, string $vertical = 'auto', array $options = []): array {
        $startTime = microtime(true);

        $maxResults = $options['max_results'] ?? 10;
        $scrapePages = $options['scrape_pages'] ?? 5;
        $scrapeMaxLen = $options['scrape_max_length'] ?? 5000;

        $queryData = QueryBuilder::build($userMessage, $vertical);
        $vertical = $queryData['vertical'];
        $queries = $queryData['queries'];
        $cleanedQuery = $queryData['cleaned_query'];

        $cacheKey = $queries[0] ?? $cleanedQuery;
        $cached = $this->cache->get($vertical, $cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            $cached['timing_ms'] = round((microtime(true) - $startTime) * 1000, 1);
            return $cached;
        }

        $provider = $this->getPreferredProvider($vertical);
        $providerName = $provider->getName();

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

        $validated = array_map([Validator::class, 'extract'], $allResults);

        $scraped = 0;
        foreach ($validated as &$r) {
            if ($scraped >= $scrapePages) break;

            $shouldScrape = false;
            $urlType = $r['extracted']['url_type'] ?? 'unknown';

            if ($vertical === 'real_estate') {
                $shouldScrape = true;
            } elseif ($urlType === 'specific') {
                $shouldScrape = true;
            } elseif (DomainPolicy::isWhitelisted($r['url'] ?? '')) {
                $shouldScrape = true;
            } elseif ($scraped < 3) {
                $shouldScrape = true;
            }

            if ($shouldScrape) {
                $content = $provider->scrapeContent($r['url'] ?? '', $scrapeMaxLen);
                if ($content) {
                    $r['scraped_content'] = $content;
                    $r = Validator::extract($r);
                    $scraped++;
                }
            }
        }
        unset($r);

        $ranked = HeuristicRanker::rank($validated, $cleanedQuery, $vertical);

        if ($this->llmRerankEnabled && $this->claudeApiKey && HeuristicRanker::needsLLMRerank($ranked)) {
            $ranked = LLMReranker::rerank($ranked, $cleanedQuery, $vertical, $this->claudeApiKey);
        }

        $ranked = array_slice($ranked, 0, $maxResults);

        // Collect all valid URLs from results
        $validURLs = [];
        foreach ($ranked as $r) {
            if (!empty($r['url'])) {
                $validURLs[] = $r['url'];
            }
        }

        $contextForLLM = $this->buildLLMContext($ranked, $userMessage, $vertical);

        $response = [
            'results' => $this->cleanResultsForOutput($ranked),
            'vertical' => $vertical,
            'queries_used' => $queries,
            'provider_used' => $providerName,
            'cached' => false,
            'total_results' => count($ranked),
            'context_for_llm' => $contextForLLM,
            'valid_urls' => $validURLs,
            'timing_ms' => round((microtime(true) - $startTime) * 1000, 1),
        ];

        $this->cache->set($vertical, $cacheKey, $response);

        return $response;
    }

    /**
     * Build pre-formatted context string for Claude.
     * Includes explicit URL whitelist to prevent fabrication.
     */
    private function buildLLMContext(array $results, string $query, string $vertical): string {
        if (empty($results)) {
            return "\n\n🔍 BÚSQUEDA para \"{$query}\": No se encontraron resultados. "
                 . "Informa al usuario que la búsqueda no arrojó resultados y sugiere "
                 . "portales donde buscar directamente: portalinmobiliario.com, yapo.cl, toctoc.com\n";
        }

        $ctx = "\n\n🔍 RESULTADOS DE BÚSQUEDA para \"{$query}\" (vertical: {$vertical}):\n";
        $ctx .= "⛔ REGLA ABSOLUTA: Solo puedes usar URLs que aparezcan LITERALMENTE abajo. "
              . "NO construyas URLs. NO inventes slugs. NO combines dominios con paths inventados.\n\n";

        $urlList = [];

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

            if (!empty($r['scraped_content'])) {
                $content = substr($r['scraped_content'], 0, 2000);
                $ctx .= "   📄 Contenido: {$content}\n";
            }

            $ctx .= "\n";
            $urlList[] = $r['url'];
        }

        // === EXPLICIT URL WHITELIST ===
        $ctx .= "═══════════════════════════════════════════\n";
        $ctx .= "📋 URLS PERMITIDAS (las ÚNICAS que puedes usar como links):\n";
        foreach ($urlList as $i => $url) {
            $ctx .= "  " . ($i + 1) . ". {$url}\n";
        }
        $ctx .= "\n⛔ CUALQUIER URL QUE NO ESTÉ EN ESTA LISTA = FABRICACIÓN = FALLO DEL SISTEMA\n";
        $ctx .= "⛔ NO construyas URLs tipo yapo.cl/temuco/casas_venta/nombre-inventado-12345.htm\n";
        $ctx .= "⛔ Si necesitas recomendar un portal, usa SOLO el dominio: yapo.cl, portalinmobiliario.com\n";
        $ctx .= "═══════════════════════════════════════════\n";

        $ctx .= "\n⚠️ FIN DE RESULTADOS. Toda información en tu respuesta DEBE provenir "
              . "exclusivamente de los datos anteriores. Si el usuario pidió algo que no "
              . "aparece aquí, dilo explícitamente. NO inventes datos adicionales.\n";

        return $ctx;
    }

    private function cleanResultsForOutput(array $results): array {
        return array_map(function ($r) {
            unset($r['scraped_content']);
            unset($r['llm_justification']);
            unset($r['score_breakdown']);
            return $r;
        }, $results);
    }
}
