<?php
/**
 * ProfileBuilder v2 — Weighted behavioral profiling with confidence scoring
 * 
 * Extracts ONLY from explicit user statements. Never from:
 * - Cities in search results or news
 * - Assistant-generated suggestions
 * - Portal recommendations or content snippets
 * 
 * Each profile item tracks: mentions (count), weight, confidence.
 * Low-confidence items are filtered out over time.
 */
class ProfileBuilder {
    private string $apiKey;
    private string $model = 'claude-3-haiku-20240307';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    private int $timeout = 8;

    // Weight constants
    const W_FIRST_MENTION   = 1;
    const W_REPEAT          = 2;
    const W_DIRECT_FILTER   = 5;  // "solo Temuco", "busco en X"
    const W_BUDGET_SPEC     = 5;
    const W_PROPERTY_REPEAT = 3;
    const W_THRESHOLD       = 2;  // Minimum weight to keep item
    const W_MAX             = 10; // For confidence normalization

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Determine if a message is worth extracting preferences from.
     */
    /**
     * Known button/template messages — low confidence for profiling.
     */
    private const BUTTON_TEMPLATES = [
        'compara precios del dólar',
        'noticias importantes de chile',
        'principales noticias de chile',
        'buscar propiedades en',
        'parcelas y casas en',
    ];

    /**
     * Check if a message came from a button/template click.
     */
    public function isButtonMessage(string $message): bool {
        $msg = mb_strtolower(trim($message));
        foreach (self::BUTTON_TEMPLATES as $template) {
            if (str_starts_with($msg, $template)) {
                return true;
            }
        }
        return false;
    }

        public function shouldExtract(string $message): bool {
        $msg = trim($message);
        if (mb_strlen($msg) < 12) return false;

        $trivials = [
            'hola', 'gracias', 'ok', 'dale', 'sí', 'si', 'no', 
            'chao', 'bueno', 'listo', 'perfecto', 'genial', 'vale',
            'claro', 'ya', 'ah', 'oh', 'mmm', 'jaja', 'xd',
            'buenos días', 'buenas tardes', 'buenas noches',
            'muchas gracias', 'de nada', 'hasta luego', 'adiós',
            'sigue', 'continúa', 'dale', 'siguiente'
        ];
        
        $lower = mb_strtolower($msg);
        foreach ($trivials as $t) {
            if ($lower === $t) return false;
        }
        
        return true;
    }

    /**
     * Extract user preferences with weighted confidence scoring.
     * 
     * CRITICAL: Only extracts from user's OWN words, never from assistant content.
     */
    public function extractProfile(
        string $userMessage, 
        string $assistantResponse, 
        ?array $existingProfile = null
    ): ?array {
        $existingJson = $existingProfile 
            ? json_encode($this->getProfileSummary($existingProfile), JSON_UNESCAPED_UNICODE) 
            : '{}';

        // Detect if user used direct filter language (stronger signal)
        $hasDirectFilter = $this->detectDirectFilter($userMessage);
        
        // Button/template messages get low_confidence treatment
        $isButton = $this->isButtonMessage($userMessage);
        if ($isButton) {
            $hasDirectFilter = false; // Never treat button clicks as strong signals
        }

        $prompt = $this->buildExtractionPrompt($userMessage, $existingJson, $hasDirectFilter, $isButton);

        $requestData = [
            'model' => $this->model,
            'max_tokens' => 500,
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            error_log("ProfileBuilder: " . ($curlError ?: "HTTP {$httpCode}"));
            return null;
        }

        $result = json_decode($response, true);
        $text = trim($result['content'][0]['text'] ?? '');

        // Clean markdown wrappers
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $extracted = json_decode($text, true);
        if (!$extracted || !is_array($extracted)) {
            error_log("ProfileBuilder: Parse error: " . mb_substr($text, 0, 100));
            return null;
        }

        // If empty, return null
        $filtered = array_filter($extracted, fn($v) => $v !== null && $v !== '' && $v !== []);
        if (empty($filtered)) return null;

        // Log token usage
        $tokens = $result['usage'] ?? [];
        error_log("ProfileBuilder v2: tokens in=" . ($tokens['input_tokens'] ?? 0) . " out=" . ($tokens['output_tokens'] ?? 0));

        // Weighted merge
        return $this->weightedMerge($existingProfile ?? [], $extracted, $hasDirectFilter);
    }

    /**
     * Build the extraction prompt — ONLY user-declared preferences.
     */
    private function buildExtractionPrompt(string $userMessage, string $existingJson, bool $hasDirectFilter, bool $isButton = false): string {
        $prompt = "Analiza SOLO el mensaje del USUARIO. Extrae preferencias EXPLÍCITAS que el usuario declara.\n\n";
        
        if ($isButton) {
            $prompt .= "⚠️ ATENCIÓN: Este mensaje viene de un BOTÓN PREDEFINIDO (plantilla), NO de texto libre del usuario.\n";
            $prompt .= "NO extraigas ubicaciones ni preferencias de este mensaje — es genérico, no refleja intención real del usuario.\n";
            $prompt .= "Solo extrae interests (categoría general de interés) si aplica. Responde {} para todo lo demás.\n\n";
        }
        $prompt .= "PERFIL ACTUAL:\n{$existingJson}\n\n";
        $prompt .= "MENSAJE DEL USUARIO:\n{$userMessage}\n\n";
        
        $prompt .= "REGLAS ESTRICTAS:\n";
        $prompt .= "1. SOLO extraer lo que el usuario DICE DIRECTAMENTE\n";
        $prompt .= "2. NO extraer ciudades que aparezcan en contenido de noticias o resultados\n";
        $prompt .= "3. NO extraer ubicaciones mencionadas como ejemplo o referencia\n";
        $prompt .= "4. SI el usuario dice 'busco en X' o 'solo X' → es preferencia FUERTE\n";
        $prompt .= "5. SI el usuario pregunta sobre X sin decir que QUIERE algo ahí → NO es preferencia\n";
        $prompt .= "6. Preguntas sobre noticias/dólar/UF/política → NO son preferencias inmobiliarias\n";
        $prompt .= "7. Si el mensaje menciona ciudades EN CONTEXTO de noticias o finanzas → NO extraer como ubicación de interés\n";
        $prompt .= "8. Solo extraer ubicaciones si el usuario BUSCA, QUIERE, o NECESITA algo en ese lugar\n\n";

        $prompt .= "Responde SOLO JSON válido (sin markdown, sin ```):\n";
        $prompt .= "{\n";
        $prompt .= "  \"locations\": [\"SOLO ciudades/zonas donde el usuario QUIERE buscar/vivir/comprar\"],\n";
        $prompt .= "  \"property_types\": [\"casa\",\"departamento\",\"parcela\",\"terreno\",\"oficina\"],\n";
        $prompt .= "  \"bedrooms\": null,\n";
        $prompt .= "  \"bathrooms\": null,\n";
        $prompt .= "  \"budget\": {\"min\": 0, \"max\": 0, \"unit\": \"UF\"},\n";
        $prompt .= "  \"min_area_m2\": null,\n";
        $prompt .= "  \"purpose\": \"inversión|uso personal|arriendo|null\",\n";
        $prompt .= "  \"interests\": [\"propiedades\",\"legal\",\"noticias\",\"retail\",\"finanzas\"],\n";
        $prompt .= "  \"key_requirements\": [\"requisitos explícitos del usuario\"],\n";
        $prompt .= "  \"family_info\": null,\n";
        $prompt .= "  \"is_direct_filter\": " . ($hasDirectFilter ? 'true' : 'false') . "\n";
        $prompt .= "}\n\n";
        $prompt .= "Sin info nueva del USUARIO → responde: {}\n";
        $prompt .= "IMPORTANTE: 'busco parcela en Temuco' = preferencia. 'qué pasa con el mercado en Santiago' = NO preferencia.\n";

        return $prompt;
    }

    /**
     * Detect if user message contains direct filter language.
     * "busco en Temuco", "solo parcelas", "máximo 5000 UF" → strong signal
     */
    private function detectDirectFilter(string $message): bool {
        $lower = mb_strtolower($message);
        $patterns = [
            '/\b(busco|quiero|necesito|me interesa)\s+(en|una?|un)\b/u',
            '/\bsolo\s+(en|casas?|deptos?|parcelas?|terrenos?)\b/u',
            '/\b(máximo|max|hasta|presupuesto)\s+\d/u',
            '/\bmi\s+(zona|barrio|sector|ciudad)\b/u',
            '/\b(3d|4d|2b|3b)\b/u',  // shorthand for bedrooms/bathrooms
            '/\b\d+\s*(dormitorios|baños|piezas)\b/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $lower)) return true;
        }
        return false;
    }

    /**
     * Weighted merge with confidence scoring.
     * 
     * New schema for array fields:
     * locations: [{"name": "Temuco", "confidence": 0.95, "mentions": 8, "weight": 9.5}]
     * property_types: [{"name": "parcela", "confidence": 0.8, "mentions": 4, "weight": 8}]
     */
    private function weightedMerge(array $existing, array $extracted, bool $isDirectFilter): array {
        $merged = $existing;

        // === Weighted array fields ===
        $arrayFields = ['locations', 'property_types', 'interests', 'key_requirements'];
        foreach ($arrayFields as $field) {
            if (empty($extracted[$field]) || !is_array($extracted[$field])) continue;
            
            $currentItems = $merged[$field] ?? [];
            
            // Normalize existing: convert legacy flat arrays to weighted format
            $currentItems = $this->normalizeWeightedArray($currentItems);
            
            foreach ($extracted[$field] as $newItem) {
                $itemName = is_string($newItem) ? $newItem : ($newItem['name'] ?? '');
                $itemName = trim($itemName);
                if ($itemName === '') continue;

                $found = false;
                foreach ($currentItems as &$existing_item) {
                    if (mb_strtolower($existing_item['name']) === mb_strtolower($itemName)) {
                        // Existing item — increment
                        $existing_item['mentions'] = ($existing_item['mentions'] ?? 1) + 1;
                        $weightAdd = self::W_REPEAT;
                        if ($isDirectFilter) $weightAdd += self::W_DIRECT_FILTER;
                        if ($field === 'property_types') $weightAdd += self::W_PROPERTY_REPEAT;
                        $existing_item['weight'] = min(20, ($existing_item['weight'] ?? self::W_FIRST_MENTION) + $weightAdd);
                        $existing_item['confidence'] = min(1.0, $existing_item['weight'] / self::W_MAX);
                        $existing_item['last_seen'] = date('c');
                        $found = true;
                        break;
                    }
                }
                unset($existing_item);

                if (!$found) {
                    // New item
                    $weight = self::W_FIRST_MENTION;
                    if ($isDirectFilter) $weight += self::W_DIRECT_FILTER;
                    $currentItems[] = [
                        'name' => $itemName,
                        'confidence' => min(1.0, $weight / self::W_MAX),
                        'mentions' => 1,
                        'weight' => $weight,
                        'first_seen' => date('c'),
                        'last_seen' => date('c')
                    ];
                }
            }

            // Sort by weight descending
            usort($currentItems, fn($a, $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));

            $merged[$field] = $currentItems;
        }

        // === Scalar fields (overwrite if new) ===
        $scalarFields = ['bedrooms', 'bathrooms', 'min_area_m2', 'purpose', 'family_info'];
        foreach ($scalarFields as $field) {
            if (isset($extracted[$field]) && $extracted[$field] !== null) {
                $merged[$field] = $extracted[$field];
            }
        }

        // === Budget (structured, with weight boost) ===
        if (!empty($extracted['budget']) && is_array($extracted['budget'])) {
            $newBudget = $extracted['budget'];
            if (($newBudget['max'] ?? 0) > 0) {
                $merged['budget'] = $newBudget;
                $merged['budget']['confidence'] = 0.9; // Budget spec is high confidence
            }
        }

        // === Behavioral signals ===
        $merged['behavioral_signals'] = $this->updateBehavioralSignals(
            $merged['behavioral_signals'] ?? [],
            $extracted
        );

        // === Profile confidence score ===
        $merged['profile_confidence_score'] = $this->calculateOverallConfidence($merged);

        $merged['updated_at'] = date('c');
        $merged['profile_version'] = 2;

        return $merged;
    }

    /**
     * Convert legacy flat array ["Temuco", "Renca"] to weighted format.
     * Backward compatible — old profiles get default weights.
     */
    private function normalizeWeightedArray(array $items): array {
        if (empty($items)) return [];
        
        // Check if already weighted format
        if (isset($items[0]) && is_array($items[0]) && isset($items[0]['name'])) {
            return $items;
        }

        // Legacy flat array — convert
        $normalized = [];
        foreach ($items as $idx => $item) {
            if (is_string($item)) {
                $normalized[] = [
                    'name' => $item,
                    'confidence' => $idx === 0 ? 0.5 : 0.3, // First = slightly higher
                    'mentions' => 1,
                    'weight' => $idx === 0 ? 5 : 3, // Legacy items get moderate weight
                    'first_seen' => date('c'),
                    'last_seen' => date('c'),
                    'migrated_from_v1' => true
                ];
            } elseif (is_array($item) && isset($item['name'])) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    /**
     * Track behavioral signals — intent distribution over time.
     */
    private function updateBehavioralSignals(array $existing, array $extracted): array {
        $signals = $existing;
        
        // Detect intent from extracted data
        $intent = 'general';
        if (!empty($extracted['locations']) || !empty($extracted['property_types']) || 
            !empty($extracted['budget']) || !empty($extracted['bedrooms'])) {
            $intent = 'property_search';
        } elseif (!empty($extracted['interests'])) {
            $interests = $extracted['interests'];
            if (in_array('legal', $interests)) $intent = 'legal';
            elseif (in_array('noticias', $interests)) $intent = 'news';
            elseif (in_array('finanzas', $interests)) $intent = 'financial';
            elseif (in_array('retail', $interests)) $intent = 'retail';
        }

        // Update distribution
        $dist = $signals['intent_distribution'] ?? [];
        $dist[$intent] = ($dist[$intent] ?? 0) + 1;
        $signals['intent_distribution'] = $dist;

        // Calculate dominant intent
        $total = array_sum($dist);
        $max = 0;
        $dominant = 'general';
        foreach ($dist as $k => $v) {
            if ($v > $max) {
                $max = $v;
                $dominant = $k;
            }
        }
        $signals['dominant_intent'] = $dominant;
        $signals['dominant_pct'] = $total > 0 ? round(($max / $total) * 100) : 0;
        $signals['total_extractions'] = $total;

        return $signals;
    }

    /**
     * Calculate overall profile confidence score (0.0 - 1.0).
     * Based on: repetition depth, budget clarity, geographic specificity, consistency.
     */
    private function calculateOverallConfidence(array $profile): float {
        $score = 0.0;
        $factors = 0;

        // Location confidence (weighted average)
        $locs = $profile['locations'] ?? [];
        if (!empty($locs)) {
            $locConf = 0;
            foreach ($locs as $loc) {
                if (is_array($loc)) {
                    $locConf = max($locConf, $loc['confidence'] ?? 0);
                }
            }
            $score += $locConf;
            $factors++;
        }

        // Budget clarity
        $budget = $profile['budget'] ?? [];
        if (!empty($budget) && ($budget['max'] ?? 0) > 0) {
            $score += ($budget['confidence'] ?? 0.7);
            $factors++;
        }

        // Property type confidence
        $types = $profile['property_types'] ?? [];
        if (!empty($types)) {
            $typeConf = 0;
            foreach ($types as $t) {
                if (is_array($t)) {
                    $typeConf = max($typeConf, $t['confidence'] ?? 0);
                }
            }
            $score += $typeConf;
            $factors++;
        }

        // Bedrooms/bathrooms (explicit = good signal)
        if (!empty($profile['bedrooms'])) {
            $score += 0.8;
            $factors++;
        }

        // Purpose specified
        if (!empty($profile['purpose'])) {
            $score += 0.7;
            $factors++;
        }

        if ($factors === 0) return 0.0;
        return round($score / $factors, 2);
    }

    /**
     * Get a simplified summary for the extraction prompt.
     * Don't overwhelm Haiku with the full weighted structure.
     */
    private function getProfileSummary(array $profile): array {
        $summary = [];
        
        // Flatten weighted arrays for the prompt
        foreach (['locations', 'property_types', 'interests', 'key_requirements'] as $field) {
            $items = $profile[$field] ?? [];
            $flat = [];
            foreach ($items as $item) {
                if (is_string($item)) $flat[] = $item;
                elseif (is_array($item) && isset($item['name'])) $flat[] = $item['name'];
            }
            if (!empty($flat)) $summary[$field] = $flat;
        }

        // Copy scalars
        foreach (['bedrooms', 'bathrooms', 'min_area_m2', 'purpose', 'family_info'] as $f) {
            if (isset($profile[$f]) && $profile[$f] !== null) $summary[$f] = $profile[$f];
        }
        if (!empty($profile['budget'])) {
            $summary['budget'] = [
                'min' => $profile['budget']['min'] ?? 0,
                'max' => $profile['budget']['max'] ?? 0,
                'unit' => $profile['budget']['unit'] ?? 'UF'
            ];
        }

        return $summary;
    }

    /**
     * Sanitize profile — remove low-confidence and stale items.
     * Called periodically (nightly or on load).
     */
    public function sanitizeProfile(array $profile): array {
        $arrayFields = ['locations', 'property_types', 'interests', 'key_requirements'];
        
        foreach ($arrayFields as $field) {
            $items = $profile[$field] ?? [];
            if (empty($items)) continue;

            $cleaned = [];
            foreach ($items as $item) {
                if (is_string($item)) {
                    // Legacy item with no weight info — keep but flag
                    $cleaned[] = [
                        'name' => $item,
                        'confidence' => 0.3,
                        'mentions' => 1,
                        'weight' => 3,
                        'migrated_from_v1' => true
                    ];
                    continue;
                }
                
                if (!is_array($item)) continue;

                $weight = $item['weight'] ?? 0;
                $confidence = $item['confidence'] ?? 0;
                $mentions = $item['mentions'] ?? 0;

                // Remove if: weight below threshold AND only 1 mention
                if ($weight < self::W_THRESHOLD && $mentions <= 1) {
                    error_log("ProfileBuilder sanitize: removing '{$item['name']}' (weight={$weight}, mentions={$mentions})");
                    continue;
                }

                // Remove if confidence below 0.2
                if ($confidence < 0.2) {
                    error_log("ProfileBuilder sanitize: removing '{$item['name']}' (confidence={$confidence})");
                    continue;
                }

                $cleaned[] = $item;
            }

            // Sort by weight desc
            usort($cleaned, fn($a, $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));
            
            $profile[$field] = $cleaned;
        }

        // Recalculate overall confidence
        $profile['profile_confidence_score'] = $this->calculateOverallConfidence($profile);
        $profile['last_sanitized'] = date('c');

        return $profile;
    }
}
