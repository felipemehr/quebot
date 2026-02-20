<?php
/**
 * Search Orchestrator v3.
 *
 * Pipeline:
 *   IntentParser v3 → QueryBuilder (site: + context queries) → Cache check →
 *   Provider search (parallel) → Filter blocklist → De-dup →
 *   Validate + Extract → Scrape top N → Re-rank →
 *   GeoResolver (resolve zones) → CandidateValidator (strict filter) →
 *   Build LLM context (filtered) → Cache store → Return
 *
 * New in v3:
 * - GeoResolver: resolves "barrio alto" → concrete sectors
 * - CandidateValidator: pre-Claude filtering (price ±tol%, zone, coherence)
 * - 0-results protocol with concrete alternatives
 * - Filter diagnostics in response
 */

require_once __DIR__ . '/IntentParser.php';
require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/DomainPolicy.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/HeuristicRanker.php';
require_once __DIR__ . '/LLMReranker.php';
require_once __DIR__ . '/SearchCache.php';
require_once __DIR__ . '/CandidateValidator.php';
require_once __DIR__ . '/GeoResolver.php';
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
        $contextQueries = $queryData['context_queries'] ?? [];

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

        // Step 3.5: Run context queries in parallel
        $contextResults = [];
        if (!empty($contextQueries) && $vertical === 'real_estate') {
            $contextProvider = $this->getPreferredProvider('general');
            if (method_exists($contextProvider, 'searchParallel')) {
                $contextRaw = $contextProvider->searchParallel($contextQueries, 5);
            } else {
                $contextRaw = [];
                foreach ($contextQueries as $cq) {
                    $contextRaw = array_merge($contextRaw, $contextProvider->search($cq, 5));
                }
            }
            foreach ($contextRaw as $cr) {
                $snippet = $cr['snippet'] ?? $cr['description'] ?? '';
                $title = $cr['title'] ?? '';
                if ($snippet || $title) {
                    $contextResults[] = [
                        'title' => $title,
                        'snippet' => $snippet,
                        'url' => $cr['url'] ?? '',
                    ];
                }
            }
            $contextResults = array_slice($contextResults, 0, 8);
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
                    $r = Validator::extract($r);
                    $scraped++;
                }
            }
        }
        unset($r);

        // Step 7: Re-rank after scraping
        $ranked = HeuristicRanker::rank($ranked, $cleanedQuery, $vertical, $intent ?? []);

        // Optional LLM rerank
        if ($this->llmRerankEnabled && $this->claudeApiKey && HeuristicRanker::needsLLMRerank($ranked)) {
            $ranked = LLMReranker::rerank($ranked, $cleanedQuery, $vertical, $this->claudeApiKey);
        }

        $ranked = array_slice($ranked, 0, $maxResults);

        // ===== NEW IN V3: GeoResolver + CandidateValidator =====

        // Step 8: Resolve zones (for real estate with zone qualifier)
        $resolvedZones = [];
        if ($vertical === 'real_estate' && $intent && !empty($intent['zona_texto'])) {
            $resolvedZones = GeoResolver::resolve($intent, $contextResults);
        }

        // Step 9: Strict candidate filtering
        $filterResult = null;
        $filteredForLLM = $ranked;  // Default: use all ranked results

        if ($vertical === 'real_estate' && $intent) {
            $filterResult = CandidateValidator::filter($ranked, $intent, $resolvedZones);

            // Use passed + soft_failed for Claude (hard_failed excluded)
            $filteredForLLM = array_merge(
                $filterResult['passed'],
                $filterResult['soft_failed']
            );
        }

        // Step 10: Count valid listings
        $validListings = 0;
        $validListingResults = [];
        if ($vertical === 'real_estate') {
            foreach ($filteredForLLM as $r) {
                $urlType = $r['extracted']['url_type'] ?? 'unknown';
                $tier = DomainPolicy::getTier($r['url'] ?? '', $vertical);
                $isListing = $r['validation']['is_listing_page'] ?? false;
                if ($urlType === 'specific' && $tier !== 'none' && !$isListing) {
                    $validListings++;
                    $validListingResults[] = $r;
                }
            }
        }

        // Step 11: Insufficient results handling
        $insufficient = ($vertical === 'real_estate' && $validListings < self::MIN_VALID_LISTINGS);
        $expansionSuggestions = [];
        if ($insufficient && $intent) {
            $expansionSuggestions = self::generateExpansionSuggestions($intent, $resolvedZones, $filterResult);
        }

        // Collect all valid URLs from filtered results
        $validURLs = [];
        foreach ($filteredForLLM as $r) {
            if (!empty($r['url'])) {
                $validURLs[] = $r['url'];
            }
        }

        // Step 12: Build LLM context with filtered results
        $contextForLLM = $this->buildLLMContext(
            $filteredForLLM, $userMessage, $vertical, $intent,
            $insufficient, $expansionSuggestions, $validListings,
            $contextResults, $resolvedZones, $filterResult
        );

        // Build diagnostics
        $diagnostics = [
            'total_raw_results' => count($allResults),
            'after_filter_dedup' => count($ranked),
            'after_candidate_filter' => count($filteredForLLM),
            'valid_listings' => $validListings,
            'insufficient' => $insufficient,
            'queries_sent' => count($searchQueries),
            'context_queries_sent' => count($contextQueries),
            'context_results' => count($contextResults),
            'scraped_pages' => $scraped,
            'zone_resolved' => !empty($resolvedZones['sectors']),
            'zone_confidence' => $resolvedZones['confidence'] ?? 'n/a',
        ];

        if ($filterResult) {
            $diagnostics['filter_stats'] = $filterResult['stats'];
            $diagnostics['filter_summary'] = CandidateValidator::summarizeFilter($filterResult);
        }

        $response = [
            'results' => $this->cleanResultsForOutput($filteredForLLM),
            'vertical' => $vertical,
            'intent' => $intent,
            'queries_used' => $searchQueries,
            'context_queries_used' => $contextQueries,
            'provider_used' => $providerName,
            'cached' => false,
            'total_results' => count($filteredForLLM),
            'valid_listings' => $validListings,
            'insufficient' => $insufficient,
            'expansion_suggestions' => $expansionSuggestions,
            'resolved_zones' => $resolvedZones,
            'diagnostics' => $diagnostics,
            'context_for_llm' => $contextForLLM,
            'valid_urls' => $validURLs,
            'timing_ms' => round((microtime(true) - $startTime) * 1000, 1),
        ];

        if (!empty($filteredForLLM)) {
            $this->cache->set($vertical, $cacheKey, $response);
        }

        return $response;
    }

    /**
     * Generate expansion suggestions when results are insufficient.
     * v3: Uses resolved zones and filter data for concrete suggestions.
     */
    private static function generateExpansionSuggestions(
        array $intent,
        array $resolvedZones = [],
        ?array $filterResult = null
    ): array {
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

        // If zone filter was too strict, suggest adjacent sectors
        if (!empty($resolvedZones['sectors']) && $filterResult) {
            $hardFailCount = $filterResult['stats']['hard_failed'] ?? 0;
            if ($hardFailCount > 0) {
                $sectors = array_slice($resolvedZones['sectors'], 0, 3);
                $suggestions[] = "Se encontraron propiedades pero fuera de " .
                    implode('/', $sectors) . ". ¿Ampliar a barrios colindantes?";
            }
        }

        // Suggest relaxing price if many failed on price
        if ($filterResult && !empty($intent['precio'])) {
            $priceMax = $intent['precio']['max'] ?? 0;
            $moneda = $intent['precio']['moneda'] ?? 'UF';
            if ($priceMax > 0) {
                $expanded = round($priceMax * 1.25);
                $suggestions[] = "Ampliar presupuesto a " .
                    number_format($expanded, 0, ',', '.') . " {$moneda} (+25%)";
            }
        }

        // Suggest relaxing surface constraint
        if (!empty($intent['superficie'])) {
            $s = $intent['superficie'];
            if ($s['unit'] === 'ha' && $s['amount'] >= 3) {
                $smaller = max(1, $s['amount'] - 2);
                $suggestions[] = "Reducir superficie mínima a {$smaller} ha";
            }
        }

        // Suggest broadening property type
        $tipo = $intent['tipo_propiedad'] ?? '';
        if ($tipo === 'parcela') {
            $suggestions[] = 'Buscar también como "terreno" o "sitio"';
        }

        return array_slice($suggestions, 0, 4);
    }

    /**
     * Build pre-formatted context string for Claude.
     * v3: Uses filtered results, includes filter diagnostics and zone context.
     */
    private function buildLLMContext(
        array $results, string $query, string $vertical,
        ?array $intent, bool $insufficient, array $expansionSuggestions,
        int $validListings, array $contextResults = [],
        array $resolvedZones = [], ?array $filterResult = null
    ): string {
        // === INTENT SUMMARY ===
        $ctx = "\n\n";
        if ($intent && $vertical === 'real_estate') {
            $summary = IntentParser::summarize($intent);
            $ctx .= "🎯 INTENCIÓN DETECTADA: {$summary}\n";

            // Show price range with tolerance
            $priceRange = IntentParser::getPriceRange($intent);
            if ($priceRange) {
                $ctx .= "💰 RANGO PRECIO EFECTIVO (con tolerancia ±{$intent['tolerancia_precio_pct']}%): ";
                $ctx .= number_format($priceRange['min'], 0, ',', '.') . " - " .
                         number_format($priceRange['max'], 0, ',', '.') . " {$priceRange['moneda']}\n";
            }

            // Show hard constraints
            if (!empty($intent['restricciones_duras'])) {
                $ctx .= "🔒 RESTRICCIONES DURAS (obligatorias): " .
                         implode(', ', $intent['restricciones_duras']) . "\n";
            }

            if ($intent['confidence'] < 0.3 && !empty($intent['fallback_questions'])) {
                $ctx .= "⚠️ Confianza baja (" . ($intent['confidence'] * 100) . "%). ";
                $ctx .= "Preguntas sugeridas: " . implode(' | ', $intent['fallback_questions']) . "\n";
            }
            $ctx .= "\n";
        }

        // === RESOLVED ZONES (from GeoResolver) ===
        if (!empty($resolvedZones['sectors'])) {
            $ctx .= "📍 ZONAS RESUELTAS para '{$resolvedZones['zona_texto']}' en {$resolvedZones['ciudad']}:\n";
            $ctx .= "   Sectores que coinciden: " . implode(', ', $resolvedZones['sectors']) . "\n";
            if (!empty($resolvedZones['excluded_sectors'])) {
                $ctx .= "   Sectores que NO coinciden: " . implode(', ', $resolvedZones['excluded_sectors']) . "\n";
            }
            $ctx .= "   Confianza: {$resolvedZones['confidence']}\n";
            $ctx .= "\n";
        }

        // === FILTER DIAGNOSTICS ===
        if ($filterResult) {
            $stats = $filterResult['stats'];
            $ctx .= "🔎 FILTRO PRE-RESPUESTA: {$stats['total']} resultados analizados → ";
            $ctx .= "{$stats['passed']} pasan";
            if ($stats['soft_failed'] > 0) $ctx .= ", {$stats['soft_failed']} con advertencias";
            if ($stats['hard_failed'] > 0) $ctx .= ", {$stats['hard_failed']} descartados";
            $ctx .= "\n";

            // Show WHY results were excluded (helps Claude explain honestly)
            if (!empty($filterResult['hard_failed'])) {
                $ctx .= "❌ PROPIEDADES DESCARTADAS (no las muestres, pero explica por qué):\n";
                foreach (array_slice($filterResult['hard_failed'], 0, 3) as $hf) {
                    $reasons = implode('; ', $hf['validation']['failures'] ?? []);
                    $ctx .= "   - {$hf['title']}: {$reasons}\n";
                }
            }
            $ctx .= "\n";
        }

        // === INSUFFICIENT RESULTS ===
        if ($insufficient) {
            $ctx .= "⚠️ OFERTA INSUFICIENTE EN PORTALES DIGITALES\n";
            $ctx .= "Solo se encontraron {$validListings} listados válidos que pasan los filtros.\n";
            $ctx .= "NO inventes propiedades. Informa al usuario honestamente.\n";
            if (!empty($expansionSuggestions)) {
                $ctx .= "📋 SUGERENCIAS DE EXPANSIÓN (ofrece al usuario):\n";
                foreach ($expansionSuggestions as $i => $suggestion) {
                    $ctx .= "  " . ($i + 1) . ". {$suggestion}\n";
                }
            }
            $ctx .= "\n";
        }

        if (empty($results)) {
            $ctx .= "🔍 BÚSQUEDA para \"{$query}\": No se encontraron resultados que pasen los filtros.\n";
            $ctx .= "Informa al usuario que no encontraste propiedades que cumplan sus criterios.\n";
            if (!empty($expansionSuggestions)) {
                $ctx .= "Ofrece estas opciones de ajuste:\n";
                foreach ($expansionSuggestions as $i => $s) {
                    $ctx .= "  " . ($i + 1) . ". {$s}\n";
                }
            }
            if ($vertical === 'real_estate') {
                $ctx .= "Sugiere buscar directamente en: portalinmobiliario.com, yapo.cl, toctoc.com\n";
            }
            return $ctx;
        }

        // === URBAN CONTEXT ===
        if (!empty($contextResults)) {
            $ctx .= "🏘️ CONTEXTO URBANO Y DE MERCADO:\n";
            foreach ($contextResults as $i => $cr) {
                $ctx .= "  " . ($i + 1) . ". {$cr['title']}\n";
                if ($cr['snippet']) {
                    $ctx .= "     {$cr['snippet']}\n";
                }
            }
            $ctx .= "\n";
            $ctx .= "INSTRUCCIÓN: Usa el contexto urbano para:\n";
            $ctx .= "- Identificar qué barrios/sectores coinciden con lo que pide el usuario\n";
            $ctx .= "- Evaluar si las propiedades encontradas están en zonas relevantes\n";
            $ctx .= "- Informar al usuario sobre barrios específicos que coinciden con su búsqueda\n";
            $ctx .= "- Si las propiedades no están en barrios que coincidan, dilo honestamente\n";
            $ctx .= "\n";
        }

        // === SEPARATE RESULTS BY TYPE ===
        $specificResults = [];
        $listingResults = [];
        $otherResults = [];

        foreach ($results as $r) {
            $urlType = $r['extracted']['url_type'] ?? 'unknown';
            $isListing = $r['validation']['is_listing_page'] ?? ($urlType === 'listing');
            if ($isListing) {
                $listingResults[] = $r;
            } elseif ($urlType === 'specific') {
                $specificResults[] = $r;
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
        $ctx .= "5. Si solo hay páginas de búsqueda y ninguna propiedad específica, di que no encontraste propiedades individuales\n";
        $ctx .= "6. Si hay advertencias de validación (⚠️), menciónalas al usuario\n";
        $ctx .= "═══════════════════════════════════════════\n\n";

        $allValidUrls = [];

        // === SECTION A: SPECIFIC PROPERTIES ===
        if (!empty($specificResults)) {
            $ctx .= "━━━ SECCIÓN A: PROPIEDADES ESPECÍFICAS (puedes presentar en tabla) ━━━\n";
            $num = 0;
            foreach ($specificResults as $r) {
                $num++;
                $this->appendResultToContext($ctx, $r, $num, $vertical);

                // Add validation info
                $validation = $r['validation'] ?? null;
                if ($validation && !empty($validation['warnings'])) {
                    $ctx .= "   ⚠️ Notas: " . implode(', ', $validation['warnings']) . "\n";
                }

                $allValidUrls[] = $r['url'];
            }
            $ctx .= "\n";
        } else {
            $ctx .= "━━━ SECCIÓN A: PROPIEDADES ESPECÍFICAS ━━━\n";
            $ctx .= "⚠️ NO se encontraron propiedades individuales que cumplan los filtros.\n";
            if (!empty($filterResult['hard_failed'])) {
                $ctx .= "Se encontraron " . count($filterResult['hard_failed']) . " propiedades pero fueron descartadas por no cumplir criterios.\n";
            }
            $ctx .= "NO inventes propiedades. Informa al usuario y sugiere los links de la Sección B.\n\n";
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

                if (!empty($r['scraped_content'])) {
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
