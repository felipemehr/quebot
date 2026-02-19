<?php
/**
 * Temporary debug endpoint - tests BCN connector
 * DELETE THIS FILE after debugging
 */
header('Content-Type: application/json');

// Auth check
$token = $_GET['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($token !== getenv('ADMIN_TOKEN')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Test 1: Can we load the files?
    echo "// Step 1: Loading files...\n";
    require_once __DIR__ . '/../../services/legal/database.php';
    require_once __DIR__ . '/../../services/legal/BcnConnector.php';
    require_once __DIR__ . '/../../services/legal/LegalChunker.php';
    
    $results = ['steps' => []];
    $results['steps'][] = 'Files loaded OK';
    
    // Test 2: DB connection
    $db = LegalDatabase::getConnection();
    $results['steps'][] = 'DB connected OK';
    
    // Test 3: Check sync_runs status
    $stmt = $db->query("SELECT id, status, started_at, finished_at, norms_checked, norms_updated, errors FROM legal_sync_runs ORDER BY id DESC LIMIT 3");
    $results['sync_runs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Test 4: Fetch one norm from BCN
    $connector = new BcnConnector();
    $results['steps'][] = 'BcnConnector created OK';
    
    $idNorma = '141599'; // Ley 19496
    $fetchResult = $connector->fetchNorm($idNorma);
    $results['fetch_http_code'] = $fetchResult['http_code'] ?? null;
    $results['fetch_error'] = $fetchResult['error'] ?? null;
    $results['fetch_xml_length'] = strlen($fetchResult['xml'] ?? '');
    $results['fetch_xml_preview'] = substr($fetchResult['xml'] ?? '', 0, 500);
    
    if (!empty($fetchResult['xml']) && empty($fetchResult['error'])) {
        $results['steps'][] = 'BCN fetch OK';
        
        // Test 5: Parse
        $parsed = $connector->parseNorm($fetchResult['xml']);
        $results['parse_error'] = $parsed['error'] ?? null;
        $results['parse_titulo'] = $parsed['titulo'] ?? null;
        $results['parse_tipo'] = $parsed['tipo'] ?? null;
        $results['parse_articles_count'] = count($parsed['articulos'] ?? []);
        $results['parse_first_article'] = !empty($parsed['articulos']) ? substr(json_encode($parsed['articulos'][0]), 0, 300) : null;
        
        if (empty($parsed['error'])) {
            $results['steps'][] = 'Parse OK';
            
            // Test 6: Chunk
            $chunker = new LegalChunker();
            $chunks = $chunker->chunkArticles($parsed['articulos'] ?? []);
            $results['chunks_count'] = count($chunks);
            $results['first_chunk'] = !empty($chunks) ? ['path' => $chunks[0]['path'] ?? null, 'text_length' => strlen($chunks[0]['text'] ?? '')] : null;
            $results['steps'][] = 'Chunking OK: ' . count($chunks) . ' chunks';
        }
    }
    
    $results['status'] = 'ok';
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10)
    ], JSON_PRETTY_PRINT);
}
