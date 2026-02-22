<?php
/**
 * ModeRouter — Lightweight Mode-of-Expert (MoE) Router
 * 
 * Classifies user queries into operational modes using rule-based matching.
 * Ensures domain isolation (e.g., real estate portals only in REAL_ESTATE_MODE).
 * 
 * Modes:
 *   REAL_ESTATE_MODE — Property search, buying, selling, renting
 *   FINANCIAL_MODE   — Currency, UF, indicators, investment metrics
 *   NEWS_MODE        — Current events, news, trending topics
 *   LEGAL_MODE       — Laws, regulations, legal questions
 *   DEV_MODE         — Programming, debugging, technical
 *   GENERAL_MODE     — Fallback for everything else
 * 
 * Prepared for future MoE routing with LLM classification.
 * 
 * @version 1.0.0 (Lab)
 * @date 2026-02-22
 */

class ModeRouter {
    
    public const REAL_ESTATE_MODE = 'REAL_ESTATE_MODE';
    public const FINANCIAL_MODE   = 'FINANCIAL_MODE';
    public const NEWS_MODE        = 'NEWS_MODE';
    public const LEGAL_MODE       = 'LEGAL_MODE';
    public const DEV_MODE         = 'DEV_MODE';
    public const GENERAL_MODE     = 'GENERAL_MODE';
    
    /**
     * Mode-specific configuration
     */
    private const MODE_CONFIG = [
        self::REAL_ESTATE_MODE => [
            'allow_property_portals' => true,
            'search_vertical' => 'real_estate',
            'max_results' => 10,
            'scrape_depth' => 5,
        ],
        self::FINANCIAL_MODE => [
            'allow_property_portals' => false,
            'search_vertical' => 'financial',
            'max_results' => 8,
            'scrape_depth' => 3,
        ],
        self::NEWS_MODE => [
            'allow_property_portals' => false,
            'search_vertical' => 'news',
            'max_results' => 8,
            'scrape_depth' => 3,
        ],
        self::LEGAL_MODE => [
            'allow_property_portals' => false,
            'search_vertical' => 'legal',
            'max_results' => 5,
            'scrape_depth' => 2,
        ],
        self::DEV_MODE => [
            'allow_property_portals' => false,
            'search_vertical' => 'dev',
            'max_results' => 5,
            'scrape_depth' => 2,
        ],
        self::GENERAL_MODE => [
            'allow_property_portals' => false,
            'search_vertical' => 'general',
            'max_results' => 8,
            'scrape_depth' => 3,
        ],
    ];

    /**
     * Keyword groups for each mode (scored by match count)
     */
    private const KEYWORD_RULES = [
        self::REAL_ESTATE_MODE => [
            'strong' => ['casa', 'departamento', 'depto', 'parcela', 'terreno', 'propiedad', 
                        'propiedades', 'inmueble', 'arriendo', 'arrendar', 'comprar casa',
                        'vender propiedad', 'inversión inmobiliaria', 'corredor',
                        'portalinmobiliario', 'toctoc', 'yapo', 'chilepropiedades',
                        'dormitorio', 'baño', 'estacionamiento', 'bodega',
                        'hipotecario', 'hipoteca', 'dividendo', 'pie'],
            'moderate' => ['comprar', 'vender', 'venta', 'buscar', 'sector', 'comuna',
                          'barrio', 'condominio', 'loteo', 'sitio', 'hectárea',
                          'tasación', 'tasar', 'plusvalía', 'rentabilidad'],
            'weak' => ['precio', 'metro', 'superficie', 'zona'],
        ],
        self::FINANCIAL_MODE => [
            'strong' => ['dólar', 'dollar', 'euro', 'bitcoin', 'btc', 'criptomoneda',
                        'tipo de cambio', 'divisa', 'forex', 'ipc', 'inflación',
                        'tasa de interés', 'banco central', 'indicadores económicos',
                        'imacec', 'pib'],
            'moderate' => ['uf ', 'valor uf', 'cotización', 'bolsa', 'acciones',
                          'inversión', 'rendimiento', 'mercado', 'economía'],
            'weak' => ['precio', 'valor', 'costo'],
        ],
        self::NEWS_MODE => [
            'strong' => ['noticias', 'noticia', 'actualidad', 'titular', 'titulares',
                        'qué pasó', 'que paso', 'breaking', 'último momento',
                        'prensa', 'diario', 'periódico', 'medio'],
            'moderate' => ['hoy', 'ayer', 'esta semana', 'reciente', 'tendencia',
                          'trending', 'acontecimiento', 'evento', 'crisis',
                          'escándalo', 'elecciones', 'gobierno'],
            'weak' => ['chile', 'mundo', 'internacional'],
        ],
        self::LEGAL_MODE => [
            'strong' => ['ley', 'artículo', 'código civil', 'código penal', 'decreto',
                        'legislación', 'jurídico', 'tribunal', 'juicio', 'demanda',
                        'contrato', 'escritura', 'notaría', 'conservador',
                        'copropiedad inmobiliaria', 'ley de arriendo', 'dfl',
                        'recurso de protección', 'amparo'],
            'moderate' => ['legal', 'derecho', 'normativa', 'regulación', 'constitución',
                          'abogado', 'procurador', 'fiscalía', 'ministerio público'],
            'weak' => ['permiso', 'trámite', 'multa'],
        ],
        self::DEV_MODE => [
            'strong' => ['código', 'programar', 'programación', 'bug', 'debug', 'error de código',
                        'refactor', 'deploy', 'deployment', 'api rest', 'endpoint',
                        'base de datos', 'database', 'query sql', 'migration'],
            'moderate' => ['php', 'javascript', 'python', 'html', 'css', 'react', 'vue',
                          'firebase', 'railway', 'cors', 'sql', 'json', 'xml',
                          'git', 'commit', 'branch', 'merge', 'docker'],
            'weak' => ['función', 'variable', 'servidor', 'hosting'],
        ],
    ];

    /**
     * Route a user message to the appropriate mode
     * 
     * @param string $message User's message
     * @param string|null $searchVertical Detected search vertical (from DomainPolicy)
     * @param bool $hasLegalContext Whether legal search returned results
     * @return array ['mode' => string, 'confidence' => float, 'scores' => array, 'config' => array]
     */
    public static function route(string $message, ?string $searchVertical = null, bool $hasLegalContext = false): array {
        $messageLower = mb_strtolower(trim($message));
        
        // Score each mode
        $scores = [];
        foreach (self::KEYWORD_RULES as $mode => $groups) {
            $score = 0;
            foreach ($groups['strong'] as $kw) {
                if (str_contains($messageLower, $kw)) $score += 3;
            }
            foreach ($groups['moderate'] as $kw) {
                if (str_contains($messageLower, $kw)) $score += 2;
            }
            foreach ($groups['weak'] as $kw) {
                if (str_contains($messageLower, $kw)) $score += 1;
            }
            $scores[$mode] = $score;
        }

        // Boost based on search vertical detection
        if ($searchVertical === 'real_estate') {
            $scores[self::REAL_ESTATE_MODE] += 4;
        } elseif ($searchVertical === 'news') {
            $scores[self::NEWS_MODE] += 4;
        } elseif ($searchVertical === 'financial') {
            $scores[self::FINANCIAL_MODE] += 4;
        }
        
        // Boost legal if context was found
        if ($hasLegalContext) {
            $scores[self::LEGAL_MODE] += 3;
        }

        // Find winner
        arsort($scores);
        $topMode = array_key_first($scores);
        $topScore = $scores[$topMode];
        
        // If top score is 0 → GENERAL_MODE
        if ($topScore === 0) {
            $topMode = self::GENERAL_MODE;
        }
        
        // Calculate confidence (0-1)
        $totalScore = array_sum($scores);
        $confidence = $totalScore > 0 ? round($topScore / $totalScore, 2) : 0;
        
        // Disambiguation: if FINANCIAL and REAL_ESTATE are close, check for contextual clues
        if (abs(($scores[self::REAL_ESTATE_MODE] ?? 0) - ($scores[self::FINANCIAL_MODE] ?? 0)) <= 2) {
            // "Valor UF" without property terms → financial
            if (preg_match('/\buf\b/i', $messageLower) && !preg_match('/casa|depto|parcela|propiedad/i', $messageLower)) {
                $topMode = self::FINANCIAL_MODE;
            }
        }

        return [
            'mode' => $topMode,
            'confidence' => $confidence,
            'scores' => $scores,
            'config' => self::MODE_CONFIG[$topMode] ?? self::MODE_CONFIG[self::GENERAL_MODE],
        ];
    }

    /**
     * Check if a domain is allowed in the given mode
     */
    public static function isDomainAllowed(string $mode, string $domain): bool {
        $propertyPortals = [
            'portalinmobiliario.com',
            'www.portalinmobiliario.com',
            'yapo.cl',
            'www.yapo.cl',
            'toctoc.com',
            'www.toctoc.com',
            'chilepropiedades.cl',
            'www.chilepropiedades.cl',
            'goplaceit.com',
            'www.goplaceit.com',
            'inmueble.mercadolibre.cl',
        ];

        $config = self::MODE_CONFIG[$mode] ?? self::MODE_CONFIG[self::GENERAL_MODE];
        
        if (!$config['allow_property_portals']) {
            foreach ($propertyPortals as $portal) {
                if ($domain === $portal || str_ends_with($domain, '.' . $portal)) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Get mode label in Spanish for display
     */
    public static function getModeLabel(string $mode): string {
        return match($mode) {
            self::REAL_ESTATE_MODE => 'Propiedades',
            self::FINANCIAL_MODE   => 'Mercados',
            self::NEWS_MODE        => 'Noticias',
            self::LEGAL_MODE       => 'Legal',
            self::DEV_MODE         => 'Código',
            self::GENERAL_MODE     => 'General',
            default                => 'Desconocido',
        };
    }

    /**
     * Get all available modes
     */
    public static function allModes(): array {
        return [
            self::REAL_ESTATE_MODE,
            self::FINANCIAL_MODE,
            self::NEWS_MODE,
            self::LEGAL_MODE,
            self::DEV_MODE,
            self::GENERAL_MODE,
        ];
    }
}
