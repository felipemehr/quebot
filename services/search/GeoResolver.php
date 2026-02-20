<?php
/**
 * GeoResolver — Resolves zone qualifiers to concrete sectors using context query results.
 *
 * Takes context query results (from SerpAPI) about neighborhoods/city
 * and extracts concrete sector names that match the user's zone qualifier.
 *
 * Example:
 *   zona_texto: "sector alto"
 *   ciudad: "Temuco"
 *   context results mention: "Pueblo Nuevo", "Pedro de Valdivia", "Av. Alemania"
 *   → resolvedZones: { sectors: ["Pueblo Nuevo", "Pedro de Valdivia", "Av. Alemania"], ... }
 *
 * Also identifies excluded sectors (clearly NOT matching) for negative filtering.
 */
class GeoResolver {

    /**
     * Known sector associations for Chilean cities.
     * Fallback when search context doesn't provide enough info.
     *
     * Structure: city → qualifier → [sectors]
     */
    private static array $knownSectors = [
        'Temuco' => [
            'sector alto' => [
                'sectors' => ['Pueblo Nuevo', 'Pedro de Valdivia Norte', 'Av. Alemania', 'Avenida Alemania',
                              'Las Quilas', 'Labranza Alto', 'Portal Temuco', 'Nielol'],
                'excluded' => ['Amanecer', 'Santa Rosa', 'Pedro de Valdivia Sur', 'Labranza',
                               'Pueblo Nuevo Sur', 'Villa Los Creadores'],
            ],
            'sector exclusivo' => [
                'sectors' => ['Pueblo Nuevo', 'Av. Alemania', 'Las Quilas', 'Portal Temuco'],
                'excluded' => ['Amanecer', 'Santa Rosa', 'Labranza'],
            ],
        ],
        'Santiago' => [
            'sector alto' => [
                'sectors' => ['Las Condes', 'Vitacura', 'Lo Barnechea', 'Providencia',
                              'La Reina', 'Ñuñoa'],
                'excluded' => ['Puente Alto', 'La Pintana', 'San Bernardo', 'Maipú',
                               'El Bosque', 'Cerro Navia'],
            ],
            'sector exclusivo' => [
                'sectors' => ['Vitacura', 'Lo Barnechea', 'Las Condes'],
                'excluded' => ['Puente Alto', 'La Pintana', 'San Bernardo'],
            ],
        ],
        'Concepción' => [
            'sector alto' => [
                'sectors' => ['San Pedro de la Paz', 'Lomas de San Sebastián', 'Pedro de Valdivia',
                              'Lomas de San Andrés', 'Barrio Universitario'],
                'excluded' => ['Coronel', 'Lota', 'Hualpén centro'],
            ],
        ],
        'Valparaíso' => [
            'sector alto' => [
                'sectors' => ['Viña del Mar', 'Reñaca', 'Concón', 'Cerro Alegre', 'Cerro Concepción'],
                'excluded' => ['Placilla', 'Rodelillo'],
            ],
        ],
        'La Serena' => [
            'sector alto' => [
                'sectors' => ['Av. del Mar', 'Peñuelas', 'San Joaquín', 'Las Compañías Alto'],
                'excluded' => ['Las Compañías Bajo'],
            ],
        ],
        'Puerto Montt' => [
            'sector alto' => [
                'sectors' => ['Pelluco', 'Chamiza', 'Alerce Alto', 'Mirasol'],
                'excluded' => ['Alerce bajo', 'Población Modelo'],
            ],
        ],
    ];

    /**
     * Resolve zone qualifier into concrete sectors using context data.
     *
     * @param array $spec SearchSpec from IntentParser
     * @param array $contextResults Context search results (snippets about neighborhoods)
     * @return array{
     *   sectors: string[],
     *   excluded_sectors: string[],
     *   confidence: string,
     *   source: string,
     *   raw_context: string
     * }
     */
    public static function resolve(array $spec, array $contextResults = []): array {
        $ciudad = $spec['ubicacion'] ?? null;
        $zonaTxt = $spec['zona_texto'] ?? null;

        if (!$ciudad || !$zonaTxt) {
            return self::emptyResult('Sin ciudad o zona para resolver');
        }

        // Step 1: Try to extract sectors from context query results
        $extractedSectors = self::extractSectorsFromContext($contextResults, $ciudad, $zonaTxt);

        // Step 2: Merge with known sectors (fallback/enrichment)
        $knownData = self::getKnownSectors($ciudad, $zonaTxt);

        // Step 3: Combine — context results take priority, known data fills gaps
        $sectors = array_unique(array_merge(
            $extractedSectors['matching'],
            $knownData['sectors'] ?? []
        ));

        $excludedSectors = array_unique(array_merge(
            $extractedSectors['excluded'],
            $knownData['excluded'] ?? []
        ));

        // Remove any sector that appears in both lists (conflict → keep as matching)
        $excludedSectors = array_diff($excludedSectors, $sectors);

        // Determine confidence
        $confidence = 'baja';
        if (!empty($extractedSectors['matching']) && !empty($knownData['sectors'])) {
            $confidence = 'alta';  // Both sources agree
        } elseif (!empty($extractedSectors['matching']) || !empty($knownData['sectors'])) {
            $confidence = 'media';  // One source
        }

        // Build raw context summary for Claude
        $rawContext = self::buildContextSummary($contextResults);

        return [
            'sectors' => array_values($sectors),
            'excluded_sectors' => array_values($excludedSectors),
            'confidence' => $confidence,
            'source' => !empty($extractedSectors['matching']) ? 'context+known' : 'known',
            'ciudad' => $ciudad,
            'zona_texto' => $zonaTxt,
            'raw_context' => $rawContext,
        ];
    }

    /**
     * Extract sector names from context search results.
     */
    private static function extractSectorsFromContext(array $contextResults, string $ciudad, string $zonaTxt): array {
        $matching = [];
        $excluded = [];

        // Combine all context text
        $fullText = '';
        foreach ($contextResults as $cr) {
            $fullText .= ($cr['title'] ?? '') . ' ' . ($cr['snippet'] ?? '') . ' ';
        }
        $fullTextLower = mb_strtolower($fullText);

        // Common Chilean neighborhood/sector indicators
        $sectorPatterns = [
            // "sector X", "barrio X", "zona X"
            '/(?:sector|barrio|zona|villa|población|condominio|loteo)\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+(?:de\s+)?[A-ZÁÉÍÓÚÑ]?[a-záéíóúñ]+)*)/u',
            // "X es el barrio más exclusivo"
            '/([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+(?:de\s+)?[A-ZÁÉÍÓÚÑ]?[a-záéíóúñ]+)*)\s+(?:es|son|como)\s+(?:el|los|la|las)\s+(?:barrio|sector|zona|mejor|más)/u',
            // "vivir en X"
            '/vivir\s+en\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+(?:de\s+)?[A-ZÁÉÍÓÚÑ]?[a-záéíóúñ]+)*)/u',
            // "Av./Avenida X"
            '/(?:Av\.?|Avenida)\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ]?[a-záéíóúñ]+)*)/u',
        ];

        $candidates = [];
        foreach ($sectorPatterns as $pattern) {
            if (preg_match_all($pattern, $fullText, $matches)) {
                foreach ($matches[1] as $match) {
                    $clean = trim($match);
                    // Filter out common non-sector words
                    $skip = ['Chile', 'Región', 'País', 'Nacional', 'Todos', 'Según',
                             'También', 'Además', 'Pero', 'Sin', 'Con', 'Por', 'Para',
                             'Las', 'Los', 'Del', 'Que', 'Como', 'Más', 'Muy',
                             $ciudad]; // Skip the city name itself
                    if (mb_strlen($clean) > 2 && mb_strlen($clean) < 40 && !in_array($clean, $skip)) {
                        // Additional cleanup: skip if it looks like a sentence fragment
                        $wordCount = str_word_count($clean);
                        if ($wordCount <= 4) {
                            $candidates[] = $clean;
                        }
                    }
                }
            }
        }

        // Classify candidates based on zone qualifier context
        $premiumIndicators = ['alto', 'exclusiv', 'premium', 'mejor', 'residencial',
                              'segur', 'tranquil', 'universidad', 'privad'];
        $nonPremiumIndicators = ['popular', 'económic', 'social', 'villa', 'población',
                                  'periféri', 'industri', 'comerc'];

        foreach (array_unique($candidates) as $candidate) {
            // Check context around this candidate name
            $candidateLower = mb_strtolower($candidate);
            $pos = mb_strpos($fullTextLower, $candidateLower);
            if ($pos === false) continue;

            $contextWindow = mb_substr($fullTextLower, max(0, $pos - 100), 250);

            $isPremium = false;
            $isNonPremium = false;
            foreach ($premiumIndicators as $ind) {
                if (str_contains($contextWindow, $ind)) $isPremium = true;
            }
            foreach ($nonPremiumIndicators as $ind) {
                if (str_contains($contextWindow, $ind)) $isNonPremium = true;
            }

            // For "sector alto" qualifier, premium context → matching, non-premium → excluded
            if ($zonaTxt === 'sector alto' || $zonaTxt === 'sector exclusivo' || $zonaTxt === 'mejor zona') {
                if ($isPremium && !$isNonPremium) {
                    $matching[] = $candidate;
                } elseif ($isNonPremium && !$isPremium) {
                    $excluded[] = $candidate;
                } else {
                    // Ambiguous — include as matching (benefit of doubt)
                    $matching[] = $candidate;
                }
            } else {
                // For other qualifiers, all candidates are potentially matching
                $matching[] = $candidate;
            }
        }

        return [
            'matching' => array_values(array_unique($matching)),
            'excluded' => array_values(array_unique($excluded)),
        ];
    }

    /**
     * Get known sector data for a city + qualifier combination.
     */
    private static function getKnownSectors(string $ciudad, string $zonaTxt): array {
        // Try exact city match
        if (isset(self::$knownSectors[$ciudad][$zonaTxt])) {
            return self::$knownSectors[$ciudad][$zonaTxt];
        }

        // Try normalized city name
        $normalized = self::normalizeCity($ciudad);
        foreach (self::$knownSectors as $knownCity => $qualifiers) {
            if (self::normalizeCity($knownCity) === $normalized) {
                if (isset($qualifiers[$zonaTxt])) {
                    return $qualifiers[$zonaTxt];
                }
            }
        }

        return ['sectors' => [], 'excluded' => []];
    }

    /**
     * Normalize city name for comparison.
     */
    private static function normalizeCity(string $city): string {
        return mb_strtolower(strtr($city, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]));
    }

    /**
     * Build a text summary of context results for LLM context.
     */
    private static function buildContextSummary(array $contextResults): string {
        if (empty($contextResults)) return '';

        $parts = [];
        foreach ($contextResults as $cr) {
            $title = $cr['title'] ?? '';
            $snippet = $cr['snippet'] ?? '';
            if ($title || $snippet) {
                $parts[] = "{$title}: {$snippet}";
            }
        }
        return implode("\n", array_slice($parts, 0, 5));
    }

    /**
     * Get an empty result structure.
     */
    private static function emptyResult(string $reason): array {
        return [
            'sectors' => [],
            'excluded_sectors' => [],
            'confidence' => 'baja',
            'source' => 'none',
            'ciudad' => null,
            'zona_texto' => null,
            'raw_context' => '',
            'note' => $reason,
        ];
    }
}
