<?php
/**
 * QueBot Legal Library - BCN LeyChile Connector
 * Fetches and parses norms from BCN Legislación Abierta Web Service (XML)
 * 
 * API Docs: https://www.bcn.cl/leychile/consulta/legislacion_abierta_web_service
 * Key endpoint: https://www.leychile.cl/Consulta/obtxml?opt=7&idNorma={id}
 */

class BcnConnector {
    
    private const BASE_URL = 'https://www.leychile.cl/Consulta/obtxml';
    private const USER_AGENT = 'QueBot/1.0 (Legal Library; +https://quebot-production.up.railway.app)';
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 3; // seconds
    
    /**
     * Fetch full XML of a norm's latest version
     * @param string $idNorma BCN norm identifier
     * @return array{xml: string, http_code: int, error: ?string}
     */
    public function fetchNorm(string $idNorma): array {
        $url = self::BASE_URL . '?' . http_build_query([
            'opt' => 7,
            'idNorma' => $idNorma
        ]);
        
        return $this->fetchWithRetry($url);
    }
    
    /**
     * Fetch norm metadata only (lighter request)
     * @param string $idNorma BCN norm identifier
     * @return array{xml: string, http_code: int, error: ?string}
     */
    public function fetchNormMetadata(string $idNorma): array {
        $url = self::BASE_URL . '?' . http_build_query([
            'opt' => 4546,
            'idNorma' => $idNorma
        ]);
        
        return $this->fetchWithRetry($url);
    }
    
    /**
     * Search norms by text
     * @param string $query Search text
     * @param int $limit Max results
     * @return array{xml: string, http_code: int, error: ?string}
     */
    public function searchNorms(string $query, int $limit = 10): array {
        $url = self::BASE_URL . '?' . http_build_query([
            'opt' => 61,
            'cadena' => $query,
            'cantidad' => $limit
        ]);
        
        return $this->fetchWithRetry($url);
    }
    
    /**
     * Parse a full norm XML into structured data
     * @param string $xmlString Raw XML from BCN
     * @return array Parsed norm data
     */
    public function parseNorm(string $xmlString): array {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return ['error' => 'Failed to parse XML'];
        }
        
        // Register namespace
        $xml->registerXPathNamespace('n', 'http://www.leychile.cl/esquemas');
        
        // Extract basic identification
        $norm = [
            'id_norma' => (string)$xml['normaId'],
            'fecha_version' => (string)$xml['fechaVersion'],
            'derogado' => ((string)$xml['derogado']) === 'derogado',
            'es_tratado' => ((string)$xml['esTratado']) === 'tratado',
        ];
        
        // Identification
        $ident = $xml->xpath('//n:Identificador')[0] ?? null;
        if ($ident) {
            $norm['fecha_publicacion'] = (string)$ident['fechaPublicacion'];
            $norm['fecha_promulgacion'] = (string)$ident['fechaPromulgacion'];
            
            $tipos = $ident->xpath('.//n:TipoNumero');
            if (!empty($tipos)) {
                $norm['tipo'] = (string)$tipos[0]->xpath('n:Tipo')[0];
                $norm['numero'] = (string)$tipos[0]->xpath('n:Numero')[0];
            }
            
            $orgs = $ident->xpath('.//n:Organismo');
            $norm['organismo'] = !empty($orgs) ? (string)$orgs[0] : null;
        }
        
        // Metadata
        $meta = $xml->xpath('//n:Norma/n:Metadatos')[0] ?? null;
        if (!$meta) {
            $meta = $xml->xpath('//n:Metadatos')[0] ?? null;
        }
        if ($meta) {
            $norm['titulo'] = (string)($meta->xpath('n:TituloNorma')[0] ?? '');
            
            $materias = $meta->xpath('n:Materias/n:Materia');
            $norm['materias'] = array_map(function($m) { return (string)$m; }, $materias);
            
            $nombres = $meta->xpath('n:NombresUsoComun/n:NombreUsoComun');
            $norm['nombres_uso_comun'] = array_map(function($n) { return (string)$n; }, $nombres);
        }
        
        // Encabezado (header/preamble)
        $encab = $xml->xpath('//n:Encabezado')[0] ?? null;
        if ($encab) {
            $norm['encabezado'] = [
                'texto' => $this->cleanText((string)($encab->xpath('n:Texto')[0] ?? '')),
                'fecha_version' => (string)$encab['fechaVersion'],
                'derogado' => ((string)$encab['derogado']) === 'derogado',
            ];
        }
        
        // Articles (recursive structure)
        $norm['articles'] = [];
        $estructuras = $xml->xpath('//n:Norma/n:EstructurasFuncionales/n:EstructuraFuncional');
        if (empty($estructuras)) {
            $estructuras = $xml->xpath('//n:EstructurasFuncionales/n:EstructuraFuncional');
        }
        
        foreach ($estructuras as $ef) {
            $norm['articles'][] = $this->parseEstructuraFuncional($ef);
        }
        
        // Promulgación
        $prom = $xml->xpath('//n:Promulgacion')[0] ?? null;
        if ($prom) {
            $norm['promulgacion'] = [
                'texto' => $this->cleanText((string)($prom->xpath('n:Texto')[0] ?? '')),
            ];
        }
        
        // URL canónica
        $norm['url_canonica'] = "https://www.leychile.cl/Navegar?idNorma={$norm['id_norma']}";
        
        // Hash for change detection
        $norm['text_hash'] = $this->computeTextHash($norm);
        $norm['xml_size'] = strlen($xmlString);
        
        return $norm;
    }
    
    /**
     * Parse a single EstructuraFuncional (article or grouper) recursively
     */
    private function parseEstructuraFuncional($ef): array {
        $item = [
            'id_parte' => (string)$ef['idParte'],
            'tipo_parte' => (string)$ef['tipoParte'],
            'fecha_version' => (string)$ef['fechaVersion'],
            'derogado' => ((string)$ef['derogado']) === 'derogado',
            'transitorio' => ((string)$ef['transitorio']) === 'transitorio',
            'texto' => '',
            'nombre_parte' => '',
            'titulo_parte' => '',
            'children' => [],
        ];
        
        // Text
        $textoNodes = $ef->xpath('n:Texto');
        if (!empty($textoNodes)) {
            $item['texto'] = $this->cleanText((string)$textoNodes[0]);
        }
        
        // Metadata
        $metaNodes = $ef->xpath('n:Metadatos');
        if (!empty($metaNodes)) {
            $nombreParte = $metaNodes[0]->xpath('n:NombreParte');
            if (!empty($nombreParte) && ((string)$nombreParte[0]['presente']) === 'si') {
                $item['nombre_parte'] = trim((string)$nombreParte[0]);
            }
            
            $tituloParte = $metaNodes[0]->xpath('n:TituloParte');
            if (!empty($tituloParte) && ((string)$tituloParte[0]['presente']) === 'si') {
                $item['titulo_parte'] = trim((string)$tituloParte[0]);
            }
        }
        
        // Nested structures (for groupers like Título, Capítulo, etc.)
        $children = $ef->xpath('n:EstructurasFuncionales/n:EstructuraFuncional');
        foreach ($children as $child) {
            $item['children'][] = $this->parseEstructuraFuncional($child);
        }
        
        return $item;
    }
    
    /**
     * Clean text from XML artifacts
     */
    private function cleanText(string $text): string {
        // Decode XML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Remove non-breaking spaces
        $text = str_replace("\xC2\xA0", ' ', $text);
        // Normalize whitespace but preserve paragraph breaks
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
    
    /**
     * Compute hash of all article texts for change detection
     */
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
    
    /**
     * HTTP fetch with retry logic
     */
    private function fetchWithRetry(string $url): array {
        $lastError = null;
        
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                sleep(self::RETRY_DELAY);
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/xml, application/xml, */*',
                ],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                $lastError = "cURL error: $curlError";
                continue;
            }
            
            if ($httpCode === 200 && !empty($response)) {
                return ['xml' => $response, 'http_code' => $httpCode, 'error' => null];
            }
            
            $lastError = "HTTP $httpCode" . (empty($response) ? ' (empty response)' : '');
        }
        
        return ['xml' => '', 'http_code' => $httpCode ?? 0, 'error' => $lastError];
    }
}
