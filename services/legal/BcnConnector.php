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
    
    public function fetchNorm(string $idNorma): array {
        $url = self::BASE_URL . '?' . http_build_query(['opt' => 7, 'idNorma' => $idNorma]);
        return $this->fetchWithRetry($url);
    }
    
    public function fetchNormMetadata(string $idNorma): array {
        $url = self::BASE_URL . '?' . http_build_query(['opt' => 4546, 'idNorma' => $idNorma]);
        return $this->fetchWithRetry($url);
    }
    
    public function searchNorms(string $query, int $limit = 10): array {
        $url = self::BASE_URL . '?' . http_build_query(['opt' => 61, 'cadena' => $query, 'cantidad' => $limit]);
        return $this->fetchWithRetry($url);
    }
    
    /**
     * Parse a full norm XML into structured data
     * Handles both namespaced and non-namespaced BCN XML
     */
    public function parseNorm(string $xmlString): array {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return ['error' => 'Failed to parse XML'];
        }
        
        // Detect namespace
        $namespaces = $xml->getNamespaces(true);
        $hasNs = !empty($namespaces);
        $nsPrefix = '';
        if ($hasNs) {
            // Register the default namespace
            $defaultNs = reset($namespaces);
            $xml->registerXPathNamespace('n', $defaultNs);
            $nsPrefix = 'n:';
        }
        
        // Helper: safe xpath that always returns array
        $xp = function(string $path, $context = null) use ($xml, $nsPrefix) {
            $node = $context ?? $xml;
            $result = $node->xpath($path);
            return is_array($result) ? $result : [];
        };
        
        // Extract basic identification from root attributes
        // BCN uses PascalCase: NormaId, FechaVersion, Derogado, EsTratado
        $norm = [
            'id_norma' => (string)($xml['NormaId'] ?? $xml['normaId'] ?? ''),
            'fecha_version' => (string)($xml['FechaVersion'] ?? $xml['fechaVersion'] ?? ''),
            'derogado' => in_array((string)($xml['Derogado'] ?? $xml['derogado'] ?? ''), ['derogado', 'si', 'true']),
            'es_tratado' => in_array((string)($xml['EsTratado'] ?? $xml['esTratado'] ?? ''), ['tratado', 'si', 'true']),
        ];
        
        // Try both namespaced and non-namespaced xpath
        $findNodes = function(string $path, $context = null) use ($xml, $nsPrefix, $xp) {
            $node = $context ?? $xml;
            // Try with namespace prefix first
            if ($nsPrefix) {
                $result = $xp($path, $node);
                if (!empty($result)) return $result;
            }
            // Try without namespace
            $plainPath = str_replace($nsPrefix, '', $path);
            $result = $xp($plainPath, $node);
            if (!empty($result)) return $result;
            return [];
        };
        
        // Identification
        $idents = $findNodes("//{$nsPrefix}Identificador");
        $ident = $idents[0] ?? null;
        if ($ident) {
            $norm['fecha_publicacion'] = (string)($ident['FechaPublicacion'] ?? $ident['fechaPublicacion'] ?? '');
            $norm['fecha_promulgacion'] = (string)($ident['FechaPromulgacion'] ?? $ident['fechaPromulgacion'] ?? '');
            
            $tipos = $findNodes(".//{$nsPrefix}TipoNumero", $ident);
            if (!empty($tipos)) {
                $tipoNodes = $findNodes("{$nsPrefix}Tipo", $tipos[0]);
                $numNodes = $findNodes("{$nsPrefix}Numero", $tipos[0]);
                $norm['tipo'] = !empty($tipoNodes) ? (string)$tipoNodes[0] : null;
                $norm['numero'] = !empty($numNodes) ? (string)$numNodes[0] : null;
            }
            
            $orgs = $findNodes(".//{$nsPrefix}Organismo", $ident);
            $norm['organismo'] = !empty($orgs) ? (string)$orgs[0] : null;
        }
        
        // Metadata
        $metas = $findNodes("//{$nsPrefix}Metadatos");
        $meta = $metas[0] ?? null;
        if ($meta) {
            $titulos = $findNodes("{$nsPrefix}TituloNorma", $meta);
            $norm['titulo'] = !empty($titulos) ? (string)$titulos[0] : '';
            
            $materias = $findNodes("{$nsPrefix}Materias/{$nsPrefix}Materia", $meta);
            $norm['materias'] = array_map(function($m) { return (string)$m; }, $materias);
            
            $nombres = $findNodes("{$nsPrefix}NombresUsoComun/{$nsPrefix}NombreUsoComun", $meta);
            $norm['nombres_uso_comun'] = array_map(function($n) { return (string)$n; }, $nombres);
        } else {
            $norm['titulo'] = '';
            $norm['materias'] = [];
            $norm['nombres_uso_comun'] = [];
        }
        
        // Encabezado (header/preamble)
        $encabs = $findNodes("//{$nsPrefix}Encabezado");
        $encab = $encabs[0] ?? null;
        if ($encab) {
            $textos = $findNodes("{$nsPrefix}Texto", $encab);
            $norm['encabezado'] = [
                'texto' => $this->cleanText(!empty($textos) ? (string)$textos[0] : ''),
                'fecha_version' => (string)($encab['FechaVersion'] ?? $encab['fechaVersion'] ?? ''),
                'derogado' => in_array((string)($encab['Derogado'] ?? $encab['derogado'] ?? ''), ['derogado', 'si']),
            ];
        }
        
        // Articles (recursive structure)
        $norm['articles'] = [];
        $estructuras = $findNodes("//{$nsPrefix}Norma/{$nsPrefix}EstructurasFuncionales/{$nsPrefix}EstructuraFuncional");
        if (empty($estructuras)) {
            $estructuras = $findNodes("//{$nsPrefix}EstructurasFuncionales/{$nsPrefix}EstructuraFuncional");
        }
        
        foreach ($estructuras as $ef) {
            $norm['articles'][] = $this->parseEstructuraFuncional($ef, $nsPrefix);
        }
        
        // Promulgación
        $proms = $findNodes("//{$nsPrefix}Promulgacion");
        $prom = $proms[0] ?? null;
        if ($prom) {
            $textos = $findNodes("{$nsPrefix}Texto", $prom);
            $norm['promulgacion'] = [
                'texto' => $this->cleanText(!empty($textos) ? (string)$textos[0] : ''),
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
     * Parse a single EstructuraFuncional recursively
     */
    private function parseEstructuraFuncional($ef, string $nsPrefix = ''): array {
        $item = [
            'id_parte' => (string)($ef['IdParte'] ?? $ef['idParte'] ?? ''),
            'tipo_parte' => (string)($ef['TipoParte'] ?? $ef['tipoParte'] ?? ''),
            'fecha_version' => (string)($ef['FechaVersion'] ?? $ef['fechaVersion'] ?? ''),
            'derogado' => in_array((string)($ef['Derogado'] ?? $ef['derogado'] ?? ''), ['derogado', 'si']),
            'transitorio' => in_array((string)($ef['Transitorio'] ?? $ef['transitorio'] ?? ''), ['transitorio', 'si']),
            'texto' => '',
            'nombre_parte' => '',
            'titulo_parte' => '',
            'children' => [],
        ];
        
        // Text - try both namespaced and plain
        $textoNodes = $ef->xpath("{$nsPrefix}Texto");
        if (!is_array($textoNodes) || empty($textoNodes)) {
            $textoNodes = $ef->xpath('Texto');
        }
        if (is_array($textoNodes) && !empty($textoNodes)) {
            $item['texto'] = $this->cleanText((string)$textoNodes[0]);
        }
        
        // Metadata
        $metaNodes = $ef->xpath("{$nsPrefix}Metadatos");
        if (!is_array($metaNodes) || empty($metaNodes)) {
            $metaNodes = $ef->xpath('Metadatos');
        }
        if (is_array($metaNodes) && !empty($metaNodes)) {
            $nombreParte = $metaNodes[0]->xpath("{$nsPrefix}NombreParte");
            if (!is_array($nombreParte)) $nombreParte = $metaNodes[0]->xpath('NombreParte') ?: [];
            if (!empty($nombreParte) && in_array((string)($nombreParte[0]['Presente'] ?? $nombreParte[0]['presente'] ?? ''), ['si', 'true'])) {
                $item['nombre_parte'] = trim((string)$nombreParte[0]);
            }
            
            $tituloParte = $metaNodes[0]->xpath("{$nsPrefix}TituloParte");
            if (!is_array($tituloParte)) $tituloParte = $metaNodes[0]->xpath('TituloParte') ?: [];
            if (!empty($tituloParte) && in_array((string)($tituloParte[0]['Presente'] ?? $tituloParte[0]['presente'] ?? ''), ['si', 'true'])) {
                $item['titulo_parte'] = trim((string)$tituloParte[0]);
            }
        }
        
        // Nested structures
        $children = $ef->xpath("{$nsPrefix}EstructurasFuncionales/{$nsPrefix}EstructuraFuncional");
        if (!is_array($children) || empty($children)) {
            $children = $ef->xpath('EstructurasFuncionales/EstructuraFuncional') ?: [];
        }
        foreach ($children as $child) {
            $item['children'][] = $this->parseEstructuraFuncional($child, $nsPrefix);
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
