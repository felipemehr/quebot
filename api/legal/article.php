<?php
/**
 * GET /api/legal/article?idNorma=141599&art=1
 * Returns article text + sub-chunks
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../services/legal/database.php';

$idNorma = $_GET['idNorma'] ?? '';
$art = $_GET['art'] ?? '';

if (!$idNorma) {
    http_response_code(400);
    echo json_encode(['error' => 'idNorma parameter required']);
    exit;
}

try {
    $db = LegalDatabase::getConnection();
    
    // Find norm
    $stmt = $db->prepare("SELECT id FROM legal_norms WHERE id_norma = ?");
    $stmt->execute([$idNorma]);
    $norm = $stmt->fetch();
    
    if (!$norm) {
        http_response_code(404);
        echo json_encode(['error' => "Norm $idNorma not found"]);
        exit;
    }
    
    if ($art) {
        // Search by article number in chunk_path or nombre_parte
        $stmt = $db->prepare(
            "SELECT * FROM legal_chunks WHERE norm_id = ? AND (
                chunk_path LIKE ? OR nombre_parte = ?
            ) ORDER BY ordering"
        );
        $artPattern = '%Art_' . $art . '%';
        $stmt->execute([$norm['id'], $artPattern, $art]);
    } else {
        // Return all chunks
        $stmt = $db->prepare("SELECT * FROM legal_chunks WHERE norm_id = ? ORDER BY ordering");
        $stmt->execute([$norm['id']]);
    }
    
    $chunks = $stmt->fetchAll();
    
    // Get sub-chunks for each main chunk
    foreach ($chunks as &$chunk) {
        $sub = $db->prepare("SELECT * FROM legal_chunks WHERE parent_chunk_id = ? ORDER BY ordering");
        $sub->execute([$chunk['id']]);
        $chunk['sub_chunks'] = $sub->fetchAll();
    }
    
    echo json_encode([
        'status' => 'ok',
        'id_norma' => $idNorma,
        'article' => $art ?: 'all',
        'count' => count($chunks),
        'chunks' => $chunks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
