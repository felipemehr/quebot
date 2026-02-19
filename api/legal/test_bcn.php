<?php
header('Content-Type: application/json');
$token = $_GET['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($token !== getenv('ADMIN_TOKEN')) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
error_reporting(E_ALL); ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../services/legal/database.php';
    require_once __DIR__ . '/../../services/legal/BcnConnector.php';
    require_once __DIR__ . '/../../services/legal/LegalChunker.php';
    $results = ['steps' => []];
    
    // Test BCN fetch
    $connector = new BcnConnector();
    $idNorma = '141599';
    $fetchResult = $connector->fetchNorm($idNorma);
    $results['fetch_http_code'] = $fetchResult['http_code'] ?? null;
    $results['fetch_error'] = $fetchResult['error'] ?? null;
    $results['xml_length'] = strlen($fetchResult['xml'] ?? '');
    
    if (!empty($fetchResult['xml'])) {
        // Dump XML structure
        $xml = simplexml_load_string($fetchResult['xml']);
        if ($xml) {
            $results['xml_root'] = $xml->getName();
            $results['xml_namespaces'] = $xml->getNamespaces(true);
            $results['xml_attrs'] = [];
            foreach ($xml->attributes() as $k => $v) { $results['xml_attrs'][$k] = (string)$v; }
            $results['xml_children'] = [];
            foreach ($xml->children() as $child) { $results['xml_children'][] = $child->getName(); }
            
            // Test xpath with and without namespace
            $results['xpath_tests'] = [
                'with_ns_Identificador' => count($xml->xpath('//n:Identificador') ?: []),
                'no_ns_Identificador' => count($xml->xpath('//Identificador') ?: []),
            ];
            $xml->registerXPathNamespace('n', 'http://www.leychile.cl/esquemas');
            $results['xpath_tests']['after_register_ns'] = count($xml->xpath('//n:Identificador') ?: []);
            $results['xpath_tests']['after_register_no_ns'] = count($xml->xpath('//Identificador') ?: []);
            
            // Check default namespace
            $xmlStr = substr($fetchResult['xml'], 0, 500);
            $results['xml_head'] = $xmlStr;
        }
        
        // Try parse
        $parsed = $connector->parseNorm($fetchResult['xml']);
        $results['parse_keys'] = array_keys($parsed);
        $results['parse_id'] = $parsed['id_norma'] ?? null;
        $results['parse_titulo'] = $parsed['titulo'] ?? null;
        $results['parse_tipo'] = $parsed['tipo'] ?? null;
        $results['parse_articles'] = count($parsed['articles'] ?? []);
        $results['parse_error'] = $parsed['error'] ?? null;
        
        if (!empty($parsed['articles'])) {
            $results['first_article'] = [
                'tipo' => $parsed['articles'][0]['tipo_parte'] ?? null,
                'nombre' => $parsed['articles'][0]['nombre_parte'] ?? null,
                'text_len' => strlen($parsed['articles'][0]['texto'] ?? ''),
                'children' => count($parsed['articles'][0]['children'] ?? []),
            ];
        }
        
        // If parse worked, try chunking
        if (empty($parsed['error']) && !empty($parsed['articles'])) {
            $chunker = new LegalChunker();
            $chunks = $chunker->chunkArticles($parsed['articles']);
            $results['chunks'] = count($chunks);
            if (!empty($chunks)) {
                $results['first_chunk'] = ['path' => $chunks[0]['path'] ?? null, 'len' => strlen($chunks[0]['text'] ?? '')];
            }
        }
    }
    
    $results['status'] = 'ok';
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}
