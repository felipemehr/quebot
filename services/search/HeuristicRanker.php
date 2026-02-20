<?php
require_once __DIR__ . '/DomainPolicy.php';

/**
 * HeuristicRanker v2 — Listing-aware scoring for real estate.
 *
 * Score formula:
 *   0.25 * query_match
 * + 0.25 * domain_trust
 * + 0.20 * listing_signals (is it a specific property page?)
 * + 0.15 * data_richness (extracted price/area/beds)
 * + 0.15 * freshness
 * - penalties (search pages, category pages, informational)
 *
 * New in v2:
 * - Listing-specific scoring (specific > listing > unknown > informational)
 * - Penalty for search/category pages
 * - Blocklist filtering (google.com, serpapi.com, etc.)
 * - De-duplication by canonical URL
 */
class HeuristicRanker {

    /** Domains that should NEVER appear in final results */
    private static array $blocklist = [
        'google.com', 'google.cl', 'serpapi.com', 'bing.com',
        'duckduckgo.com', 'wikipedia.org', 'facebook.com',
        'instagram.com', 'twitter.com', 'x.com', 'youtube.com',
        'tiktok.com', 'pinterest.com',
    ];

    /**
     * Full pipeline: filter → de-dup → score → sort.
     *
     * @param array $results Results with 'extracted' from Validator
     * @param string $query Cleaned query
     * @param string $vertical Detected vertical
     * @param array $intent Parsed intent from IntentParser (optional)
     * @return array Sorted, filtered, deduplicated results
     */
    public static function rank(array $results, string $query, string $vertical, array $intent = []): array {
        // Step 1: Remove blocklisted domains
        $results = self::filterBlocklist($results);

        // Step 2: De-duplicate by canonical URL
        $results = self::deduplicate($results);

        // Step 3: Score each result
        $queryTerms = self::tokenize($query);
        foreach ($results as &$r) {
            $scores = self::scoreResult($r, $queryTerms, $vertical, $intent);
            $r['score'] = $scores['total'];
            $r['score_breakdown'] = $scores['breakdown'];
        }
        unset($r);

        // Step 4: Sort descending by score
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Check if top results have similar scores (trigger for LLM rerank).
     */
    public static function needsLLMRerank(array $rankedResults, float $threshold = 0.08): bool {
        if (count($rankedResults) < 3) return false;
        $topScore = $rankedResults[0]['score'] ?? 0;
        $thirdScore = $rankedResults[2]['score'] ?? 0;
        return ($topScore - $thirdScore) < $threshold;
    }

    // ===== FILTERING =====

    private static function filterBlocklist(array $results): array {
        return array_values(array_filter($results, function($r) {
            $url = $r['url'] ?? '';
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $host = preg_replace('/^www\\./', '', $host);
            foreach (self::$blocklist as $blocked) {
                if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                    return false;
                }
            }
            return true;
        }));
    }

    // ===== DE-DUPLICATION =====

    private static function deduplicate(array $results): array {
        $seen = [];
        $deduped = [];

        foreach ($results as $r) {
            $key = self::canonicalUrl($r['url'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $r;
            }
        }

        return $deduped;
    }

    /**
     * Normalize URL for de-duplication.
     * Remove tracking params, trailing slashes, www prefix.
     */
    private static function canonicalUrl(string $url): string {
        $parsed = parse_url($url);
        $host = strtolower(preg_replace('/^www\\./', '', $parsed['host'] ?? ''));
        $path = rtrim($parsed['path'] ?? '', '/');

        // Remove common tracking parameters
        $query = $parsed['query'] ?? '';
        if ($query) {
            parse_str($query, $params);
            // Remove tracking params
            $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
                               'utm_content', 'fbclid', 'gclid', 'ref', 'source'];
            foreach ($trackingParams as $tp) {
                unset($params[$tp]);
            }
            $query = !empty($params) ? '?' . http_build_query($params) : '';
        }

        return $host . $path . $query;
    }

    // ===== SCORING =====

    private static function scoreResult(array $result, array $queryTerms, string $vertical, array $intent): array {
        $matchScore = self::matchScore($result, $queryTerms);
        $domainScore = DomainPolicy::getTrustScore($result['url'] ?? '', $vertical);
        $listingScore = self::listingScore($result, $vertical);
        $dataScore = self::dataRichnessScore($result, $intent);
        $freshnessScore = self::freshnessScore($result);
        $penalty = self::penaltyScore($result, $vertical);

        $total = 0.25 * $matchScore
               + 0.25 * $domainScore
               + 0.20 * $listingScore
               + 0.15 * $dataScore
               + 0.15 * $freshnessScore
               - $penalty;

        $total = max(0.0, min(1.0, $total));

        return [
            'total' => round($total, 4),
            'breakdown' => [
                'query_match' => round($matchScore, 3),
                'domain_trust' => round($domainScore, 3),
                'listing_type' => round($listingScore, 3),
                'data_richness' => round($dataScore, 3),
                'freshness' => round($freshnessScore, 3),
                'penalty' => round($penalty, 3),
            ],
        ];
    }

    private static function matchScore(array $result, array $queryTerms): float {
        if (empty($queryTerms)) return 0.5;
        $text = mb_strtolower(($result['title'] ?? '') . ' ' . ($result['snippet'] ?? ''));
        $hits = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($text, $term)) $hits++;
        }
        return $hits / count($queryTerms);
    }

    /**
     * Score based on URL type: specific property > listing page > unknown > informational.
     */
    private static function listingScore(array $result, string $vertical): float {
        if ($vertical !== 'real_estate') return 0.5; // neutral for non-RE

        $urlType = $result['extracted']['url_type'] ?? 'unknown';
        $text = mb_strtolower(($result['title'] ?? '') . ' ' . ($result['snippet'] ?? ''));

        // Bonus signals that this is a real listing
        $listingSignals = ['uf', '$', 'm²', 'm2', 'ha', 'hectárea', 'dorm',
                           'baño', 'rol', 'comuna', 'dormitorio', 'venta',
                           'arriendo', 'precio'];
        $signalCount = 0;
        foreach ($listingSignals as $sig) {
            if (str_contains($text, $sig)) $signalCount++;
        }
        $signalBonus = min($signalCount / 5.0, 0.3);

        return match($urlType) {
            'specific' => 0.9 + $signalBonus * 0.1,
            'listing' => 0.5 + $signalBonus,
            default => 0.2 + $signalBonus,
        };
    }

    /**
     * Score based on how many structured data fields were extracted.
     * More data = more useful result.
     */
    private static function dataRichnessScore(array $result, array $intent): float {
        $ext = $result['extracted'] ?? [];
        $score = 0.0;

        // Has price (critical for RE)
        if (!empty($ext['price_uf']) || !empty($ext['price_clp'])) $score += 0.35;

        // Has area
        if (!empty($ext['area_m2'])) $score += 0.25;

        // Has bedrooms/bathrooms
        if (!empty($ext['bedrooms'])) $score += 0.15;
        if (!empty($ext['bathrooms'])) $score += 0.10;

        // Has price per m2
        if (!empty($ext['price_per_m2'])) $score += 0.15;

        return min($score, 1.0);
    }

    private static function freshnessScore(array $result): float {
        $snippet = $result['snippet'] ?? '';
        $date = $result['date'] ?? '';
        $text = $snippet . ' ' . $date;

        if (preg_match('/202[5-6]/', $text)) return 0.9;
        if (preg_match('/2024/', $text)) return 0.7;
        if (preg_match('/2023/', $text)) return 0.4;
        if (preg_match('/202[0-2]/', $text)) return 0.2;
        return 0.5; // neutral if no date found
    }

    /**
     * Penalty for low-value results.
     */
    private static function penaltyScore(array $result, string $vertical): float {
        $url = strtolower($result['url'] ?? '');
        $title = mb_strtolower($result['title'] ?? '');
        $penalty = 0.0;

        // Search/results pages
        if (preg_match('/\/(search|buscar|resultados|results)\b/', $url)) {
            $penalty += 0.3;
        }

        // AMP pages
        if (str_contains($url, '/amp/') || str_contains($url, '?amp=')) {
            $penalty += 0.1;
        }

        // Category/hub pages (too generic)
        if (preg_match('/^\/(casas|departamentos|parcelas|terrenos)\/?$/', parse_url($url, PHP_URL_PATH) ?? '')) {
            $penalty += 0.2;
        }

        // Informational articles (not listings) in RE vertical
        if ($vertical === 'real_estate') {
            $infoSignals = ['blog', 'artículo', 'articulo', 'guía', 'guia', 'tips',
                           'cómo comprar', 'como comprar', 'consejos', 'qué es'];
            foreach ($infoSignals as $sig) {
                if (str_contains($title, $sig) || str_contains($url, $sig)) {
                    $penalty += 0.25;
                    break;
                }
            }
        }

        return min($penalty, 0.6); // cap penalty at 0.6
    }

    // ===== HELPERS =====

    private static function tokenize(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = ['de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'del',
                      'un', 'una', 'con', 'por', 'para', 'que', 'se', 'es'];
        return array_values(array_diff($words, $stopwords));
    }
}
