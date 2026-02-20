<?php
/**
 * ResponseVerifier — Pre-delivery quality gate for QueBot responses.
 * 
 * Evaluates Claude's response across 5 dimensions before delivering to user.
 * Uses Haiku for semantic analysis + rule-based checks for URLs/numbers.
 * 
 * Pipeline: Claude response → Verifier → PASS|PATCH|REGEN|FLAG → Deliver
 */
class ResponseVerifier {
    
    private string $apiKey;
    private bool $debug;
    
    // Thresholds by vertical
    private const THRESHOLDS = [
        'default' => ['pass' => 0.80, 'patch' => 0.60, 'regen' => 0.40],
        'legal'   => ['pass' => 0.90, 'patch' => 0.70, 'regen' => 0.50],
    ];
    
    // Dimension weights
    private const WEIGHTS = [
        'factual_accuracy' => 0.30,
        'url_validity'     => 0.25,
        'completeness'     => 0.20,
        'hallucination'    => 0.20,
        'tone'             => 0.05,
    ];
    
    // Filler phrases QueBot should avoid
    private const FILLER_PHRASES = [
        '¡perfecto!', '¡excelente!', '¡genial!', '¡maravilloso!', '¡fantástico!',
        '¡por supuesto!', '¡con mucho gusto!', '¡claro que sí!', '¡absolutamente!',
        'con gusto te ayudo', 'estaré encantado', 'será un placer',
    ];

    // Max emojis allowed
    private const MAX_EMOJIS = 2;
    
    public function __construct(string $apiKey, bool $debug = false) {
        $this->apiKey = $apiKey;
        $this->debug = $debug;
    }
    
    /**
     * Main entry point — verify a response before delivery.
     * 
     * @param string $userQuery Original user message
     * @param string $response Claude's generated response
     * @param array $searchResults Raw search results (URLs, snippets, extracted data)
     * @param array|null $intent Parsed intent from IntentParser
     * @param string $vertical Detected vertical (real_estate, legal, news, general)
     * @return array Verification result with verdict, confidence, dimensions, and possibly modified response
     */
    public function verify(
        string $userQuery, 
        string $response, 
        array $searchResults = [],
        ?array $intent = null,
        string $vertical = 'general'
    ): array {
        $startTime = microtime(true);
        
        // Skip verification for conversational messages (no search results)
        if (empty($searchResults)) {
            return $this->buildResult('SKIP', 1.0, [], $response, $startTime, 'No search results — conversational message');
        }
        
        // === RULE-BASED CHECKS (fast, no API call) ===
        $urlCheck = $this->checkUrlValidity($response, $searchResults);
        $toneCheck = $this->checkTone($response);
        
        // === SEMANTIC CHECK via Haiku (accuracy, completeness, hallucination) ===
        $semanticCheck = $this->semanticVerification($userQuery, $response, $searchResults, $intent, $vertical);
        
        // === COMBINE DIMENSIONS ===
        $dimensions = [
            'factual_accuracy' => $semanticCheck['factual_accuracy'] ?? ['score' => 0.8, 'issues' => []],
            'url_validity'     => $urlCheck,
            'completeness'     => $semanticCheck['completeness'] ?? ['score' => 0.8, 'issues' => []],
            'hallucination'    => $semanticCheck['hallucination'] ?? ['score' => 0.8, 'issues' => []],
            'tone'             => $toneCheck,
        ];
        
        // === CALCULATE CONFIDENCE ===
        $confidence = $this->calculateConfidence($dimensions);
        
        // === DETERMINE VERDICT ===
        $thresholds = self::THRESHOLDS[$vertical] ?? self::THRESHOLDS['default'];
        $verdict = $this->determineVerdict($confidence, $thresholds);
        
        // === APPLY RESOLUTION STRATEGY ===
        $finalResponse = $response;
        $fixes = [];
        $regenerated = false;
        
        switch ($verdict) {
            case 'PASS':
                // Deliver as-is (maybe minor tone fixes)
                if (!empty($toneCheck['fixes'])) {
                    $finalResponse = $this->applyToneFixes($response, $toneCheck['fixes']);
                    $fixes = $toneCheck['fixes'];
                }
                break;
                
            case 'PATCH':
                // Apply automatic fixes
                $patchResult = $this->applyPatches($response, $dimensions, $searchResults);
                $finalResponse = $patchResult['response'];
                $fixes = $patchResult['fixes'];
                break;
                
            case 'REGEN':
                // Regenerate with specific instructions
                $regenResult = $this->regenerate($userQuery, $searchResults, $dimensions, $intent, $vertical);
                if ($regenResult['success']) {
                    $finalResponse = $regenResult['response'];
                    $regenerated = true;
                    // Re-verify the regenerated response (but don't loop)
                    $reVerify = $this->quickCheck($finalResponse, $searchResults);
                    if ($reVerify['confidence'] < $thresholds['regen']) {
                        // Even regenerated response is bad → FLAG
                        $verdict = 'FLAG';
                        $flagResult = $this->buildFlagResponse($userQuery, $searchResults, $vertical);
                        $finalResponse = $flagResult;
                    }
                } else {
                    // Regeneration failed → FLAG
                    $verdict = 'FLAG';
                    $flagResult = $this->buildFlagResponse($userQuery, $searchResults, $vertical);
                    $finalResponse = $flagResult;
                }
                break;
                
            case 'FLAG':
                $finalResponse = $this->buildFlagResponse($userQuery, $searchResults, $vertical);
                break;
        }
        
        return $this->buildResult($verdict, $confidence, $dimensions, $finalResponse, $startTime, null, [
            'fixes' => $fixes,
            'regenerated' => $regenerated,
            'original_response' => ($verdict !== 'PASS' && $verdict !== 'SKIP') ? $response : null,
        ]);
    }
    
    /**
     * Rule-based URL validation — checks all URLs against search results whitelist.
     */
    private function checkUrlValidity(string $response, array $searchResults): array {
        $issues = [];
        $totalUrls = 0;
        $validUrls = 0;
        
        // Build whitelist from search results
        $allowedUrls = [];
        foreach ($searchResults as $result) {
            if (!empty($result['url'])) {
                $allowedUrls[] = rtrim($result['url'], '/');
            }
            if (!empty($result['link'])) {
                $allowedUrls[] = rtrim($result['link'], '/');
            }
        }
        
        // Safe domains (legal, government)
        $safeDomains = [
            'leychile.cl', 'bcn.cl', 'sii.cl', 'contraloria.cl',
            'google.com', 'maps.google.com', 'minvu.gob.cl',
        ];
        
        // Extract markdown links
        preg_match_all('/\[([^\]]*)\]\((https?:\/\/[^\)]+)\)/', $response, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $url = rtrim($match[2], '/');
            $totalUrls++;
            
            // Check if in whitelist
            $isValid = false;
            
            // Direct match
            if (in_array($url, $allowedUrls)) {
                $isValid = true;
            }
            
            // Partial match (URL starts with allowed URL)
            if (!$isValid) {
                foreach ($allowedUrls as $allowed) {
                    if (str_starts_with($url, $allowed)) {
                        $isValid = true;
                        break;
                    }
                }
            }
            
            // Homepage/root URL
            $path = parse_url($url, PHP_URL_PATH);
            if (!$isValid && (empty($path) || $path === '/')) {
                $isValid = true;
            }
            
            // Safe domain
            if (!$isValid) {
                $host = parse_url($url, PHP_URL_HOST) ?? '';
                foreach ($safeDomains as $safe) {
                    if (str_contains($host, $safe)) {
                        $isValid = true;
                        break;
                    }
                }
            }
            
            if ($isValid) {
                $validUrls++;
            } else {
                $issues[] = "URL no respaldada: {$url}";
            }
        }
        
        $score = $totalUrls > 0 ? $validUrls / $totalUrls : 1.0;
        
        return [
            'score' => round($score, 2),
            'issues' => $issues,
            'total_urls' => $totalUrls,
            'valid_urls' => $validUrls,
            'fixes' => array_map(fn($i) => ['type' => 'url_fabricated', 'detail' => $i], $issues),
        ];
    }
    
    /**
     * Rule-based tone check — filler phrases, emoji count, etc.
     */
    private function checkTone(string $response): array {
        $issues = [];
        $fixes = [];
        $score = 1.0;
        
        $responseLower = mb_strtolower($response);
        
        // Check filler phrases
        foreach (self::FILLER_PHRASES as $filler) {
            if (str_contains($responseLower, $filler)) {
                $issues[] = "Frase filler detectada: \"{$filler}\"";
                $fixes[] = ['type' => 'remove_filler', 'text' => $filler];
                $score -= 0.1;
            }
        }
        
        // Count emojis
        $emojiCount = preg_match_all('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}]/u', $response);
        
        if ($emojiCount > self::MAX_EMOJIS) {
            $issues[] = "Demasiados emojis: {$emojiCount} (máx " . self::MAX_EMOJIS . ")";
            $score -= 0.05 * ($emojiCount - self::MAX_EMOJIS);
        }
        
        return [
            'score' => max(0.0, round($score, 2)),
            'issues' => $issues,
            'fixes' => $fixes,
        ];
    }
    
    /**
     * Semantic verification via Haiku — checks factual accuracy, completeness, hallucination.
     */
    private function semanticVerification(
        string $userQuery, 
        string $response, 
        array $searchResults, 
        ?array $intent, 
        string $vertical
    ): array {
        // Build condensed search context for Haiku
        $searchSummary = $this->buildSearchSummary($searchResults);
        
        $prompt = <<<PROMPT
Eres un verificador de calidad para un chatbot inmobiliario chileno llamado QueBot.
Tu trabajo es evaluar si la RESPUESTA es precisa y fiel a los DATOS DE BÚSQUEDA.

CONSULTA DEL USUARIO:
{$userQuery}

DATOS DE BÚSQUEDA DISPONIBLES (fuente real):
{$searchSummary}

INTENCIÓN DETECTADA:
{$this->formatIntent($intent)}

RESPUESTA A VERIFICAR:
{$response}

Evalúa estas 3 dimensiones en JSON:

1. **factual_accuracy**: ¿Los precios, superficies, ubicaciones y datos específicos en la respuesta coinciden con los datos de búsqueda? Score 0-1.
2. **completeness**: ¿La respuesta aborda lo que preguntó el usuario? Para inmobiliaria: ¿tiene tabla con propiedades, links, precios? Para legal: ¿cita artículos? Score 0-1.
3. **hallucination**: ¿Hay información específica (precios, %%, tendencias, propiedades) que NO aparece en los datos de búsqueda? Score 1 = sin alucinación, 0 = toda inventada.

Responde SOLO en JSON válido, sin texto adicional:
{
  "factual_accuracy": {"score": 0.0, "issues": ["..."]},
  "completeness": {"score": 0.0, "issues": ["..."]},
  "hallucination": {"score": 0.0, "issues": ["dato inventado: ..."]}
}
PROMPT;

        try {
            $result = $this->callHaiku($prompt);
            
            // Parse JSON from Haiku response
            $parsed = $this->extractJson($result);
            if ($parsed) {
                return $parsed;
            }
            
            error_log("ResponseVerifier: Could not parse Haiku response");
            // Fallback — moderate scores
            return $this->defaultSemanticScores();
            
        } catch (\Throwable $e) {
            error_log("ResponseVerifier semantic check failed: " . $e->getMessage());
            return $this->defaultSemanticScores();
        }
    }
    
    /**
     * Quick re-check after regeneration (rule-based only, no API call).
     */
    private function quickCheck(string $response, array $searchResults): array {
        $urlCheck = $this->checkUrlValidity($response, $searchResults);
        $toneCheck = $this->checkTone($response);
        
        $confidence = ($urlCheck['score'] * 0.5) + ($toneCheck['score'] * 0.1) + 0.4; // base 0.4 for regen
        
        return ['confidence' => round($confidence, 2)];
    }
    
    /**
     * Apply automatic patches to response.
     */
    private function applyPatches(string $response, array $dimensions, array $searchResults): array {
        $fixes = [];
        
        // 1. Fix fabricated URLs → replace with domain homepage
        if (!empty($dimensions['url_validity']['fixes'])) {
            $allowedUrls = [];
            foreach ($searchResults as $r) {
                $url = $r['url'] ?? $r['link'] ?? '';
                if ($url) $allowedUrls[] = rtrim($url, '/');
            }
            
            $response = preg_replace_callback(
                '/\[([^\]]*)\]\((https?:\/\/[^\)]+)\)/',
                function ($match) use ($allowedUrls, &$fixes) {
                    $url = rtrim($match[2], '/');
                    $host = parse_url($url, PHP_URL_HOST);
                    $path = parse_url($url, PHP_URL_PATH);
                    
                    // Skip if homepage or in whitelist
                    if (empty($path) || $path === '/' || in_array($url, $allowedUrls)) {
                        return $match[0];
                    }
                    
                    // Check partial match
                    foreach ($allowedUrls as $allowed) {
                        if (str_starts_with($url, $allowed)) {
                            return $match[0];
                        }
                    }
                    
                    // Replace with homepage
                    $fixes[] = ['type' => 'url_replaced', 'from' => $url, 'to' => "https://{$host}"];
                    return "[{$match[1]}](https://{$host})";
                },
                $response
            );
        }
        
        // 2. Apply tone fixes
        if (!empty($dimensions['tone']['fixes'])) {
            $response = $this->applyToneFixes($response, $dimensions['tone']['fixes']);
            $fixes = array_merge($fixes, $dimensions['tone']['fixes']);
        }
        
        // 3. Add disclaimer if hallucination issues detected
        $hallucinationIssues = $dimensions['hallucination']['issues'] ?? [];
        if (!empty($hallucinationIssues) && ($dimensions['hallucination']['score'] ?? 1) < 0.7) {
            $response .= "\n\n> ⚠️ *Algunos datos podrían no estar completamente verificados. Te recomiendo confirmar precios y disponibilidad directamente en los portales.*";
            $fixes[] = ['type' => 'disclaimer_added', 'reason' => 'hallucination_detected'];
        }
        
        return ['response' => $response, 'fixes' => $fixes];
    }
    
    /**
     * Apply tone fixes — remove filler phrases, reduce emojis.
     */
    private function applyToneFixes(string $response, array $fixes): array|string {
        foreach ($fixes as $fix) {
            if (($fix['type'] ?? '') === 'remove_filler' && !empty($fix['text'])) {
                // Remove the filler phrase (case-insensitive)
                $pattern = '/^' . preg_quote($fix['text'], '/') . '\s*/iu';
                $response = preg_replace($pattern, '', $response);
                // Also catch it mid-sentence
                $response = str_ireplace($fix['text'], '', $response);
            }
        }
        
        // Clean up double spaces/newlines from removals
        $response = preg_replace('/\n{3,}/', "\n\n", $response);
        $response = preg_replace('/  +/', ' ', $response);
        
        return trim($response);
    }
    
    /**
     * Regenerate response with specific correction instructions.
     */
    private function regenerate(
        string $userQuery, 
        array $searchResults, 
        array $dimensions, 
        ?array $intent, 
        string $vertical
    ): array {
        // Build correction instructions from issues
        $corrections = [];
        foreach ($dimensions as $dimName => $dim) {
            foreach ($dim['issues'] ?? [] as $issue) {
                $corrections[] = "- [{$dimName}] {$issue}";
            }
        }
        
        $correctionText = implode("\n", array_slice($corrections, 0, 10)); // Max 10 corrections
        $searchContext = $this->buildSearchSummary($searchResults);
        
        $regenPrompt = <<<PROMPT
Regenera la respuesta corrigiendo los siguientes problemas detectados por el verificador:

{$correctionText}

REGLAS ESTRICTAS:
- Usa SOLO datos que aparezcan textualmente en los resultados de búsqueda
- Si no tienes un dato, NO lo inventes — di que no está disponible
- Máximo 2 emojis
- Sin frases filler como "¡Perfecto!", "¡Excelente!"
- Directo al dato
- Para propiedades: tabla con link, superficie, precio, ubicación
- Todos los links DEBEN ser de los resultados de búsqueda

RESULTADOS DE BÚSQUEDA DISPONIBLES:
{$searchContext}

CONSULTA ORIGINAL: {$userQuery}
PROMPT;

        try {
            // Use Sonnet for regeneration (better quality)
            $result = $this->callClaude($regenPrompt, $userQuery);
            return ['success' => true, 'response' => $result];
        } catch (\Throwable $e) {
            error_log("ResponseVerifier regeneration failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build minimal FLAG response when quality is too low.
     */
    private function buildFlagResponse(string $userQuery, array $searchResults, string $vertical): string {
        $response = "Busqué información sobre **" . htmlspecialchars($userQuery) . "** pero no pude verificar los resultados con suficiente confianza.\n\n";
        
        // Add direct links to portals
        if ($vertical === 'real_estate') {
            $response .= "Te sugiero buscar directamente en estos portales:\n\n";
            $response .= "- 🏠 [Portal Inmobiliario](https://www.portalinmobiliario.com)\n";
            $response .= "- 🏡 [Yapo.cl](https://www.yapo.cl/inmuebles)\n";
            $response .= "- 📊 [TocToc](https://www.toctoc.com)\n";
            $response .= "- 🔍 [GoPlaceit](https://www.goplaceit.com/cl)\n";
        } elseif ($vertical === 'legal') {
            $response .= "Te recomiendo consultar directamente:\n\n";
            $response .= "- ⚖️ [LeyChile](https://www.leychile.cl)\n";
            $response .= "- 📜 [BCN](https://www.bcn.cl)\n";
        } else {
            $response .= "Te comparto los enlaces que encontré para que verifiques directamente:\n\n";
            $validResults = array_filter($searchResults, fn($r) => !empty($r['url']) || !empty($r['link']));
            foreach (array_slice($validResults, 0, 5) as $r) {
                $url = $r['url'] ?? $r['link'] ?? '';
                $title = $r['title'] ?? $r['snippet'] ?? $url;
                $response .= "- [{$title}]({$url})\n";
            }
        }
        
        $response .= "\n> Los datos de búsqueda no fueron suficientes para armar una respuesta confiable. Prefiero ser honesto antes que inventar. 🤝";
        
        return $response;
    }
    
    // ========================
    // HELPER METHODS
    // ========================
    
    private function calculateConfidence(array $dimensions): float {
        $weighted = 0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $score = $dimensions[$dim]['score'] ?? 0.5;
            $weighted += $score * $weight;
        }
        return round($weighted, 2);
    }
    
    private function determineVerdict(float $confidence, array $thresholds): string {
        if ($confidence >= $thresholds['pass']) return 'PASS';
        if ($confidence >= $thresholds['patch']) return 'PATCH';
        if ($confidence >= $thresholds['regen']) return 'REGEN';
        return 'FLAG';
    }
    
    private function buildSearchSummary(array $searchResults): string {
        $lines = [];
        foreach (array_slice($searchResults, 0, 15) as $i => $r) {
            $url = $r['url'] ?? $r['link'] ?? 'N/A';
            $title = $r['title'] ?? 'Sin título';
            $snippet = $r['snippet'] ?? $r['description'] ?? '';
            $price = $r['extracted_price'] ?? $r['price'] ?? '';
            $area = $r['extracted_area'] ?? $r['area'] ?? '';
            
            $line = ($i + 1) . ". [{$title}]({$url})";
            if ($snippet) $line .= " — " . mb_substr($snippet, 0, 150);
            if ($price) $line .= " | Precio: {$price}";
            if ($area) $line .= " | Área: {$area}";
            $lines[] = $line;
        }
        return implode("\n", $lines) ?: "Sin resultados de búsqueda.";
    }
    
    private function formatIntent(?array $intent): string {
        if (!$intent) return "No detectada";
        $parts = [];
        if (!empty($intent['tipo_propiedad'])) $parts[] = "Tipo: " . $intent['tipo_propiedad'];
        if (!empty($intent['ubicacion'])) $parts[] = "Ubicación: " . $intent['ubicacion'];
        if (!empty($intent['presupuesto'])) {
            $p = $intent['presupuesto'];
            $parts[] = "Presupuesto: " . ($p['raw'] ?? json_encode($p));
        }
        if (!empty($intent['superficie'])) {
            $s = $intent['superficie'];
            $parts[] = "Superficie: " . ($s['raw'] ?? json_encode($s));
        }
        return implode(" | ", $parts) ?: "Intent vacío";
    }
    
    private function callHaiku(string $prompt): string {
        $payload = json_encode([
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ], JSON_UNESCAPED_UNICODE);
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Haiku API error: HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? '';
    }
    
    private function callClaude(string $systemPrompt, string $userMessage): string {
        $payload = json_encode([
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4096,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage]
            ]
        ], JSON_UNESCAPED_UNICODE);
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 45,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Claude API error: HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? '';
    }
    
    private function extractJson(string $text): ?array {
        // Try direct parse
        $parsed = json_decode($text, true);
        if ($parsed && is_array($parsed)) return $parsed;
        
        // Try extracting JSON from code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $parsed = json_decode($m[1], true);
            if ($parsed) return $parsed;
        }
        
        // Try finding JSON object in text
        if (preg_match('/\{[^{}]*"factual_accuracy"[^{}]*\}/s', $text, $m)) {
            // More lenient — find outermost braces
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
                if ($parsed) return $parsed;
            }
        }
        
        return null;
    }
    
    private function defaultSemanticScores(): array {
        return [
            'factual_accuracy' => ['score' => 0.75, 'issues' => ['No se pudo verificar con Haiku']],
            'completeness'     => ['score' => 0.75, 'issues' => []],
            'hallucination'    => ['score' => 0.75, 'issues' => ['Verificación semántica no disponible']],
        ];
    }
    
    private function buildResult(
        string $verdict, 
        float $confidence, 
        array $dimensions, 
        string $response, 
        float $startTime, 
        ?string $note = null,
        array $extra = []
    ): array {
        return [
            'verdict'           => $verdict,
            'confidence'        => $confidence,
            'dimensions'        => $dimensions,
            'response'          => $response,
            'timing_ms'         => round((microtime(true) - $startTime) * 1000),
            'note'              => $note,
            'fixes'             => $extra['fixes'] ?? [],
            'regenerated'       => $extra['regenerated'] ?? false,
            'original_response' => $extra['original_response'] ?? null,
        ];
    }
}
