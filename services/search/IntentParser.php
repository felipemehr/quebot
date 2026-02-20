<?php
/**
 * IntentParser — Structured intent extraction for real estate queries.
 *
 * Parses user query into:
 * - tipo_propiedad: parcela|terreno|casa|depto|sitio|local|bodega|campo
 * - ubicacion: normalized comuna/ciudad/región
 * - presupuesto: {amount, unit: 'CLP'|'UF', raw}
 * - superficie: {amount, unit: 'm2'|'ha', m2: <always in m2>}
 * - must_have: ['agua', 'luz', 'camino', etc.]
 * - operacion: 'venta'|'arriendo'
 * - dormitorios: int|null
 * - banos: int|null
 * - fallback_questions: string[] (if critical info missing)
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
        'hualaihue' => 'Hualaihué', 'hualaihué' => 'Hualaihué',
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

    /**
     * Parse user query into structured real estate intent.
     *
     * @return array{
     *   tipo_propiedad: ?string,
     *   ubicacion: ?string,
     *   ubicacion_raw: ?string,
     *   presupuesto: ?array,
     *   superficie: ?array,
     *   must_have: string[],
     *   operacion: string,
     *   dormitorios: ?int,
     *   banos: ?int,
     *   fallback_questions: string[],
     *   confidence: float
     * }
     */
    public static function parse(string $query): array {
        $q = mb_strtolower(trim($query));
        // Normalize multiple spaces
        $q = preg_replace('/\s+/', ' ', $q);

        $intent = [
            'tipo_propiedad' => null,
            'ubicacion' => null,
            'ubicacion_raw' => null,
            'presupuesto' => null,
            'superficie' => null,
            'must_have' => [],
            'operacion' => 'venta', // default
            'dormitorios' => null,
            'banos' => null,
            'fallback_questions' => [],
            'confidence' => 0.0,
        ];

        // --- Operación ---
        if (preg_match('/\b(arriendo|arrienda|alquil|rent)\b/i', $q)) {
            $intent['operacion'] = 'arriendo';
        }

        // --- Tipo de propiedad ---
        foreach (self::$propertyTypes as $keyword => $canonical) {
            if (str_contains($q, $keyword)) {
                $intent['tipo_propiedad'] = $canonical;
                break;
            }
        }

        // --- Ubicación ---
        // Sort by length descending to match "San Pedro de la Paz" before "San Pedro"
        $sortedLocations = self::$locations;
        uksort($sortedLocations, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($sortedLocations as $key => $display) {
            if (str_contains($q, $key)) {
                $intent['ubicacion'] = $display;
                $intent['ubicacion_raw'] = $key;
                break;
            }
        }

        // If no match, try to find "en <word>" pattern
        if (!$intent['ubicacion'] && preg_match('/\ben\s+([a-záéíóúñü]+(?:\s+(?:de\s+)?[a-záéíóúñü]+)*)/u', $q, $m)) {
            $candidate = trim($m[1]);
            // Exclude common non-location words
            $nonLocations = ['venta', 'arriendo', 'oferta', 'el', 'la', 'los', 'las', 'un', 'una', 'portalinmobiliario', 'yapo', 'toctoc'];
            if (!in_array($candidate, $nonLocations) && mb_strlen($candidate) > 2) {
                $intent['ubicacion'] = ucwords($candidate);
                $intent['ubicacion_raw'] = $candidate;
            }
        }

        // --- Presupuesto ---
        // UF: "5000 uf", "5.000 UF", "hasta 5000uf"
        if (preg_match('/(?:hasta\s+)?(\d{1,3}(?:\.\d{3})*(?:,\d+)?)\s*uf\b/i', $q, $m)) {
            $val = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $intent['presupuesto'] = ['amount' => $val, 'unit' => 'UF', 'raw' => $m[0]];
        }
        // CLP millions: "100 millones", "80 millones clp"
        elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:millones?|MM?)\b/i', $q, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            $intent['presupuesto'] = ['amount' => $val * 1000000, 'unit' => 'CLP', 'raw' => $m[0]];
        }
        // CLP explicit: "$120.000.000"
        elseif (preg_match('/\$\s?(\d{1,3}(?:\.\d{3}){2,3})/', $q, $m)) {
            $val = (int) str_replace('.', '', $m[1]);
            $intent['presupuesto'] = ['amount' => $val, 'unit' => 'CLP', 'raw' => $m[0]];
        }

        // --- Superficie ---
        // Hectáreas: "5ha", "5 ha", "5 hectáreas", "5 hectareas"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:ha|hect[áa]reas?)\b/i', $q, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            $intent['superficie'] = ['amount' => $val, 'unit' => 'ha', 'm2' => $val * 10000];
        }
        // m²: "5000m2", "7.250 m²"
        elseif (preg_match('/(\d{1,3}(?:\.\d{3})*(?:,\d+)?)\s*m[²2]\b/i', $q, $m)) {
            $val = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $intent['superficie'] = ['amount' => $val, 'unit' => 'm2', 'm2' => $val];
        }

        // --- Infer property type from surface ---
        // If surface in hectáreas and no explicit type → likely parcela/terreno
        if (!$intent['tipo_propiedad'] && $intent['superficie'] && $intent['superficie']['unit'] === 'ha') {
            $intent['tipo_propiedad'] = 'parcela';
        }
        // If surface > 1000m2 and no type → likely terreno/parcela
        if (!$intent['tipo_propiedad'] && $intent['superficie'] && $intent['superficie']['m2'] > 1000) {
            $intent['tipo_propiedad'] = 'terreno';
        }

        // --- Dormitorios / Baños ---
        if (preg_match('/(\d+)\s*(?:dormitorio|dorm|pieza|habitaci[oó]n)/i', $q, $m)) {
            $intent['dormitorios'] = (int) $m[1];
        } elseif (preg_match('/(\d+)\s*d\b/i', $q, $m)) {
            $intent['dormitorios'] = (int) $m[1];
        }

        if (preg_match('/(\d+)\s*(?:baño|bath)/i', $q, $m)) {
            $intent['banos'] = (int) $m[1];
        } elseif (preg_match('/(\d+)\s*b\b/i', $q, $m)) {
            $intent['banos'] = (int) $m[1];
        }

        // --- Must-have features ---
        // Sort by length desc to match "orilla de lago" before "lago"
        $sortedFeatures = self::$featureKeywords;
        uksort($sortedFeatures, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        $foundFeatures = [];
        foreach ($sortedFeatures as $keyword => $canonical) {
            if (str_contains($q, $keyword) && !in_array($canonical, $foundFeatures)) {
                $foundFeatures[] = $canonical;
            }
        }
        $intent['must_have'] = $foundFeatures;

        // --- Infer from budget context ---
        // "puedo comprar varias" implies buying intent with budget
        if (str_contains($q, 'comprar') || str_contains($q, 'puedo')) {
            $intent['operacion'] = 'venta';
        }

        // --- Fallback questions ---
        if (!$intent['ubicacion']) {
            $intent['fallback_questions'][] = '¿En qué comuna o ciudad estás buscando?';
        }
        if (!$intent['tipo_propiedad']) {
            $intent['fallback_questions'][] = '¿Qué tipo de propiedad te interesa? (casa, departamento, parcela, terreno)';
        }

        // --- Confidence score ---
        $score = 0.0;
        if ($intent['tipo_propiedad']) $score += 0.3;
        if ($intent['ubicacion']) $score += 0.3;
        if ($intent['presupuesto']) $score += 0.15;
        if ($intent['superficie']) $score += 0.1;
        if (!empty($intent['must_have'])) $score += 0.05;
        if ($intent['dormitorios'] || $intent['banos']) $score += 0.1;
        $intent['confidence'] = round($score, 2);

        return $intent;
    }

    /**
     * Get accent variations for a location name.
     * Returns both accented and non-accented versions.
     */
    public static function getLocationVariations(string $location): array {
        $variations = [$location];

        // Remove accents
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
     * Build a human-readable summary of the parsed intent.
     */
    public static function summarize(array $intent): string {
        $parts = [];
        if ($intent['tipo_propiedad']) $parts[] = $intent['tipo_propiedad'];
        if ($intent['operacion'] === 'arriendo') $parts[] = 'en arriendo';
        else $parts[] = 'en venta';
        if ($intent['ubicacion']) $parts[] = 'en ' . $intent['ubicacion'];
        if ($intent['superficie']) {
            $parts[] = $intent['superficie']['amount'] . ' ' . $intent['superficie']['unit'];
        }
        if ($intent['presupuesto']) {
            $unit = $intent['presupuesto']['unit'];
            $amount = $intent['presupuesto']['amount'];
            if ($unit === 'CLP') {
                $parts[] = 'hasta $' . number_format($amount, 0, ',', '.');
            } else {
                $parts[] = 'hasta ' . number_format($amount, 0, ',', '.') . ' UF';
            }
        }
        if (!empty($intent['must_have'])) {
            $parts[] = 'con ' . implode(', ', $intent['must_have']);
        }
        return implode(' ', $parts);
    }
}
