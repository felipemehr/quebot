<?php
/**
 * QueBot Legal Library - BCN LeyChile Connector
 * Fetches and parses norms from BCN Legislación Abierta Web Service (XML)
 */

class BcnConnector {
    
    private const BASE_URL = 'https://www.leychile.cl/Consulta/obtxml';
    private const USER_AGENT = 'QueBot/1.0 (Legal Library; +https://quebot-production.up.railway.app)';
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 3;
    private const NS_URI = 'http://www.leychile.cl/esquemas';
    
    public function fetchNorm(string $idNorma): array {
        return $this->fetchWithRetry(self::BASE_URL . '?' . http_build_query(['opt' => 7, 'idNorma' => $idNorma]));
    }
    
    public function fetchNormMetadata(string $idNorma): array {
        return $this->fetchWithRetry(self::BASE_URL . '?' . http_build_query(['opt' => 4546, 'idNorma' => $idNorma]));
    }
    
    public function searchNorms(string $query, int $limit = 10): array {
        return $this->fetchWithRetry(self::BASE_URL . '?' . http_build_query(['opt' => 61, 'cadena' => $query, 'cantidad' => $limit]));
    }
    
    /**
     * Safe xpath: registers namespace and returns array always
     */
    private function xp($node, string $path): array {
        $node->registerXPathNamespace('n', self::NS_URI);
        $result = $node->xpath($path);
        return is_array($result) ? $result : [];
    }
    
    /**
     * Get first match or null
     */
    private function xpFirst($node, string $path) {
        $results = $this->xp($node, $path);
        return $results[0] ?? null;
    }
    
    /**
     * Parse a full norm XML into structured data
     */
    public function parseNorm(string $xmlString): array {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return ['error' => 'Failed to parse XML'];
        }
        
        // Root attributes (camelCase in BCN XML)
        $norm = [
            'id_norma' => (string)($xml['normaId'] ?? ''),
            'fecha_version' => (string)($xml['fechaVersion'] ?? ''),
            'derogado' => stripos((string)($xml['derogado'] ?? ''), 'derogado') !== false 
                          && stripos((string)($xml['derogado'] ?? ''), 'no derogado') === false,
            'es_tratado' => stripos((string)($xml['esTratado'] ?? ''), 'tratado') !== false
                            && stripos((string)($xml['esTratado'] ?? ''), 'no tratado') === false,
        ];
        
        // Identification
        $ident = $this->xpFirst($xml, '//n:Identificador');
        if ($ident) {
            $norm['fecha_publicacion'] = (string)($ident['fechaPublicacion'] ?? '');
            $norm['fecha_promulgacion'] = (string)($ident['fechaPromulgacion'] ?? '');
            
            // Path: Identificador > TiposNumeros > TipoNumero > Tipo/Numero
            $tipoNumero = $this->xpFirst($ident, './/n:TipoNumero');
            if ($tipoNumero) {
                $tipo = $this->xpFirst($tipoNumero, 'n:Tipo');
                $numero = $this->xpFirst($tipoNumero, 'n:Numero');
                $norm['tipo'] = $tipo ? (string)$tipo : null;
                $norm['numero'] = $numero ? (string)$numero : null;
            }
            
            $org = $this->xpFirst($ident, './/n:Organismo');
            $norm['organismo'] = $org ? (string)$org : null;
        }
        
        // Metadata (direct child of Norma, not inside EstructuraFuncional)
        $meta = $this->xpFirst($xml, 'n:Metadatos');
        if (!$meta) {
            $meta = $this->xpFirst($xml, '//n:Norma/n:Metadatos');
        }
        if ($meta) {
            $titulo = $this->xpFirst($meta, 'n:TituloNorma');
            $norm['titulo'] = $titulo ? trim((string)$titulo) : '';
            
            $materias = $this->xp($meta, 'n:Materias/n:Materia');
            $norm['materias'] = array_map(fn($m) => (string)$m, $materias);
            
            $nombres = $this->xp($meta, 'n:NombresUsoComun/n:NombreUsoComun');
            $norm['nombres_uso_comun'] = array_map(fn($n) => (string)$n, $nombres);
        } else {
            $norm['titulo'] = '';
            $norm['materias'] = [];
            $norm['nombres_uso_comun'] = [];
        }
        
        // Encabezado
        $encab = $this->xpFirst($xml, '//n:Encabezado');
        if ($encab) {
            $texto = $this->xpFirst($encab, 'n:Texto');
            $norm['encabezado'] = [
                'texto' => $this->cleanText($texto ? (string)$texto : ''),
                'fecha_version' => (string)($encab['fechaVersion'] ?? ''),
                'derogado' => stripos((string)($encab['derogado'] ?? ''), 'derogado') !== false
                              && stripos((string)($encab['derogado'] ?? ''), 'no derogado') === false,
            ];
        }
        
        // Articles - get top-level EstructuraFuncional elements
        $norm['articles'] = [];
        $estructuras = $this->xp($xml, 'n:EstructurasFuncionales/n:EstructuraFuncional');
        if (empty($estructuras)) {
            $estructuras = $this->xp($xml, '//n:EstructurasFuncionales/n:EstructuraFuncional');
        }
        
        foreach ($estructuras as $ef) {
            $norm['articles'][] = $this->parseEstructuraFuncional($ef);
        }
        
        // Promulgación
        $prom = $this->xpFirst($xml, '//n:Promulgacion');
        if ($prom) {
            $texto = $this->xpFirst($prom, 'n:Texto');
            $norm['promulgacion'] = [
                'texto' => $this->cleanText($texto ? (string)$texto : ''),
            ];
        }
        
        $norm['url_canonica'] = "https://www.leychile.cl/Navegar?idNorma={$norm['id_norma']}";
        $norm['text_hash'] = $this->computeTextHash($norm);
        $norm['xml_size'] = strlen($xmlString);
        
        return $norm;
    }
    
    private function parseEstructuraFuncional($ef): array {
        $item = [
            'id_parte' => (string)($ef['idParte'] ?? ''),
            'tipo_parte' => '',
            'fecha_version' => (string)($ef['fechaVersion'] ?? ''),
            'derogado' => stripos((string)($ef['derogado'] ?? ''), 'derogado') !== false
                          && stripos((string)($ef['derogado'] ?? ''), 'no derogado') === false,
            'transitorio' => stripos((string)($ef['transitorio'] ?? ''), 'transitorio') !== false,
            'texto' => '',
            'nombre_parte' => '',
            'titulo_parte' => '',
            'children' => [],
        ];
        
        // Text
        $texto = $this->xpFirst($ef, 'n:Texto');
        if ($texto) {
            $item['texto'] = $this->cleanText((string)$texto);
        }
        
        // Metadata of this part
        $meta = $this->xpFirst($ef, 'n:Metadatos');
        if ($meta) {
            // TipoParte
            $tipoParte = $this->xpFirst($meta, 'n:TipoParte');
            if ($tipoParte) {
                $item['tipo_parte'] = trim((string)$tipoParte);
            }
            
            // NombreParte
            $nombreParte = $this->xpFirst($meta, 'n:NombreParte');
            if ($nombreParte) {
                $presente = (string)($nombreParte['presente'] ?? '');
                if ($presente === 'si' || $presente === 'true' || !empty(trim((string)$nombreParte))) {
                    $item['nombre_parte'] = trim((string)$nombreParte);
                }
            }
            
            // TituloParte
            $tituloParte = $this->xpFirst($meta, 'n:TituloParte');
            if ($tituloParte) {
                $presente = (string)($tituloParte['presente'] ?? '');
                if ($presente === 'si' || $presente === 'true' || !empty(trim((string)$tituloParte))) {
                    $item['titulo_parte'] = trim((string)$tituloParte);
                }
            }
        }
        
        // Also check tipoParte as attribute (some versions)
        if (empty($item['tipo_parte'])) {
            $item['tipo_parte'] = (string)($ef['tipoParte'] ?? $ef['TipoParte'] ?? '');
        }
        
        // Nested structures
        $children = $this->xp($ef, 'n:EstructurasFuncionales/n:EstructuraFuncional');
        foreach ($children as $child) {
            $item['children'][] = $this->parseEstructuraFuncional($child);
        }
        
        return $item;
    }
    
    private function cleanText(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
    
    private function computeTextHash(array $norm): string {
        $allText = '';
        foreach ($norm['articles'] as $art) {
            $allText .= $this->collectText($art);
        }
        return hash('sha256', $allText);
    }
    
    private function collectText(array $item): string {
        $text = $item['texto'] ?? '';
        foreach ($item['children'] ?? [] as $child) {
            $text .= $this->collectText($child);
        }
        return $text;
    }
    
    private function fetchWithRetry(string $url): array {
        $lastError = null;
        $httpCode = 0;
        
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) sleep(self::RETRY_DELAY);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: text/xml, application/xml, */*'],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) { $lastError = "cURL: $curlError"; continue; }
            if ($httpCode === 200 && !empty($response)) {
                return ['xml' => $response, 'http_code' => $httpCode, 'error' => null];
            }
            $lastError = "HTTP $httpCode" . (empty($response) ? ' (empty)' : '');
        }
        
        return ['xml' => '', 'http_code' => $httpCode, 'error' => $lastError];
    }
}
