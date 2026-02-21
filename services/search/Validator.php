<?php
/**
 * Validates and enriches search results with extracted structured data.
 * Extracts: price_clp, price_uf, area_m2, price_per_m2, url_type.
 */
class Validator {

    /**
     * Extract structured data from a single search result.
     * Works on title + snippet text.
     *
     * @return array Enriched result with 'extracted' key
     */
    public static function extract(array $result): array {
        $text = ($result['title'] ?? '') . ' ' . ($result['snippet'] ?? '');
        $text .= ' ' . ($result['scraped_content'] ?? '');

        $extracted = [
            'price_clp' => self::extractPriceCLP($text),
            'price_uf' => self::extractPriceUF($text),
            'area_m2' => self::extractArea($text),
            'price_per_m2' => null,
            'url_type' => self::classifyUrl($result['url'] ?? ''),
            'bedrooms' => self::extractBedrooms($text),
            'bathrooms' => self::extractBathrooms($text),
            'validated_field_count' => 0,
        ];

        // Calculate price_per_m2 ONLY if both price and area are explicit
        if ($extracted['area_m2'] !== null && $extracted['area_m2'] > 0) {
            if ($extracted['price_clp'] !== null) {
                $extracted['price_per_m2'] = round($extracted['price_clp'] / $extracted['area_m2']);
            }
        }

        // Count validated fields
        $count = 0;
        if ($extracted['price_clp'] !== null || $extracted['price_uf'] !== null) $count++;
        if ($extracted['area_m2'] !== null) $count++;
        if ($extracted['bedrooms'] !== null) $count++;
        if ($extracted['url_type'] === 'specific') $count++;
        $extracted['validated_field_count'] = $count;

        $result['extracted'] = $extracted;
        return $result;
    }

    /**
     * Extract CLP price from text. Patterns: $120.000.000, 120 millones, etc.
     */
    private static function extractPriceCLP(?string $text): ?int {
        if (!$text) return null;

        // Pattern: $120.000.000 or $ 120.000.000
        if (preg_match('/\$\s?([\d]{1,3}(?:\.[\d]{3}){2,3})/', $text, $m)) {
            return (int) str_replace('.', '', $m[1]);
        }

        // Pattern: 120 millones or 120M
        if (preg_match('/([\d]+(?:[\.,]\d+)?)\s*millones/i', $text, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return (int) ($val * 1000000);
        }

        return null;
    }

    /**
     * Extract UF price. Patterns: UF 5.900, 5900 UF, UF5.900
     */
    private static function extractPriceUF(?string $text): ?float {
        if (!$text) return null;

        // Pattern: UF 5.900 or UF 5900 or UF5.900
        if (preg_match('/UF\s?([\d]{1,3}(?:\.[\d]{3})*(?:,\d+)?)/i', $text, $m)) {
            $val = str_replace('.', '', $m[1]);
            $val = str_replace(',', '.', $val);
            return (float) $val;
        }

        // Pattern: 5.900 UF or 5900 UF
        if (preg_match('/([\d]{1,3}(?:\.[\d]{3})*(?:,\d+)?)\s*UF/i', $text, $m)) {
            $val = str_replace('.', '', $m[1]);
            $val = str_replace(',', '.', $val);
            return (float) $val;
        }

        return null;
    }

    /**
     * Extract area in m². Handles m², ha (hectáreas), cuadras.
     */
    private static function extractArea(?string $text): ?float {
        if (!$text) return null;

        // Pattern: 7.250 m² or 7250 m2 or 7.250m²
        if (preg_match('/([\d]{1,3}(?:\.[\d]{3})*(?:,\d+)?)\s*m[²2]/i', $text, $m)) {
            $val = str_replace('.', '', $m[1]);
            $val = str_replace(',', '.', $val);
            return (float) $val;
        }

        // Pattern: 131 ha or 131 hectáreas (convert to m²)
        if (preg_match('/([\d]+(?:[\.,]\d+)?)\s*(?:ha|hect[áa]reas?)/i', $text, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return $val * 10000;
        }

        return null;
    }

    /**
     * Extract bedroom count from text.
     */
    private static function extractBedrooms(?string $text): ?int {
        if (!$text) return null;
        if (preg_match('/(\d+)\s*(?:dormitorio|dorm|pieza|habitaci[oó]n)/i', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*[dD]\b/', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Extract bathroom count from text.
     */
    private static function extractBathrooms(?string $text): ?int {
        if (!$text) return null;
        if (preg_match('/(\d+)\s*(?:baño|bath)/i', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*[bB]\b/', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Classify URL as 'specific' (individual property) or 'listing' (search/category page).
     * v2: Stronger listing detection — if URL has NO numeric ID, it's almost certainly a listing page.
     */
    public static function classifyUrl(string $url): string {
        $urlLower = strtolower($url);
        $path = parse_url($urlLower, PHP_URL_PATH) ?? '';

        // === LISTING PATTERNS (check FIRST — more common in search results) ===

        // Pagination pages are ALWAYS listings
        if (preg_match('/_Desde_\d+/', $url) || preg_match('/[?&]page=\d+/', $url)) {
            return 'listing';
        }

        // Category/filter paths without numeric IDs
        // e.g. /venta/casa/araucania/temuco or /venta/casa/propiedades-usadas/araucania/temuco
        $listingPathPatterns = [
            '/^\/(venta|arriendo|comprar|alquiler)\/(casa|departamento|parcela|terreno|sitio|propiedad)s?\//i',
            '/\/(buscar|search|results|listings|resultados)(\/|$)/',
            '/\/(parcelas|casas|departamentos|terrenos|propiedades|avisos)(\/|\?|$)/',
        ];
        foreach ($listingPathPatterns as $p) {
            if (preg_match($p, $path)) {
                // But if it ALSO has a numeric ID at the end, it might be specific
                if (preg_match('/\/\d{5,}(\/|$|\?)/', $path) || preg_match('/[-_]\d{6,}/', $path)) {
                    return 'specific'; // Has property ID → specific
                }
                return 'listing'; // No ID → listing page
            }
        }

        // Generic category/region paths
        if (preg_match('/\/(category|region|comuna|sector)\//i', $path)) {
            return 'listing';
        }

        // === SPECIFIC PROPERTY PATTERNS ===

        $specificPatterns = [
            '/\/(propiedad|property|ficha|detalle|aviso|publicacion|inmueble)\//i',
            '/\/[A-Z0-9]{5,}\/?$/i',  // Hash-like IDs
            '/[?&]id=\d+/',
            '/\/\d{6,}(\/|$)/',       // Numeric ID in path (6+ digits)
            '/[-_]\d{6,}/',             // ID after dash/underscore
            '/-(casa|depto|departamento|parcela|terreno|sitio)-.*-\d{4,}/',
            '/\/MLC-\d+/',             // MercadoLibre pattern
        ];
        foreach ($specificPatterns as $p) {
            if (preg_match($p, $urlLower)) return 'specific';
        }

        // If URL ends with a numeric segment (property ID), likely specific
        if (preg_match('/\/\d{4,}\/?$/', $path)) {
            return 'specific';
        }

        return 'unknown';
    }
}
