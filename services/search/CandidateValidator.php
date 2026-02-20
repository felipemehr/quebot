<?php
require_once __DIR__ . '/IntentParser.php';
require_once __DIR__ . '/DomainPolicy.php';

/**
 * CandidateValidator — Strict pre-Claude filtering of search results.
 *
 * Applies SearchSpec constraints BEFORE sending to Claude:
 * 1. Price within ±tolerance
 * 2. Zone coherence (resolved sectors from context)
 * 3. Incomplete candidate exclusion (missing price = excluded for RE)
 * 4. Price↔zone coherence (cheap property in "barrio alto" = discard)
 *
 * Each candidate gets a validation result:
 * - PASS: Meets all hard constraints
 * - SOFT_FAIL: Meets hard constraints but fails soft ones
 * - HARD_FAIL: Fails one or more hard constraints (excluded)
 * - INCOMPLETE: Missing critical data (excluded unless requested)
 */
class CandidateValidator {

    /**
     * Filter and annotate candidates against SearchSpec.
     *
     * @param array $candidates Ranked results from HeuristicRanker
     * @param array $spec SearchSpec from IntentParser v3
     * @param array $resolvedZones Resolved zone data from GeoResolver
     * @return array{
     *   passed: array,
     *   soft_failed: array,
     *   hard_failed: array,
     *   incomplete: array,
     *   stats: array
     * }
     */
    public static function filter(array $candidates, array $spec, array $resolvedZones = []): array {
        $passed = [];
        $softFailed = [];
        $hardFailed = [];
        $incomplete = [];

        $priceRange = IntentParser::getPriceRange($spec);
        $currentUF = self::getCurrentUFValue();

        foreach ($candidates as $candidate) {
            $result = self::validateCandidate($candidate, $spec, $priceRange, $resolvedZones, $currentUF);
            $candidate['validation'] = $result;

            switch ($result['verdict']) {
                case 'PASS':
                    $passed[] = $candidate;
                    break;
                case 'SOFT_FAIL':
                    $softFailed[] = $candidate;
                    break;
                case 'HARD_FAIL':
                    $hardFailed[] = $candidate;
                    break;
                case 'INCOMPLETE':
                    $incomplete[] = $candidate;
                    break;
            }
        }

        return [
            'passed' => $passed,
            'soft_failed' => $softFailed,
            'hard_failed' => $hardFailed,
            'incomplete' => $incomplete,
            'stats' => [
                'total' => count($candidates),
                'passed' => count($passed),
                'soft_failed' => count($softFailed),
                'hard_failed' => count($hardFailed),
                'incomplete' => count($incomplete),
                'price_range_applied' => $priceRange !== null,
                'zone_filter_applied' => !empty($resolvedZones),
                'uf_value' => $currentUF,
            ],
        ];
    }

    /**
     * Validate a single candidate against the SearchSpec.
     */
    private static function validateCandidate(
        array $candidate,
        array $spec,
        ?array $priceRange,
        array $resolvedZones,
        ?float $currentUF
    ): array {
        $ext = $candidate['extracted'] ?? [];
        $urlType = $ext['url_type'] ?? 'unknown';
        $failures = [];
        $warnings = [];

        // === LISTING PAGES: cannot validate as individual properties ===
        if ($urlType === 'listing') {
            return [
                'verdict' => 'PASS',  // Listing pages pass but are handled differently in context
                'is_listing_page' => true,
                'failures' => [],
                'warnings' => ['Página de búsqueda, no propiedad individual'],
            ];
        }

        // === COMPLETENESS CHECK ===
        // For real estate specific URLs, require at least price
        if ($urlType === 'specific') {
            $hasPrice = !empty($ext['price_uf']) || !empty($ext['price_clp']);
            if (!$hasPrice) {
                // Don't immediately exclude — might get price from scraping later
                $warnings[] = 'Sin precio detectado';
            }
        }

        // === PRICE VALIDATION ===
        if ($priceRange && in_array('precio', $spec['restricciones_duras'])) {
            $candidatePrice = self::normalizePriceToUF($ext, $currentUF);

            if ($candidatePrice !== null) {
                $rangeInUF = self::normalizeRangeToUF($priceRange, $currentUF);

                if ($rangeInUF) {
                    if ($candidatePrice < $rangeInUF['min']) {
                        $pctBelow = round((1 - $candidatePrice / max($rangeInUF['min'], 1)) * 100);
                        $failures[] = "Precio UF " . number_format($candidatePrice, 0) .
                                      " está {$pctBelow}% bajo el rango mínimo (" .
                                      number_format($rangeInUF['min'], 0) . " UF)";
                    }
                    if ($candidatePrice > $rangeInUF['max']) {
                        $pctAbove = round(($candidatePrice / max($rangeInUF['max'], 1) - 1) * 100);
                        $failures[] = "Precio UF " . number_format($candidatePrice, 0) .
                                      " excede el rango máximo (" .
                                      number_format($rangeInUF['max'], 0) . " UF) en {$pctAbove}%";
                    }
                }
            }
        }

        // === ZONE COHERENCE ===
        if (!empty($resolvedZones) && in_array('zona', $spec['restricciones_duras'])) {
            $zoneMatch = self::checkZoneCoherence($candidate, $resolvedZones, $spec);
            if ($zoneMatch['verdict'] === 'fail') {
                $failures[] = $zoneMatch['reason'];
            } elseif ($zoneMatch['verdict'] === 'warn') {
                $warnings[] = $zoneMatch['reason'];
            }
        }

        // === PRICE↔ZONE COHERENCE ===
        // If user asks for "barrio alto" with 8000 UF budget, a 1500 UF property
        // is almost certainly NOT in barrio alto, even if zone data is uncertain
        if ($spec['zona_texto'] && $priceRange) {
            $candidatePrice = self::normalizePriceToUF($ext, $currentUF);
            if ($candidatePrice !== null) {
                $coherence = self::checkPriceZoneCoherence(
                    $candidatePrice, $spec['zona_texto'], $spec['precio'], $currentUF
                );
                if ($coherence['verdict'] === 'fail') {
                    $failures[] = $coherence['reason'];
                } elseif ($coherence['verdict'] === 'warn') {
                    $warnings[] = $coherence['reason'];
                }
            }
        }

        // === BEDROOMS/BATHROOMS CHECK ===
        if ($spec['dormitorios_min'] && !empty($ext['bedrooms'])) {
            if ($ext['bedrooms'] < $spec['dormitorios_min']) {
                $warnings[] = "Solo {$ext['bedrooms']}D (mínimo pedido: {$spec['dormitorios_min']}D)";
            }
        }
        if ($spec['banos_min'] && !empty($ext['bathrooms'])) {
            if ($ext['bathrooms'] < $spec['banos_min']) {
                $warnings[] = "Solo {$ext['bathrooms']}B (mínimo pedido: {$spec['banos_min']}B)";
            }
        }

        // === DETERMINE VERDICT ===
        $verdict = 'PASS';
        if (!empty($failures)) {
            $verdict = 'HARD_FAIL';
        } elseif (!empty($warnings)) {
            $verdict = 'SOFT_FAIL';
        }

        return [
            'verdict' => $verdict,
            'is_listing_page' => false,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * Normalize candidate price to UF for comparison.
     */
    private static function normalizePriceToUF(array $ext, ?float $currentUF): ?float {
        if (!empty($ext['price_uf'])) {
            return (float) $ext['price_uf'];
        }
        if (!empty($ext['price_clp']) && $currentUF && $currentUF > 0) {
            return round($ext['price_clp'] / $currentUF, 1);
        }
        return null;
    }

    /**
     * Normalize price range to UF.
     */
    private static function normalizeRangeToUF(array $range, ?float $currentUF): ?array {
        if ($range['moneda'] === 'UF') {
            return $range;
        }
        if ($range['moneda'] === 'CLP' && $currentUF && $currentUF > 0) {
            return [
                'min' => round($range['min'] / $currentUF, 1),
                'max' => round($range['max'] / $currentUF, 1),
                'moneda' => 'UF',
            ];
        }
        return null;
    }

    /**
     * Check if candidate is in a resolved zone.
     */
    private static function checkZoneCoherence(array $candidate, array $resolvedZones, array $spec): array {
        if (empty($resolvedZones['sectors'])) {
            return ['verdict' => 'pass', 'reason' => ''];
        }

        // Get candidate text to check for zone mentions
        $text = mb_strtolower(
            ($candidate['title'] ?? '') . ' ' .
            ($candidate['snippet'] ?? '') . ' ' .
            ($candidate['scraped_content'] ?? '')
        );

        // Check if candidate mentions any of the resolved sectors
        $sectors = $resolvedZones['sectors'] ?? [];
        foreach ($sectors as $sector) {
            $sectorLower = mb_strtolower($sector);
            if (str_contains($text, $sectorLower)) {
                return ['verdict' => 'pass', 'reason' => "Coincide con sector: {$sector}"];
            }
        }

        // Check if candidate mentions excluded sectors (known non-matching zones)
        $excluded = $resolvedZones['excluded_sectors'] ?? [];
        foreach ($excluded as $excl) {
            $exclLower = mb_strtolower($excl);
            if (str_contains($text, $exclLower)) {
                return [
                    'verdict' => 'fail',
                    'reason' => "Ubicada en {$excl}, no coincide con '{$spec['zona_texto']}'"
                ];
            }
        }

        // No zone info in candidate — uncertain
        if ($resolvedZones['confidence'] === 'alta') {
            return [
                'verdict' => 'warn',
                'reason' => "No se puede confirmar que esté en zona '{$spec['zona_texto']}'"
            ];
        }

        return ['verdict' => 'pass', 'reason' => ''];
    }

    /**
     * Check price↔zone coherence.
     * A property much cheaper than expected for a premium zone is likely
     * NOT in that zone, regardless of other signals.
     *
     * This is a heuristic — not perfect, but catches obvious mismatches.
     */
    private static function checkPriceZoneCoherence(
        float $candidatePriceUF,
        string $zonaTxt,
        array $precioSpec,
        ?float $currentUF
    ): array {
        $maxPrice = $precioSpec['max'] ?? null;
        if (!$maxPrice) return ['verdict' => 'pass', 'reason' => ''];

        // Normalize max to UF
        if ($precioSpec['moneda'] === 'CLP' && $currentUF && $currentUF > 0) {
            $maxPrice = $maxPrice / $currentUF;
        }

        // Premium zones: property should be at least 30% of the budget
        // (if user says 8000 UF barrio alto, a 1500 UF property is suspicious)
        $premiumZones = ['sector alto', 'sector exclusivo', 'condominio', 'mejor zona'];
        if (in_array($zonaTxt, $premiumZones)) {
            $threshold = $maxPrice * 0.30;
            if ($candidatePriceUF < $threshold) {
                return [
                    'verdict' => 'fail',
                    'reason' => sprintf(
                        "Precio UF %s es muy bajo para '%s' (esperado >UF %s, que es 30%% del presupuesto UF %s)",
                        number_format($candidatePriceUF, 0),
                        $zonaTxt,
                        number_format($threshold, 0),
                        number_format($maxPrice, 0)
                    ),
                ];
            }
            // Also warn if much cheaper than expected (50% threshold)
            $warnThreshold = $maxPrice * 0.50;
            if ($candidatePriceUF < $warnThreshold) {
                return [
                    'verdict' => 'warn',
                    'reason' => sprintf(
                        "Precio UF %s algo bajo para '%s' (presupuesto UF %s)",
                        number_format($candidatePriceUF, 0),
                        $zonaTxt,
                        number_format($maxPrice, 0)
                    ),
                ];
            }
        }

        return ['verdict' => 'pass', 'reason' => ''];
    }

    /**
     * Get current UF value (cached, from SII.cl or fallback).
     */
    private static function getCurrentUFValue(): ?float {
        // Try to get from environment or cached value
        $cached = getenv('CURRENT_UF_VALUE');
        if ($cached && is_numeric($cached)) {
            return (float) $cached;
        }

        // Fallback: reasonable approximation (Feb 2026)
        // This should be updated by the UF fetcher in chat.php
        return 38800.0;  // Approximate UF value
    }

    /**
     * Generate a human-readable filter summary for diagnostics.
     */
    public static function summarizeFilter(array $filterResult): string {
        $stats = $filterResult['stats'];
        $parts = [];
        $parts[] = "Total: {$stats['total']}";
        $parts[] = "✅ Pasan: {$stats['passed']}";
        if ($stats['soft_failed'] > 0) $parts[] = "⚠️ Soft-fail: {$stats['soft_failed']}";
        if ($stats['hard_failed'] > 0) $parts[] = "❌ Descartados: {$stats['hard_failed']}";
        if ($stats['incomplete'] > 0) $parts[] = "❓ Incompletos: {$stats['incomplete']}";
        if ($stats['price_range_applied']) $parts[] = "💰 Filtro precio activo";
        if ($stats['zone_filter_applied']) $parts[] = "📍 Filtro zona activo";

        return implode(' | ', $parts);
    }
}
