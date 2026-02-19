<?php
require_once __DIR__ . '/DomainPolicy.php';

/**
 * Builds optimized search queries per vertical.
 * Cleans user input, expands abbreviations, generates multi-query sets.
 *
 * NOTE: Does NOT use site: operator — DuckDuckGo HTML doesn't support it.
 * Instead uses domain names as query hints.
 */
class QueryBuilder {

    /** Common property abbreviations */
    private static array $abbreviations = [
        '/\b1d\b/i' => '1 dormitorio',
        '/\b2d\b/i' => '2 dormitorios',
        '/\b3d\b/i' => '3 dormitorios',
        '/\b4d\b/i' => '4 dormitorios',
        '/\b5d\b/i' => '5 dormitorios',
        '/\b1b\b/i' => '1 baño',
        '/\b2b\b/i' => '2 baños',
        '/\b3b\b/i' => '3 baños',
        '/\b4b\b/i' => '4 baños',
    ];

    /** Phrases from users that pollute search queries */
    private static array $instructionNoise = [
        '/datos?\s*reales?/i',
        '/tabla\s+link/i',
        '/m[ií]nimo\s+\d+\s+propiedades?/i',
        '/caracter[ií]sticas?\s*(y\s+)?rating/i',
        '/val\s+m2\s+construido/i',
        '/m2\s+terreno/i',
        '/m2\s+casa/i',
        '/\+[\-\/]\s*\d+\s*uf/i',
        '/\brating\b/i',
        '/\benlace\b/i',
        '/\blink\b/i',
        '/\bcon\s+links?\b/i',
        '/\bpor favor\b/i',
        '/\bgracias\b/i',
        '/\bmuéstrame\b/i',
        '/\bmuestrame\b/i',
        '/\bbusca\s+/i',
        '/\bencuentra\s+/i',
    ];

    /**
     * Build search queries for a given user message and vertical.
     *
     * @return array{queries: string[], cleaned_query: string, vertical: string}
     */
    public static function build(string $userMessage, string $vertical = 'auto'): array {
        if ($vertical === 'auto') {
            $vertical = DomainPolicy::detectVertical($userMessage);
        }

        $cleaned = self::cleanQuery($userMessage, $vertical);

        $queries = match ($vertical) {
            'real_estate' => self::buildRealEstateQueries($cleaned),
            'legal' => self::buildLegalQueries($cleaned),
            'news' => self::buildNewsQueries($cleaned),
            'retail' => self::buildRetailQueries($cleaned),
            default => self::buildGeneralQueries($cleaned),
        };

        return [
            'queries' => $queries,
            'cleaned_query' => $cleaned,
            'vertical' => $vertical,
        ];
    }

    private static function cleanQuery(string $query, string $vertical): string {
        // Expand abbreviations (especially for real estate)
        if ($vertical === 'real_estate') {
            $query = preg_replace(
                array_keys(self::$abbreviations),
                array_values(self::$abbreviations),
                $query
            );
        }

        // Remove instruction noise
        $query = preg_replace(self::$instructionNoise, '', $query);

        // Clean whitespace and punctuation
        $query = preg_replace('/,\s*,/', ',', $query);
        $query = preg_replace('/\s+/', ' ', $query);
        $query = trim($query, ' ,.');

        return $query;
    }

    /**
     * Real estate queries — use portal names as search hints (no site: operator).
     */
    private static function buildRealEstateQueries(string $cleaned): array {
        $queries = [];

        // 2 queries max to stay within timeout budget (~7s each via SerpAPI)
        // Query 1: Top portals
        $queries[] = $cleaned . ' venta portalinmobiliario.com toctoc.com';

        // Query 2: Secondary portals
        $queries[] = $cleaned . ' venta yapo.cl chilepropiedades.cl goplaceit.com';

        return $queries;
    }

    private static function buildLegalQueries(string $cleaned): array {
        $queries = [];
        $queries[] = $cleaned . ' leychile.cl bcn.cl';
        $queries[] = $cleaned . ' Chile legislación vigente';
        return $queries;
    }

    private static function buildNewsQueries(string $cleaned): array {
        $queries = [];
        // Use news portals as hints
        $queries[] = $cleaned . ' latercera.com emol.com cooperativa.cl';
        $queries[] = $cleaned . ' Chile hoy';
        return $queries;
    }

    private static function buildRetailQueries(string $cleaned): array {
        $queries = [];
        $queries[] = $cleaned . ' precio Chile';
        $queries[] = $cleaned . ' solotodo.cl falabella.com';
        return $queries;
    }

    private static function buildGeneralQueries(string $cleaned): array {
        return [$cleaned . ' Chile'];
    }
}
