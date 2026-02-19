<?php
require_once __DIR__ . '/DomainPolicy.php';

/**
 * Heuristic ranker.
 * Score = 0.35*match + 0.25*domain_trust + 0.20*validated_fields + 0.20*freshness
 */
class HeuristicRanker {

    /**
     * Rank results by heuristic score.
     * Results must already have 'extracted' from Validator.
     *
     * @return array Sorted results with 'score' and 'score_breakdown' keys
     */
    public static function rank(array $results, string $query, string $vertical): array {
        $queryTerms = self::tokenize($query);

        foreach ($results as &$r) {
            $matchScore = self::matchScore($r, $queryTerms);
            $domainScore = DomainPolicy::getTrustScore($r['url'] ?? '', $vertical);
            $fieldScore = self::fieldScore($r);
            $freshnessScore = self::freshnessScore($r);

            $total = 0.35 * $matchScore
                   + 0.25 * $domainScore
                   + 0.20 * $fieldScore
                   + 0.20 * $freshnessScore;

            $r['score'] = round($total, 4);
            $r['score_breakdown'] = [
                'match' => round($matchScore, 3),
                'domain_trust' => round($domainScore, 3),
                'validated_fields' => round($fieldScore, 3),
                'freshness' => round($freshnessScore, 3),
            ];
        }
        unset($r);

        // Sort descending by score
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

    private static function tokenize(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        // Remove stopwords
        $stopwords = ['de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'del', 'un', 'una', 'con', 'por', 'para', 'que', 'se', 'es'];
        return array_values(array_diff($words, $stopwords));
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

    private static function fieldScore(array $result): float {
        $count = $result['extracted']['validated_field_count'] ?? 0;
        // 0 fields = 0.0, 1 = 0.33, 2 = 0.66, 3+ = 1.0
        return min($count / 3.0, 1.0);
    }

    private static function freshnessScore(array $result): float {
        // If we can't determine freshness, return neutral 0.5
        $snippet = $result['snippet'] ?? '';
        // Try to find dates in snippet
        if (preg_match('/202[5-6]/', $snippet)) return 0.9;
        if (preg_match('/2024/', $snippet)) return 0.7;
        if (preg_match('/2023/', $snippet)) return 0.4;
        if (preg_match('/202[0-2]/', $snippet)) return 0.2;
        return 0.5; // neutral if no date found
    }
}
