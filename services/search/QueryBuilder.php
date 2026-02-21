<?php
require_once __DIR__ . '/DomainPolicy.php';
require_once __DIR__ . '/IntentParser.php';

/**
 * QueryBuilder v2 — Builds controlled site: queries for real estate,
 * never sends raw user text as query.
 *
 * For real_estate:
 *   Uses IntentParser to extract structured intent, then builds
 *   targeted site: queries per portal with type+location+keywords.
 *
 * For other verticals:
 *   Cleans query and adds domain hints (unchanged from v1).
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
     * @return array{queries: string[], cleaned_query: string, vertical: string, intent: ?array}
     */
    public static function build(string $userMessage, string $vertical = 'auto'): array {
        if ($vertical === 'auto') {
            $vertical = DomainPolicy::detectVertical($userMessage);
        }

        // For real estate, use structured intent parser
        if ($vertical === 'real_estate') {
            return self::buildFromIntent($userMessage);
        }

        // Other verticals: clean + domain hints (v1 behavior)
        $cleaned = self::cleanQuery($userMessage, $vertical);

        $queries = match ($vertical) {
            'legal' => self::buildLegalQueries($cleaned),
            'news' => self::buildNewsQueries($cleaned),
            'retail' => self::buildRetailQueries($cleaned),
            default => self::buildGeneralQueries($cleaned),
        };

        return [
            'queries' => $queries,
            'context_queries' => [],
            'cleaned_query' => $cleaned,
            'vertical' => $vertical,
            'intent' => null,
        ];
    }

    /**
     * Build real estate queries from structured intent.
     * Uses site: operator targeting specific portals.
     *
     * Strategy (6-8 queries, budget-conscious):
     * A) site:portalinmobiliario.com → type + location
     * B) site:yapo.cl → type + location
     * C) site:toctoc.com → type + location
     * D) site:chilepropiedades.cl → type + location
     * E) site:goplaceit.com → type + location
     * F) Fallback: generic with portal hints (if above fail)
     *
     * Include accent variations for location.
     */
    private static function buildFromIntent(string $userMessage): array {
        $intent = IntentParser::parse($userMessage);
        $queries = [];

        $type = $intent['tipo_propiedad'] ?? 'propiedad';
        $location = $intent['ubicacion'] ?? '';
        $operation = $intent['operacion'] ?? 'venta';

        // Build base terms from intent (NOT raw user text)
        $baseTerms = [];
        $baseTerms[] = $type;
        $baseTerms[] = $operation;
        if ($location) {
            $baseTerms[] = $location;
        }

        // Add location qualifier to queries (barrio alto, sector exclusivo, etc.)
        $qualifier = $intent['location_qualifier_raw'] ?? $intent['location_qualifier'] ?? null;
        if ($qualifier) {
            $baseTerms[] = $qualifier;
        }

        // Add surface hint if present
        if ($intent['superficie']) {
            $s = $intent['superficie'];
            if ($s['unit'] === 'ha') {
                $baseTerms[] = $s['amount'] . ' hectáreas';
            } else {
                $baseTerms[] = number_format($s['amount'], 0, ',', '.') . ' m2';
            }
        }

        // Add must-have keywords (max 2 to not over-constrain)
        $mustHave = array_slice($intent['must_have'], 0, 2);
        foreach ($mustHave as $kw) {
            $baseTerms[] = $kw;
        }

        $baseQuery = implode(' ', $baseTerms);

        // Location variations for accent-insensitive queries
        $locationVariations = $location ? IntentParser::getLocationVariations($location) : [''];

        // === SITE: QUERIES ===
        // Tier A portals (high trust)
        $tierAPortals = [
            'portalinmobiliario.com',
            'toctoc.com',
            'goplaceit.com',
        ];

        // Tier B portals
        $tierBPortals = [
            'yapo.cl',
            'chilepropiedades.cl',
        ];

        // Optional portals (if we have query budget)
        $optionalPortals = [
            'propiedades.emol.com',
            'icasas.cl',
        ];

        // Build site: queries for Tier A (3 queries)
        foreach ($tierAPortals as $portal) {
            $q = "site:{$portal} {$type} {$operation}";
            if ($location) {
                $q .= " {$locationVariations[0]}";
            }
            if (!empty($mustHave)) {
                $q .= ' ' . implode(' ', array_slice($mustHave, 0, 1));
            }
            $queries[] = trim($q);
        }

        // Build site: queries for Tier B (2 queries)
        foreach ($tierBPortals as $portal) {
            $q = "site:{$portal} {$type} {$operation}";
            if ($location) {
                $q .= " {$locationVariations[0]}";
            }
            $queries[] = trim($q);
        }

        // Optional: accent variation query (1 query)
        if (count($locationVariations) > 1) {
            $altLocation = $locationVariations[1];
            $queries[] = "site:portalinmobiliario.com {$type} {$operation} {$altLocation}";
        }

        // Fallback: generic query with portal hints (1 query)
        // Only used if site: queries fail — includes all portals as hints
        $fallbackQuery = "{$type} {$operation}";
        if ($location) $fallbackQuery .= " {$locationVariations[0]}";
        if ($intent['superficie']) {
            $s = $intent['superficie'];
            $fallbackQuery .= ' ' . ($s['unit'] === 'ha' ? $s['amount'] . ' hectáreas' : $s['amount'] . ' m2');
        }
        $fallbackQuery .= ' portalinmobiliario.com yapo.cl toctoc.com';
        $queries[] = trim($fallbackQuery);

        // Limit to 8 queries max (SerpAPI budget)
        $queries = array_slice($queries, 0, 8);

        // Cleaned query for display/caching
        $cleaned = self::cleanQuery($userMessage, 'real_estate');

        return [
            'queries' => $queries,
            'context_queries' => self::buildContextQueries($intent),
            'cleaned_query' => $cleaned,
            'vertical' => 'real_estate',
            'intent' => $intent,
        ];
    }


    /**
     * Build context queries about neighborhoods/city for enriched search.
     * Runs in parallel with property queries to give Claude urban context.
     *
     * @return string[] Context search queries (max 2)
     */
    private static function buildContextQueries(array $intent): array {
        $location = $intent['ubicacion'] ?? null;
        if (!$location) return [];

        $queries = [];
        $qualifier = $intent['location_qualifier'] ?? null;

        // Query 1: Best neighborhoods for the qualifier
        if ($qualifier) {
            $qualifierTerms = match($qualifier) {
                'sector alto' => 'mejores barrios sector alto exclusivo',
                'sector exclusivo' => 'barrios exclusivos premium',
                'zona residencial' => 'mejores sectores residenciales',
                'condominio' => 'condominios cerrados exclusivos',
                'mejor zona' => 'mejores barrios para vivir',
                'zona segura' => 'barrios más seguros',
                default => 'mejores barrios sectores',
            };
            $queries[] = "{$qualifierTerms} {$location} Chile";
        } else {
            // Even without qualifier, get general neighborhood info
            $queries[] = "mejores barrios sectores {$location} Chile donde vivir";
        }

        // Query 2: Real estate price context for the area
        $type = $intent['tipo_propiedad'] ?? 'propiedad';
        $budget = '';
        if ($intent['presupuesto']) {
            $b = $intent['presupuesto'];
            $budget = " {$b['amount']} {$b['unit']}";
        }
        $queries[] = "precios {$type} {$location}{$budget} mercado inmobiliario Chile";

        return array_slice($queries, 0, 2);
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

    private static function buildLegalQueries(string $cleaned): array {
        $queries = [];
        $queries[] = $cleaned . ' leychile.cl bcn.cl';
        $queries[] = $cleaned . ' Chile legislación vigente';
        return $queries;
    }

    private static function buildNewsQueries(string $cleaned): array {
        $queries = [];
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
