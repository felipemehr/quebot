<?php
/**
 * Search Orchestrator v2.
 *
 * Pipeline:
 *   IntentParser → QueryBuilder (site: queries) → Cache check →
 *   Provider search (parallel) → Filter blocklist → De-dup →
 *   Validate + Extract → Scrape top N → Re-rank (listing-aware) →
 *   Insufficient check → Build LLM context → Cache store → Return
 *
 * New in v2:
 * - Structured intent parsing for real_estate
 * - site: operator queries (not raw user text)
 * - Insufficient results handling with expansion suggestions
 * - Intent + diagnostics in response
 * - Fallback generic query if site: yields too few results
 */

require_once __DIR__ . '/IntentParser.php';
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

    /** Minimum valid listings before declaring "insufficient offer" */
    private const MIN_VALID_LISTINGS = 2;

    /** Maximum queries to send in parallel (SerpAPI budget) */
    private const MAX_PARALLEL_QUERIES = 8;

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
        // Always prefer SerpAPI when available (supports site: operator)
        if (isset($this->providers['serpapi'])) {
            return $this->providers['serpapi'];
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

        // Step 1: Parse intent + build queries
        $queryData = QueryBuilder::build($userMessage, $vertical);
        $vertical = $queryData['vertical'];
        $queries = $queryData['queries'];
        $cleanedQuery = $queryData['cleaned_query'];
        $intent = $queryData['intent'] ?? null;

        // Step 2: Cache check
        $cacheKey = $queries[0] ?? $cleanedQuery;
        $cached = $this->cache->get($vertical, $cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            $cached['timing_ms'] = round((microtime(true) - $startTime) * 1000, 1);
            return $cached;
        }

        // Step 3: Provider search (parallel)
        $provider = $this->getPreferredProvider($vertical);
        $providerName = $provider->getName();

        // Limit queries to budget
        $searchQueries = array_slice($queries, 0, self::MAX_PARALLEL_QUERIES);

        $allResults = [];
        if (method_exists($provider, 'searchParallel')) {
            $allResults = $provider->searchParallel($searchQueries, $maxResults);
        } else {
            $seenUrls = [];
            foreach ($searchQueries as $q) {
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

        // Fallback to DDG if primary provider failed
        if (empty($allResults) && $providerName !== 'duckduckgo' && isset($this->providers['duckduckgo'])) {
            $provider = $this->providers['duckduckgo'];
            $providerName = 'duckduckgo (fallback)';
            $seenUrls = [];
            foreach ($searchQueries as $q) {
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

        // Step 4: Validate + extract structured data
        $validated = array_map([Validator::class, 'extract'], $allResults);

        // Step 5: Rank (includes blocklist filter + de-dup)
        $ranked = HeuristicRanker::rank($validated, $cleanedQuery, $vertical, $intent ?? []);

        // Step 6: Scrape top results for enrichment
        $scraped = 0;
        foreach ($ranked as &$r) {
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
                    // Re-extract with scraped content
                    $r = Validator::extract($r);
                    $scraped++;
                }
            }
        }
        unset($r);

        // Step 7: Re-rank after scraping (scores may change with more data)
        $ranked = HeuristicRanker::rank($ranked, $cleanedQuery, $vertical, $intent ?? []);

        // Optional LLM rerank
        if ($this->llmRerankEnabled && $this->claudeApiKey && HeuristicRanker::needsLLMRerank($ranked)) {
            $ranked = LLMReranker::rerank($ranked, $cleanedQuery, $vertical, $this->claudeApiKey);
        }

        $ranked = array_slice($ranked, 0, $maxResults);

        // Step 8: Count valid listings for real estate
        $validListings = 0;
        $validListingResults = [];
        if ($vertical === 'real_estate') {
            foreach ($ranked as $r) {
                $urlType = $r['extracted']['url_type'] ?? 'unknown';
                $tier = DomainPolicy::getTier($r['url'] ?? '', $vertical);
                // A valid listing = specific URL on whitelisted domain OR listing page with data
                if ($urlType === 'specific' && $tier !== 'none') {
                    $validListings++;
                    $validListingResults[] = $r;
                } elseif ($urlType === 'listing' && $tier !== 'none' && ($r['extracted']['validated_field_count'] ?? 0) >= 2) {
                    $validListings++;
                    $validListingResults[] = $r;
                }
            }
        }

        // Step 9: Insufficient results handling
        $insufficient = ($vertical === 'real_estate' && $validListings < self::MIN_VALID_LISTINGS);
        $expansionSuggestions = [];
        if ($insufficient && $intent) {
            $expansionSuggestions = self::generateExpansionSuggestions($intent);
        }

        // Collect all valid URLs from results
        $validURLs = [];
        foreach ($ranked as $r) {
            if (!empty($r['url'])) {
                $validURLs[] = $r['url'];
            }
        }

        // Step 10: Build LLM context
        $contextForLLM = $this->buildLLMContext(
            $ranked, $userMessage, $vertical, $intent,
            $insufficient, $expansionSuggestions, $validListings
        );

        // Build diagnostics
        $diagnostics = [
            'total_raw_results' => count($allResults),
            'after_filter_dedup' => count($ranked),
            'valid_listings' => $validListings,
            'insufficient' => $insufficient,
            'queries_sent' => count($searchQueries),
            'scraped_pages' => $scraped,
        ];

        $response = [
            'results' => $this->cleanResultsForOutput($ranked),
            'vertical' => $vertical,
            'intent' => $intent,
            'queries_used' => $searchQueries,
            'provider_used' => $providerName,
            'cached' => false,
            'total_results' => count($ranked),
            'valid_listings' => $validListings,
            'insufficient' => $insufficient,
            'expansion_suggestions' => $expansionSuggestions,
            'diagnostics' => $diagnostics,
            'context_for_llm' => $contextForLLM,
            'valid_urls' => $validURLs,
            'timing_ms' => round((microtime(true) - $startTime) * 1000, 1),
        ];

        // Only cache if we have results
        if (!empty($ranked)) {
            $this->cache->set($vertical, $cacheKey, $response);
        }

        return $response;
    }

    /**
     * Generate expansion suggestions when results are insufficient.
     */
    private static function generateExpansionSuggestions(array $intent): array {
        $suggestions = [];

        $location = $intent['ubicacion'] ?? '';

        // Suggest nearby locations
        $nearbyMap = [
            'Melipeuco' => ['Cunco', 'Villarrica', 'Pucón'],
            'Curacautín' => ['Lonquimay', 'Victoria', 'Lautaro'],
            'Pucón' => ['Villarrica', 'Cunco', 'Loncoche'],
            'Villarrica' => ['Pucón', 'Loncoche', 'Freire'],
            'Cunco' => ['Melipeuco', 'Villarrica', 'Temuco'],
            'Lonquimay' => ['Curacautín', 'Victoria', 'Melipeuco'],
            'Hornopirén' => ['Hualaihué', 'Calbuco', 'Puerto Montt'],
            'Hualaihué' => ['Hornopirén', 'Calbuco', 'Puerto Montt'],
            'Chaitén' => ['Hornopirén', 'Hualaihué', 'Puerto Montt'],
            'Futrono' => ['Lago Ranco', 'Panguipulli', 'Río Bueno'],
            'Panguipulli' => ['Futrono', 'Loncoche', 'Villarrica'],
        ];

        if ($location && isset($nearbyMap[$location])) {
            $nearby = $nearbyMap[$location];
            $suggestions[] = "Ampliar búsqueda a comunas cercanas: " . implode(', ', $nearby);
        } elseif ($location) {
            $suggestions[] = "Ampliar búsqueda a comunas vecinas de {$location}";
        }

        // Suggest relaxing surface constraint
        if (!empty($intent['superficie'])) {
            $s = $intent['superficie'];
            if ($s['unit'] === 'ha' && $s['amount'] >= 3) {
                $smaller = max(1, $s['amount'] - 2);
                $suggestions[] = "Reducir superficie mínima a {$smaller} ha";
            }
        }

        // Suggest relaxing must_have
        if (count($intent['must_have'] ?? []) > 1) {
            $suggestions[] = "Flexibilizar requisitos: mantener solo los más importantes y verificar los demás directamente con el vendedor";
        }

        // Suggest broadening property type
        $tipo = $intent['tipo_propiedad'] ?? '';
        if ($tipo === 'parcela') {
            $suggestions[] = 'Buscar también como "terreno" o "sitio" (publicaciones pueden usar distintos términos)';
        }

        return $suggestions;
    }

    /**
     * Build pre-formatted context string for Claude.
     * v3: Separates SPECIFIC properties from LISTING pages.
     * Claude can ONLY present specific URLs as individual properties.
     * Listing URLs are "search more here" references only.
     */
    private function buildLLMContext(
        array $results, string $query, string $vertical,
        ?array $intent, bool $insufficient, array $expansionSuggestions,
        int $validListings
    ): string {
        // === INTENT SUMMARY ===
        $ctx = "\n\n";
        if ($intent && $vertical === 'real_estate') {
            $summary = IntentParser::summarize($intent);
            $ctx .= "🎯 INTENCIÓN DETECTADA: {$summary}\n";
            if ($intent['confidence'] < 0.3 && !empty($intent['fallback_questions'])) {
                $ctx .= "⚠️ Confianza baja (" . ($intent['confidence'] * 100) . "%). ";
                $ctx .= "Preguntas sugeridas: " . implode(' | ', $intent['fallback_questions']) . "\n";
            }
            $ctx .= "\n";
        }

        // === INSUFFICIENT RESULTS ===
        if ($insufficient) {
            $ctx .= "⚠️ OFERTA INSUFICIENTE EN PORTALES DIGITALES\n";
            $ctx .= "Solo se encontraron {$validListings} listados válidos (mínimo requerido: " . self::MIN_VALID_LISTINGS . ").\n";
            $ctx .= "NO inventes propiedades. Informa al usuario que la oferta digital es limitada.\n";
            if (!empty($expansionSuggestions)) {
                $ctx .= "📋 SUGERENCIAS DE EXPANSIÓN:\n";
                foreach ($expansionSuggestions as $i => $suggestion) {
                    $ctx .= "  " . ($i + 1) . ". {$suggestion}\n";
                }
            }
            $ctx .= "\n";
        }

        if (empty($results)) {
            $ctx .= "🔍 BÚSQUEDA para \"{$query}\": No se encontraron resultados.\n";
            $ctx .= "Informa al usuario que la búsqueda no arrojó resultados.\n";
            if ($vertical === 'real_estate') {
                $ctx .= "Sugiere buscar directamente en: portalinmobiliario.com, yapo.cl, toctoc.com\n";
            }
            return $ctx;
        }

        // === SEPARATE RESULTS BY TYPE ===
        $specificResults = [];
        $listingResults = [];
        $otherResults = [];

        foreach ($results as $r) {
            $urlType = $r['extracted']['url_type'] ?? 'unknown';
            if ($urlType === 'specific') {
                $specificResults[] = $r;
            } elseif ($urlType === 'listing') {
                $listingResults[] = $r;
            } else {
                $otherResults[] = $r;
            }
        }

        $ctx .= "🔍 RESULTADOS DE BÚSQUEDA para \"{$query}\" (vertical: {$vertical}):\n\n";

        // === CRITICAL RULES ===
        $ctx .= "═══════════════════════════════════════════\n";
        $ctx .= "⛔ REGLAS ABSOLUTAS:\n";
        $ctx .= "1. Solo puedes usar URLs que aparezcan LITERALMENTE abajo\n";
        $ctx .= "2. NO construyas URLs. NO inventes slugs. NO combines dominios con paths inventados\n";
        $ctx .= "3. PROPIEDADES ESPECÍFICAS: Solo las de la sección A pueden ir en tablas como propiedades individuales\n";
        $ctx .= "4. PÁGINAS DE BÚSQUEDA: Las de la sección B son listados generales. NUNCA las presentes como una propiedad individual\n";
        $ctx .= "5. Si solo hay páginas de búsqueda y ninguna propiedad específica, di que no encontraste propiedades individuales y sugiere los links de búsqueda\n";
        $ctx .= "═══════════════════════════════════════════\n\n";

        $allValidUrls = [];

        // === SECTION A: SPECIFIC PROPERTIES ===
        if (!empty($specificResults)) {
            $ctx .= "━━━ SECCIÓN A: PROPIEDADES ESPECÍFICAS (puedes presentar en tabla) ━━━\n";
            $num = 0;
            foreach ($specificResults as $r) {
                $num++;
                $this->appendResultToContext($ctx, $r, $num, $vertical);
                $allValidUrls[] = $r['url'];
            }
            $ctx .= "\n";
        } else {
            $ctx .= "━━━ SECCIÓN A: PROPIEDADES ESPECÍFICAS ━━━\n";
            $ctx .= "⚠️ NO se encontraron propiedades individuales con link directo.\n";
            $ctx .= "NO inventes propiedades. Informa al usuario y sugiere los links de búsqueda de la Sección B.\n\n";
        }

        // === SECTION B: LISTING/SEARCH PAGES ===
        if (!empty($listingResults)) {
            $ctx .= "━━━ SECCIÓN B: PÁGINAS DE BÚSQUEDA (solo para \"buscar más aquí\", NUNCA como propiedad individual) ━━━\n";
            $num = 0;
            foreach ($listingResults as $r) {
                $num++;
                $domain = parse_url($r['url'] ?? '', PHP_URL_HOST) ?: 'unknown';
                $ctx .= "{$num}. [LISTADO] {$domain}\n";
                $ctx .= "   URL: {$r['url']}\n";
                $ctx .= "   Descripción: Página de búsqueda con múltiples propiedades\n";

                // If scraped content has useful data, mention it
                if (!empty($r['scraped_content'])) {
                    // Extract any property data found in the listing page
                    $content = substr($r['scraped_content'], 0, 2000);
                    $ctx .= "   📄 Contenido de la página: {$content}\n";
                }
                $ctx .= "\n";
                $allValidUrls[] = $r['url'];
            }
            $ctx .= "\n";
        }

        // === SECTION C: OTHER RESULTS ===
        if (!empty($otherResults)) {
            $ctx .= "━━━ SECCIÓN C: OTROS RESULTADOS ━━━\n";
            $num = 0;
            foreach ($otherResults as $r) {
                $num++;
                $this->appendResultToContext($ctx, $r, $num, $vertical);
                $allValidUrls[] = $r['url'];
            }
            $ctx .= "\n";
        }

        // === EXPLICIT URL WHITELIST ===
        $ctx .= "═══════════════════════════════════════════\n";
        $ctx .= "📋 URLS PERMITIDAS (las ÚNICAS que puedes usar como links):\n";
        foreach ($allValidUrls as $i => $url) {
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

    /**
     * Append a single result's details to context string.
     */
    private function appendResultToContext(string &$ctx, array $r, int $num, string $vertical): void {
        $urlType = $r['extracted']['url_type'] ?? 'unknown';
        $domain = parse_url($r['url'] ?? '', PHP_URL_HOST) ?: 'unknown';
        $tier = DomainPolicy::getTier($r['url'] ?? '', $vertical);
        $tierLabel = $tier !== 'none' ? " [Tier {$tier}]" : '';
        $score = $r['score'] ?? 0;

        $ctx .= "{$num}. [{$urlType}]{$tierLabel} (score: {$score}) {$r['title']}\n";
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
