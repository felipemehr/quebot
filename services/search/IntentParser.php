<?php
/**
 * IntentParser v3 — Produces a formal SearchSpec JSON for real estate queries.
 *
 * Output SearchSpec:
 * {
 *   "tipo_propiedad": "casa|depto|parcela|terreno|...|unknown",
 *   "operacion": "venta|arriendo",
 *   "ubicacion": "Temuco",
 *   "ubicacion_raw": "temuco",
 *   "zona_texto": "barrio alto|sector norte|centro|...|null",
 *   "precio": { "min": null, "max": 8000, "moneda": "UF", "raw": "8000 uf" },
 *   "tolerancia_precio_pct": 12,
 *   "dormitorios_min": int|null,
 *   "banos_min": int|null,
 *   "m2_construidos_min": null,
 *   "m2_terreno_min": null,
 *   "superficie": { "amount": 5000, "unit": "m2", "m2": 5000 },
 *   "must_have": ["agua", "quincho"],
 *   "contexto": {
 *     "urbano_rural": "urbano|rural|unknown",
 *     "prioridad": ["ubicacion","precio","seguridad",...]
 *   },
 *   "restricciones_duras": ["precio","zona","tipo_propiedad"],
 *   "restricciones_blandas": ["vista","piscina"],
 *   "confidence": 0.75,
 *   "fallback_questions": []
 * }
 */
class IntentParser {

    /** Property type synonyms → canonical type */
    private static array $propertyTypes = [
        'parcela'       => 'parcela',
        'parcelas'      => 'parcela',
        'terreno'       => 'terreno',
        'terrenos'      => 'terreno',
        'lote'          => 'terreno',
        'lotes'         => 'terreno',
        'sitio'         => 'sitio',
        'sitios'        => 'sitio',
        'casa'          => 'casa',
        'casas'         => 'casa',
        'cabaña'        => 'casa',
        'cabana'        => 'casa',
        'cabañas'       => 'casa',
        'depto'         => 'departamento',
        'departamento'  => 'departamento',
        'departamentos' => 'departamento',
        'dptos'         => 'departamento',
        'local'         => 'local',
        'locales'       => 'local',
        'bodega'        => 'bodega',
        'bodegas'       => 'bodega',
        'campo'         => 'campo',
        'campos'        => 'campo',
        'fundo'         => 'campo',
        'fundos'        => 'campo',
        'chacra'        => 'campo',
        'hectárea'      => 'parcela',
        'hectareas'     => 'parcela',
        'oficina'       => 'oficina',
        'oficinas'      => 'oficina',
    ];

    /** Chilean comunas/cities — normalized (lowercase, no accents) → display name */
    private static array $locations = [
        // Araucanía
        'temuco' => 'Temuco', 'padre las casas' => 'Padre Las Casas',
        'villarrica' => 'Villarrica', 'pucon' => 'Pucón', 'pucón' => 'Pucón',
        'melipeuco' => 'Melipeuco', 'cunco' => 'Cunco',
        'curacautin' => 'Curacautín', 'curacautín' => 'Curacautín',
        'lonquimay' => 'Lonquimay', 'victoria' => 'Victoria',
        'angol' => 'Angol', 'collipulli' => 'Collipulli',
        'lautaro' => 'Lautaro', 'nueva imperial' => 'Nueva Imperial',
        'carahue' => 'Carahue', 'pitrufquén' => 'Pitrufquén',
        'pitrufquen' => 'Pitrufquén', 'freire' => 'Freire',
        'gorbea' => 'Gorbea', 'loncoche' => 'Loncoche',
        // Los Ríos
        'valdivia' => 'Valdivia', 'los lagos' => 'Los Lagos',
        'panguipulli' => 'Panguipulli', 'mariquina' => 'Mariquina',
        'la unión' => 'La Unión', 'la union' => 'La Unión',
        'rio bueno' => 'Río Bueno', 'río bueno' => 'Río Bueno',
        'futrono' => 'Futrono', 'lago ranco' => 'Lago Ranco',
        // Los Lagos
        'puerto montt' => 'Puerto Montt', 'osorno' => 'Osorno',
        'puerto varas' => 'Puerto Varas', 'frutillar' => 'Frutillar',
        'llanquihue' => 'Llanquihue', 'castro' => 'Castro',
        'ancud' => 'Ancud', 'calbuco' => 'Calbuco',
        'hualaihue' => 'Hualaihúé', 'hualaihúé' => 'Hualaihúé',
        'hornopirén' => 'Hornopirén', 'hornopiren' => 'Hornopirén',
        'chaitén' => 'Chaitén', 'chaiten' => 'Chaitén',
        // RM
        'santiago' => 'Santiago', 'providencia' => 'Providencia',
        'las condes' => 'Las Condes', 'ñuñoa' => 'Ñuñoa', 'nunoa' => 'Ñuñoa',
        'vitacura' => 'Vitacura', 'lo barnechea' => 'Lo Barnechea',
        'la reina' => 'La Reina', 'peñalolén' => 'Peñalolén', 'penalolen' => 'Peñalolén',
        'macul' => 'Macul', 'la florida' => 'La Florida',
        'puente alto' => 'Puente Alto', 'maipú' => 'Maipú', 'maipu' => 'Maipú',
        'san bernardo' => 'San Bernardo', 'colina' => 'Colina',
        'chicureo' => 'Chicureo', 'buin' => 'Buin', 'paine' => 'Paine',
        'talagante' => 'Talagante', 'peñaflor' => 'Peñaflor', 'penaflor' => 'Peñaflor',
        'melipilla' => 'Melipilla', 'isla de maipo' => 'Isla de Maipo',
        // Valparaíso
        'valparaíso' => 'Valparaíso', 'valparaiso' => 'Valparaíso',
        'viña del mar' => 'Viña del Mar', 'vina del mar' => 'Viña del Mar',
        'con con' => 'Concón', 'concón' => 'Concón', 'concon' => 'Concón',
        'quilpué' => 'Quilpué', 'quilpue' => 'Quilpué',
        'villa alemana' => 'Villa Alemana', 'quillota' => 'Quillota',
        'la calera' => 'La Calera', 'limache' => 'Limache',
        'olmué' => 'Olmué', 'olmue' => 'Olmué',
        'san antonio' => 'San Antonio', 'algarrobo' => 'Algarrobo',
        'el quisco' => 'El Quisco', 'el tabo' => 'El Tabo',
        'cartagena' => 'Cartagena', 'santo domingo' => 'Santo Domingo',
        // Biobío
        'concepción' => 'Concepción', 'concepcion' => 'Concepción',
        'talcahuano' => 'Talcahuano', 'chiguayante' => 'Chiguayante',
        'san pedro de la paz' => 'San Pedro de la Paz',
        'los ángeles' => 'Los Ángeles', 'los angeles' => 'Los Ángeles',
        'chillán' => 'Chillán', 'chillan' => 'Chillán',
        // O'Higgins
        'rancagua' => 'Rancagua', 'machalí' => 'Machalí', 'machali' => 'Machalí',
        'san fernando' => 'San Fernando', 'santa cruz' => 'Santa Cruz',
        'pichilemu' => 'Pichilemu',
        // Maule
        'talca' => 'Talca', 'curicó' => 'Curicó', 'curico' => 'Curicó',
        'linares' => 'Linares', 'constitución' => 'Constitución',
        'constitucion' => 'Constitución',
        // Coquimbo
        'la serena' => 'La Serena', 'coquimbo' => 'Coquimbo',
        'ovalle' => 'Ovalle', 'vicuña' => 'Vicuña', 'vicuna' => 'Vicuña',
        // Norte
        'antofagasta' => 'Antofagasta', 'iquique' => 'Iquique',
        'arica' => 'Arica', 'calama' => 'Calama',
        'copiapó' => 'Copiapó', 'copiapo' => 'Copiapó',
        // Sur extremo
        'punta arenas' => 'Punta Arenas', 'coyhaique' => 'Coyhaique',
        'puerto natales' => 'Puerto Natales',
    ];

    /** Keywords that indicate must-have features */
    private static array $featureKeywords = [
        'agua' => 'agua', 'agua potable' => 'agua potable',
        'luz' => 'luz', 'electricidad' => 'electricidad',
        'camino' => 'camino', 'acceso pavimentado' => 'acceso pavimentado',
        'orilla lago' => 'orilla de lago', 'orilla de lago' => 'orilla de lago',
        'orilla río' => 'orilla de río', 'orilla rio' => 'orilla de río',
        'orilla de rio' => 'orilla de río', 'orilla de río' => 'orilla de río',
        'vista al mar' => 'vista al mar', 'vista mar' => 'vista al mar',
        'piscina' => 'piscina', 'estacionamiento' => 'estacionamiento',
        'bodega' => 'bodega', 'quincho' => 'quincho',
        'locomoción' => 'locomoción', 'locomocion' => 'locomoción',
        'cerca del centro' => 'cerca del centro',
        'rol propio' => 'rol propio', 'escritura' => 'escritura',
        'factibilidad' => 'factibilidad',
        'bosque' => 'bosque nativo', 'bosque nativo' => 'bosque nativo',
        'río' => 'río', 'rio' => 'río', 'lago' => 'lago', 'playa' => 'playa',
    ];

    /** Zone qualifier patterns → label + implied context */
    private static array $qualifierPatterns = [
        '/\\b(?:barrio|sector)\\s+alto\\b/i' => [
            'label' => 'sector alto',
            'implies_urban' => true,
            'priority' => ['ubicacion', 'seguridad', 'conectividad'],
            'hard_constraint' => true,
        ],
        '/\\b(?:barrio|sector)\\s+(?:exclusiv|premium|residencial)\\w*\\b/i' => [
            'label' => 'sector exclusivo',
            'implies_urban' => true,
            'priority' => ['ubicacion', 'seguridad'],
            'hard_constraint' => true,
        ],
        '/\\b(?:barrio|zona)\\s+(?:tranquil|residencial)\\w*\\b/i' => [
            'label' => 'zona residencial',
            'implies_urban' => true,
            'priority' => ['ubicacion', 'seguridad'],
            'hard_constraint' => false,
        ],
        '/\\bcondominio\\s*(?:cerrado)?\\b/i' => [
            'label' => 'condominio',
            'implies_urban' => true,
            'priority' => ['seguridad', 'ubicacion'],
            'hard_constraint' => true,
        ],
        '/\\b(?:sector|barrio)\\s+(oriente|poniente|norte|sur|centro)\\b/i' => [
            'label' => 'sector orientación',
            'implies_urban' => true,
            'priority' => ['ubicacion'],
            'hard_constraint' => false,
        ],
        '/\\b(?:buena|mejor)(?:es)?\\s+(?:zona|barrio|sector)\\b/i' => [
            'label' => 'mejor zona',
            'implies_urban' => true,
            'priority' => ['ubicacion', 'seguridad', 'colegios'],
            'hard_constraint' => false,
        ],
        '/\\b(?:zona|sector)\\s+(?:segur|tranquil)\\w*\\b/i' => [
            'label' => 'zona segura',
            'implies_urban' => true,
            'priority' => ['seguridad', 'ubicacion'],
            'hard_constraint' => false,
        ],
        '/\\b(?:cerca\\s+del?\\s+centro|céntric|centrico)\\b/i' => [
            'label' => 'céntrico',
            'implies_urban' => true,
            'priority' => ['conectividad', 'ubicacion'],
            'hard_constraint' => false,
        ],
        '/\\b(?:rural|campo|parcela\\s+de\\s+agrado|fuera\\s+de\\s+la\\s+ciudad)\\b/i' => [
            'label' => 'rural',
            'implies_urban' => false,
            'priority' => ['precio', 'ubicacion'],
            'hard_constraint' => false,
        ],
    ];

    /** Soft constraint feature keywords (nice-to-have, not deal-breakers) */
    private static array $softConstraintFeatures = [
        'piscina', 'quincho', 'vista al mar', 'bosque nativo',
        'orilla de lago', 'orilla de río', 'estacionamiento',
    ];

    /**
     * Parse user query into formal SearchSpec.
     *
     * @return array SearchSpec JSON structure
     */
    public static function parse(string $query): array {
        $q = mb_strtolower(trim($query));
        $q = preg_replace('/\\s+/', ' ', $q);

        $spec = [
            // Core fields
            'tipo_propiedad' => null,
            'operacion' => 'venta',
            'ubicacion' => null,
            'ubicacion_raw' => null,
            'zona_texto' => null,
            'zona_texto_raw' => null,

            // Price with range support
            'precio' => null,  // { min, max, moneda, raw }
            'tolerancia_precio_pct' => 12,  // default ±12%

            // Physical specs
            'dormitorios_min' => null,
            'banos_min' => null,
            'm2_construidos_min' => null,
            'm2_terreno_min' => null,
            'superficie' => null,  // backward compat { amount, unit, m2 }

            // Features
            'must_have' => [],

            // Context & constraints
            'contexto' => [
                'urbano_rural' => 'unknown',
                'prioridad' => [],
            ],
            'restricciones_duras' => [],
            'restricciones_blandas' => [],

            // Metadata
            'confidence' => 0.0,
            'fallback_questions' => [],

            // Backward compat aliases
            'presupuesto' => null,
            'location_qualifier' => null,
            'location_qualifier_raw' => null,
        ];

        // --- Operación ---
        if (preg_match('/\\b(arriendo|arrienda|alquil|rent)\\b/i', $q)) {
            $spec['operacion'] = 'arriendo';
        }

        // --- Tipo de propiedad ---
        foreach (self::$propertyTypes as $keyword => $canonical) {
            if (str_contains($q, $keyword)) {
                $spec['tipo_propiedad'] = $canonical;
                $spec['restricciones_duras'][] = 'tipo_propiedad';
                break;
            }
        }

        // --- Ubicación ---
        $sortedLocations = self::$locations;
        uksort($sortedLocations, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($sortedLocations as $key => $display) {
            if (str_contains($q, $key)) {
                $spec['ubicacion'] = $display;
                $spec['ubicacion_raw'] = $key;
                break;
            }
        }

        // Fallback: "en <word>" pattern
        if (!$spec['ubicacion'] && preg_match('/\\ben\\s+([a-záéíóúñü]+(?:\\s+(?:de\\s+)?[a-záéíóúñü]+)*)/u', $q, $m)) {
            $candidate = trim($m[1]);
            $nonLocations = ['venta', 'arriendo', 'oferta', 'el', 'la', 'los', 'las', 'un', 'una', 'portalinmobiliario', 'yapo', 'toctoc'];
            if (!in_array($candidate, $nonLocations) && mb_strlen($candidate) > 2) {
                $spec['ubicacion'] = ucwords($candidate);
                $spec['ubicacion_raw'] = $candidate;
            }
        }

        // --- Presupuesto / Precio ---
        $precioDetected = false;

        // Price RANGE: "5000-8000 UF", "entre 5000 y 8000 UF"
        if (preg_match('/(?:entre\\s+)?(\\d+(?:\\.\\d{3})*(?:,\\d+)?)\\s*(?:a|-|y)\\s*(\\d+(?:\\.\\d{3})*(?:,\\d+)?)\\s*uf\\b/i', $q, $m)) {
            $min = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $max = (float) str_replace(['.', ','], ['', '.'], $m[2]);
            $spec['precio'] = ['min' => $min, 'max' => $max, 'moneda' => 'UF', 'raw' => $m[0]];
            $spec['tolerancia_precio_pct'] = 5; // explicit range = tight tolerance
            $spec['presupuesto'] = ['amount' => $max, 'unit' => 'UF', 'raw' => $m[0]];
            $precioDetected = true;
        }
        // Single UF: "8000 uf", "hasta 8000uf"
        elseif (preg_match('/(?:(?:hasta|max|máx|máximo|tope)\\s+)?(\\d+(?:\\.\\d{3})*(?:,\\d+)?)\\s*uf\\b/i', $q, $m)) {
            $val = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $hasMax = preg_match('/\\b(hasta|max|máx|máximo|tope)\\b/i', $q);
            $spec['precio'] = ['min' => null, 'max' => $val, 'moneda' => 'UF', 'raw' => $m[0]];
            $spec['tolerancia_precio_pct'] = $hasMax ? 5 : 12;
            $spec['presupuesto'] = ['amount' => $val, 'unit' => 'UF', 'raw' => $m[0]];
            $precioDetected = true;
        }
        // CLP millions: "100 millones", "80 millones clp"
        elseif (preg_match('/(?:(?:hasta|max|máx)\\s+)?(\\d+(?:[.,]\\d+)?)\\s*(?:millones?|MM?)\\b/i', $q, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            $amount = $val * 1000000;
            $spec['precio'] = ['min' => null, 'max' => $amount, 'moneda' => 'CLP', 'raw' => $m[0]];
            $spec['presupuesto'] = ['amount' => $amount, 'unit' => 'CLP', 'raw' => $m[0]];
            $precioDetected = true;
        }
        // CLP explicit: "$120.000.000"
        elseif (preg_match('/\\$\\s?(\\d{1,3}(?:\\.\\d{3}){2,3})/', $q, $m)) {
            $val = (int) str_replace('.', '', $m[1]);
            $spec['precio'] = ['min' => null, 'max' => $val, 'moneda' => 'CLP', 'raw' => $m[0]];
            $spec['presupuesto'] = ['amount' => $val, 'unit' => 'CLP', 'raw' => $m[0]];
            $precioDetected = true;
        }

        if ($precioDetected) {
            $spec['restricciones_duras'][] = 'precio';
        }

        // --- Superficie ---
        if (preg_match('/(\\d+(?:[.,]\\d+)?)\\s*(?:ha|hect[áa]reas?)\\b/i', $q, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            $spec['superficie'] = ['amount' => $val, 'unit' => 'ha', 'm2' => $val * 10000];
            $spec['m2_terreno_min'] = $val * 10000;
        } elseif (preg_match('/(\\d{1,3}(?:\\.\\d{3})*(?:,\\d+)?)\\s*m[²2]\\b/i', $q, $m)) {
            $val = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $spec['superficie'] = ['amount' => $val, 'unit' => 'm2', 'm2' => $val];
            $spec['m2_terreno_min'] = $val;
        }

        // Infer property type from surface
        if (!$spec['tipo_propiedad'] && $spec['superficie']) {
            if ($spec['superficie']['unit'] === 'ha') {
                $spec['tipo_propiedad'] = 'parcela';
            } elseif ($spec['superficie']['m2'] > 1000) {
                $spec['tipo_propiedad'] = 'terreno';
            }
        }

        // --- Dormitorios / Baños ---
        if (preg_match('/(\\d+)\\s*(?:dormitorio|dorm|pieza|habitaci[oó]n)/i', $q, $m)) {
            $spec['dormitorios_min'] = (int) $m[1];
        } elseif (preg_match('/(\\d+)\\s*d\\b/i', $q, $m)) {
            $spec['dormitorios_min'] = (int) $m[1];
        }

        if (preg_match('/(\\d+)\\s*(?:baño|bath)/i', $q, $m)) {
            $spec['banos_min'] = (int) $m[1];
        } elseif (preg_match('/(\\d+)\\s*b\\b/i', $q, $m)) {
            $spec['banos_min'] = (int) $m[1];
        }

        // Backward compat
        $spec['dormitorios'] = $spec['dormitorios_min'];
        $spec['banos'] = $spec['banos_min'];

        // --- Must-have features ---
        $sortedFeatures = self::$featureKeywords;
        uksort($sortedFeatures, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        $foundFeatures = [];
        foreach ($sortedFeatures as $keyword => $canonical) {
            // Use word boundary to avoid "barrio" matching "rio"
            $escaped = preg_quote($keyword, '/');
            if (preg_match('/\\b' . $escaped . '\\b/iu', $q) && !in_array($canonical, $foundFeatures)) {
                $foundFeatures[] = $canonical;
            }
        }
        $spec['must_have'] = $foundFeatures;

        // Classify features as hard or soft constraints
        foreach ($foundFeatures as $feat) {
            if (in_array($feat, self::$softConstraintFeatures)) {
                $spec['restricciones_blandas'][] = $feat;
            } else {
                // agua, luz, camino, rol propio = hard constraints
                $spec['restricciones_duras'][] = $feat;
            }
        }

        // --- Location qualifier (zone context) ---
        foreach (self::$qualifierPatterns as $pattern => $config) {
            if (preg_match($pattern, $q, $qm)) {
                $spec['zona_texto'] = $config['label'];
                $spec['zona_texto_raw'] = trim($qm[0]);
                $spec['location_qualifier'] = $config['label'];
                $spec['location_qualifier_raw'] = trim($qm[0]);

                // Set urban/rural context
                if ($config['implies_urban']) {
                    $spec['contexto']['urbano_rural'] = 'urbano';
                } elseif ($config['implies_urban'] === false) {
                    $spec['contexto']['urbano_rural'] = 'rural';
                }

                // Set priority from qualifier
                $spec['contexto']['prioridad'] = $config['priority'];

                // Zone as hard constraint if specified
                if ($config['hard_constraint']) {
                    $spec['restricciones_duras'][] = 'zona';
                } else {
                    $spec['restricciones_blandas'][] = 'zona';
                }

                break;
            }
        }

        // --- Infer urban/rural from other signals ---
        if ($spec['contexto']['urbano_rural'] === 'unknown') {
            $ruralTypes = ['parcela', 'campo', 'terreno'];
            $urbanTypes = ['departamento', 'oficina', 'local'];

            if (in_array($spec['tipo_propiedad'], $urbanTypes)) {
                $spec['contexto']['urbano_rural'] = 'urbano';
            } elseif (in_array($spec['tipo_propiedad'], $ruralTypes) && 
                      ($spec['superficie'] && $spec['superficie']['m2'] > 5000)) {
                $spec['contexto']['urbano_rural'] = 'rural';
            }

            // Rural keywords
            if (preg_match('/\\b(parcela de agrado|fuera de la ciudad|camino a|ruta|km\\s+\\d|sector rural)\\b/i', $q)) {
                $spec['contexto']['urbano_rural'] = 'rural';
            }
        }

        // --- Default priorities if not set ---
        if (empty($spec['contexto']['prioridad'])) {
            $spec['contexto']['prioridad'] = ['precio', 'ubicacion'];
        }

        // --- Buying intent ---
        if (str_contains($q, 'comprar') || str_contains($q, 'puedo')) {
            $spec['operacion'] = 'venta';
        }

        // --- Fallback questions ---
        if (!$spec['ubicacion']) {
            $spec['fallback_questions'][] = '¿En qué comuna o ciudad estás buscando?';
        }
        if (!$spec['tipo_propiedad']) {
            $spec['fallback_questions'][] = '¿Qué tipo de propiedad te interesa? (casa, departamento, parcela, terreno)';
        }

        // --- Deduplicate restricciones ---
        $spec['restricciones_duras'] = array_values(array_unique($spec['restricciones_duras']));
        $spec['restricciones_blandas'] = array_values(array_unique($spec['restricciones_blandas']));

        // --- Confidence score ---
        $score = 0.0;
        if ($spec['tipo_propiedad']) $score += 0.25;
        if ($spec['ubicacion']) $score += 0.25;
        if ($spec['precio']) $score += 0.15;
        if ($spec['superficie']) $score += 0.10;
        if (!empty($spec['must_have'])) $score += 0.05;
        if ($spec['zona_texto']) $score += 0.10;
        if ($spec['dormitorios_min'] || $spec['banos_min']) $score += 0.10;
        $spec['confidence'] = round(min($score, 1.0), 2);

        return $spec;
    }

    /**
     * Get accent variations for a location name.
     */
    public static function getLocationVariations(string $location): array {
        $variations = [$location];
        $noAccent = strtr($location, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]);
        if ($noAccent !== $location) {
            $variations[] = $noAccent;
        }
        return array_unique($variations);
    }

    /**
     * Build a human-readable summary of the parsed SearchSpec.
     */
    public static function summarize(array $spec): string {
        $parts = [];
        if ($spec['tipo_propiedad']) $parts[] = $spec['tipo_propiedad'];
        $parts[] = ($spec['operacion'] === 'arriendo') ? 'en arriendo' : 'en venta';
        if ($spec['ubicacion']) $parts[] = 'en ' . $spec['ubicacion'];

        if ($spec['zona_texto']) {
            $parts[] = '(' . $spec['zona_texto'] . ')';
        }

        if ($spec['superficie']) {
            $parts[] = $spec['superficie']['amount'] . ' ' . $spec['superficie']['unit'];
        }

        if ($spec['precio']) {
            $p = $spec['precio'];
            if ($p['min'] && $p['max']) {
                $parts[] = number_format($p['min'], 0, ',', '.') . '-' . number_format($p['max'], 0, ',', '.') . ' ' . $p['moneda'];
            } elseif ($p['max']) {
                $unit = $p['moneda'];
                if ($unit === 'CLP') {
                    $parts[] = 'hasta $' . number_format($p['max'], 0, ',', '.');
                } else {
                    $parts[] = 'hasta ' . number_format($p['max'], 0, ',', '.') . ' ' . $unit;
                }
            }
        }

        if ($spec['dormitorios_min']) $parts[] = $spec['dormitorios_min'] . 'D';
        if ($spec['banos_min']) $parts[] = $spec['banos_min'] . 'B';
        if (!empty($spec['must_have'])) {
            $parts[] = 'con ' . implode(', ', $spec['must_have']);
        }

        return implode(' ', $parts);
    }

    /**
     * Get the price range with tolerance applied.
     *
     * @return array{min: float, max: float, moneda: string}|null
     */
    public static function getPriceRange(array $spec): ?array {
        if (!$spec['precio']) return null;

        $tol = ($spec['tolerancia_precio_pct'] ?? 12) / 100;
        $moneda = $spec['precio']['moneda'];

        $min = $spec['precio']['min'] ?? null;
        $max = $spec['precio']['max'] ?? null;

        // Apply tolerance
        if ($min !== null && $max !== null) {
            // Explicit range: apply tolerance on edges
            return [
                'min' => round($min * (1 - $tol)),
                'max' => round($max * (1 + $tol)),
                'moneda' => $moneda,
            ];
        } elseif ($max !== null) {
            // "hasta X": range is [0, X * (1+tol)]
            return [
                'min' => 0,
                'max' => round($max * (1 + $tol)),
                'moneda' => $moneda,
            ];
        }

        return null;
    }
}
