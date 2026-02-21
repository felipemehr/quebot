<?php
/**
 * Optional LLM-based reranker using Claude Haiku.
 * Only called when heuristic scores are too close to differentiate.
 * Max 8 documents input. Minimal cost approach.
 */
class LLMReranker {

    /**
     * Rerank results using Claude LLM.
     * Returns reordered results with 'llm_justification' per item.
     *
     * @param array  $results    Pre-ranked results (max 8 will be sent)
     * @param string $query      Original user query
     * @param string $vertical   Detected vertical
     * @param string $apiKey     Claude API key
     * @return array Reranked results
     */
    public static function rerank(array $results, string $query, string $vertical, string $apiKey): array {
        $topN = array_slice($results, 0, 8);
        $rest = array_slice($results, 8);

        // Build compact prompt
        $items = [];
        foreach ($topN as $i => $r) {
            $items[] = sprintf(
                "#%d | %s | %s | %s",
                $i + 1,
                $r['title'] ?? 'Sin título',
                substr($r['snippet'] ?? '', 0, 150),
                parse_url($r['url'] ?? '', PHP_URL_HOST) ?: 'unknown'
            );
        }

        $prompt = "Query del usuario: \"{$query}\" (vertical: {$vertical})\n\n"
                . "Ordena los siguientes resultados de búsqueda del más relevante al menos relevante.\n"
                . "Responde SOLO con JSON: {\"order\": [3,1,5,...], \"notes\": [\"razón1\",\"razón2\",...]}\n\n"
                . implode("\n", $items);

        $response = self::callClaude($prompt, $apiKey);
        if (!$response) {
            // LLM failed — return original order
            return $results;
        }

        // Parse response
        $decoded = json_decode($response, true);
        if (!$decoded || !isset($decoded['order']) || !is_array($decoded['order'])) {
            return $results;
        }

        // Reorder
        $reordered = [];
        $notes = $decoded['notes'] ?? [];
        foreach ($decoded['order'] as $idx => $pos) {
            $arrayIdx = $pos - 1;
            if (isset($topN[$arrayIdx])) {
                $item = $topN[$arrayIdx];
                $item['llm_justification'] = $notes[$idx] ?? null;
                $reordered[] = $item;
            }
        }

        // Add any items not mentioned in LLM response
        $reorderedUrls = array_map(fn($r) => $r['url'] ?? '', $reordered);
        foreach ($topN as $item) {
            if (!in_array($item['url'] ?? '', $reorderedUrls)) {
                $reordered[] = $item;
            }
        }

        // Append rest (items 9+)
        return array_merge($reordered, $rest);
    }

    private static function callClaude(string $prompt, string $apiKey): ?string {
        $payload = json_encode([
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 300,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'header' => implode("\r\n", [
                    "Content-Type: application/json",
                    "x-api-key: {$apiKey}",
                    "anthropic-version: 2023-06-01",
                ]),
                'content' => $payload,
            ]
        ]);

        $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
        if (!$resp) return null;

        $data = json_decode($resp, true);
        $text = $data['content'][0]['text'] ?? null;
        if (!$text) return null;

        // Extract JSON from response (Claude might wrap it in text)
        if (preg_match('/\{[^{}]*"order"\s*:\s*\[.*?\].*?\}/s', $text, $m)) {
            return $m[0];
        }
        return $text;
    }
}
