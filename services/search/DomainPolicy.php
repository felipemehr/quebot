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

        // Financial / Currency — BEFORE real_estate to prevent UF/dólar misclassification
        $financialTerms = ['dólar', 'dolar', 'euro', 'tipo de cambio', 'divisa', 'moneda',
                           'cotización', 'cotizacion', 'cambio de', 'peso chileno', 'bitcoin',
                           'criptomoneda', 'tasa de interés', 'inflación', 'inflacion',
                           'bolsa de', 'acciones de', 'inversión en', 'inversion en'];
        $strongPropertyTerms = ['casa', 'depto', 'departamento', 'parcela', 'terreno', 'sitio',
                                'propiedad', 'lote', 'campo', 'inmueble', 'condominio',
                                'cabaña', 'cabana', 'hectárea', 'hectarea', 'fundo', 'chacra'];
        
        $hasFinancial = false;
        foreach ($financialTerms as $t) {
            if (str_contains($q, $t)) { $hasFinancial = true; break; }
        }
        
        $hasStrongProperty = false;
        foreach ($strongPropertyTerms as $t) {
            if (str_contains($q, $t)) { $hasStrongProperty = true; break; }
        }
        
        // If financial terms present WITHOUT strong property terms → financial
        if ($hasFinancial && !$hasStrongProperty) {
            return 'financial';
        }

        // Real estate — requires at least one strong property term
        // OR weak terms (uf, m2, dormitorio, venta, arriendo) combined with property context
        if ($hasStrongProperty) {
            return 'real_estate';
        }
        
        // Weak real estate terms — only classify as real_estate if no financial override
        $weakReTerms = [' uf ', 'dormitorio', 'm2', 'm²', 'hectáreas', 'arriendo'];
        $hasWeakRE = false;
        foreach ($weakReTerms as $t) {
            if (str_contains($q, $t)) { $hasWeakRE = true; break; }
        }
        
        // "venta" is ambiguous — "venta de casa" = RE, "venta de dólar" = financial
        if (str_contains($q, 'venta') && !$hasFinancial) {
            $hasWeakRE = true;
        }
        
        // Weak RE terms alone → real_estate (e.g., "uf hoy" → might be RE context)
        // But NOT if financial terms are present
        if ($hasWeakRE && !$hasFinancial) {
            return 'real_estate';
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
}
