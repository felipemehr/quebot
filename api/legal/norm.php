<?php
/**
 * GET /api/legal/norm?idNorma=141599
 * Returns norm metadata + version history
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../services/legal/database.php';

$idNorma = $_GET['idNorma'] ?? '';
if (!$idNorma) {
    http_response_code(400);
    echo json_encode(['error' => 'idNorma parameter required']);
    exit;
}

try {
    $db = LegalDatabase::getConnection();
    
    $stmt = $db->prepare("SELECT * FROM legal_norms WHERE id_norma = ?");
    $stmt->execute([$idNorma]);
    $norm = $stmt->fetch();
    
    if (!$norm) {
        http_response_code(404);
        echo json_encode(['error' => "Norm $idNorma not found"]);
        exit;
    }
    
    // Parse arrays
    $norm['materias'] = $norm['materias'] ? 
        array_filter(str_getcsv(trim($norm['materias'], '{}'), ',', '"')) : [];
    $norm['nombres_uso_comun'] = $norm['nombres_uso_comun'] ? 
        array_filter(str_getcsv(trim($norm['nombres_uso_comun'], '{}'), ',', '"')) : [];
    
    // Get versions
    $stmt = $db->prepare("SELECT * FROM legal_versions WHERE norm_id = ? ORDER BY fetched_at DESC");
    $stmt->execute([$norm['id']]);
    $versions = $stmt->fetchAll();
    
    // Get top-level chunk structure
    $stmt = $db->prepare(
        "SELECT id, chunk_type, chunk_path, nombre_parte, titulo_parte, char_count, derogado 
         FROM legal_chunks WHERE norm_id = ? AND parent_chunk_id IS NULL ORDER BY ordering"
    );
    $stmt->execute([$norm['id']]);
    $structure = $stmt->fetchAll();
    
    echo json_encode([
        'status' => 'ok',
        'norm' => $norm,
        'versions' => $versions,
        'structure' => $structure,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
