<?php
/**
 * Domain whitelist and trust scoring per vertical.
 * Tier A = high trust (0.9), Tier B = medium (0.6), unlisted = low (0.2).
 */
class DomainPolicy {

    private static array $domains = [
        'legal' => [
            'A' => [
                'leychile.cl', 'bcn.cl', 'diariooficial.interior.gob.cl',
                'pjud.cl', 'contraloria.cl', 'sii.cl', 'tesoreria.cl',
                'minvu.gob.cl', 'mop.gob.cl', 'dga.mop.gob.cl',
            ],
            'B' => [],
        ],
        'real_estate' => [
            'A' => ['portalinmobiliario.com', 'toctoc.com', 'goplaceit.com'],
            'B' => [
                'yapo.cl', 'mercadolibre.cl', 'icasas.cl',
                'chilepropiedades.cl', 'mitula.cl', 'propiedades.emol.com', 'icasas.cl',
            ],
        ],
        'retail' => [
            'A' => [
                'solotodo.cl', 'falabella.com', 'paris.cl', 'ripley.cl',
                'sodimac.cl', 'spdigital.cl', 'pcfactory.cl', 'lider.cl', 'jumbo.cl',
            ],
            'B' => [],
        ],
        'news' => [
            'A' => [
                'latercera.com', 'emol.com', 'cooperativa.cl', 'biobiochile.cl',
                'cnnchile.com', '24horas.cl', 'df.cl',
            ],
            'B' => [],
        ],
    ];

    /**
     * Extract base domain from URL or domain string.
     */
    public static function extractDomain(string $urlOrDomain): string {
        $host = parse_url($urlOrDomain, PHP_URL_HOST);
        if (!$host) $host = $urlOrDomain;
        $host = strtolower(preg_replace('/^www\./', '', $host));
        return $host;
    }

    /**
     * Get tier for a domain in a vertical: 'A', 'B', or 'none'.
     */
    public static function getTier(string $urlOrDomain, string $vertical): string {
        $domain = self::extractDomain($urlOrDomain);
        $verticalDomains = self::$domains[$vertical] ?? [];

        foreach (['A', 'B'] as $tier) {
            $tierDomains = $verticalDomains[$tier] ?? [];
            foreach ($tierDomains as $d) {
                if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                    return $tier;
                }
            }
        }
        return 'none';
    }

    /**
     * Get trust score for domain in vertical context.
     * A=0.9, B=0.6, none=0.2
     */
    public static function getTrustScore(string $urlOrDomain, string $vertical): float {
        return match (self::getTier($urlOrDomain, $vertical)) {
            'A' => 0.9,
            'B' => 0.6,
            default => 0.2,
        };
    }

    /**
     * Auto-detect vertical from user query.
     */
    public static function detectVertical(string $query): string {
        $q = mb_strtolower($query);

        // Legal
        $legalTerms = ['ley ', 'código', 'codigo', 'artículo', 'articulo', 'decreto',
                       'norma', 'jurídic', 'juridic', 'legal', 'constitución', 'constitucion',
                       'reglamento', 'ordenanza', 'dfl ', 'dfl-'];
        foreach ($legalTerms as $t) {
            if (str_contains($q, $t)) return 'legal';
        }

        // Real estate
        $reTerms = ['casa', 'depto', 'departamento', 'parcela', 'terreno', 'sitio',
                    'propiedad', 'lote', 'campo', 'arriendo', 'inmueble', 'condominio',
                    'cabaña', 'hectárea', 'hectarea', ' ha ', ' uf ', 'dormitorio', 'venta', 'propiedad', 'm2', 'm²', 'hectáreas'];
        foreach ($reTerms as $t) {
            if (str_contains($q, $t)) return 'real_estate';
        }

        // News
        $newsTerms = ['noticia', 'hoy ', 'últimas', 'ultimas', 'reciente', 'periódico',
                      'periodico', 'prensa', 'diario', 'actualidad'];
        foreach ($newsTerms as $t) {
            if (str_contains($q, $t)) return 'news';
        }

        // Retail
        $retailTerms = ['comprar', 'precio', 'tienda', 'producto', 'oferta', 'descuento',
                        'notebook', 'celular', 'televisor', 'electrodoméstico', 'comparar precio'];
        foreach ($retailTerms as $t) {
            if (str_contains($q, $t)) return 'retail';
        }

        return 'general';
    }

    /**
     * Get site: filter string for a vertical (for query augmentation).
     * Returns sites for Tier A only.
     */
    public static function getSiteFilter(string $vertical): string {
        $tierA = self::$domains[$vertical]['A'] ?? [];
        if (empty($tierA)) return '';
        // Return top 3 sites for query augmentation
        $top = array_slice($tierA, 0, 3);
        return implode(' OR ', array_map(fn($d) => "site:$d", $top));
    }

    /**
     * Check if a URL belongs to any whitelisted domain in any vertical.
     */
    public static function isWhitelisted(string $urlOrDomain): bool {
        foreach (self::$domains as $vertical => $tiers) {
            if (self::getTier($urlOrDomain, $vertical) !== 'none') {
                return true;
            }
        }
        return false;
    }
}
