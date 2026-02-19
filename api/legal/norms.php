<?php
/**
 * GET /api/legal/norms
 * Lists all norms in the core set with metadata
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../services/legal/database.php';

try {
    $db = LegalDatabase::getConnection();
    
    $coreOnly = ($_GET['core'] ?? '1') === '1';
    $sql = "SELECT n.*, 
            (SELECT COUNT(*) FROM legal_chunks c WHERE c.norm_id = n.id) as chunk_count,
            (SELECT v.fecha_version FROM legal_versions v WHERE v.norm_id = n.id AND v.status = 'active' ORDER BY v.fetched_at DESC LIMIT 1) as latest_version_date,
            (SELECT v.article_count FROM legal_versions v WHERE v.norm_id = n.id AND v.status = 'active' ORDER BY v.fetched_at DESC LIMIT 1) as article_count
            FROM legal_norms n";
    
    if ($coreOnly) {
        $sql .= " WHERE n.in_core_set = TRUE";
    }
    $sql .= " ORDER BY n.tipo, n.numero";
    
    $stmt = $db->query($sql);
    $norms = $stmt->fetchAll();
    
    // Clean up for JSON output
    foreach ($norms as &$norm) {
        $norm['materias'] = $norm['materias'] ? 
            array_filter(str_getcsv(trim($norm['materias'], '{}'), ',', '"')) : [];
        $norm['nombres_uso_comun'] = $norm['nombres_uso_comun'] ? 
            array_filter(str_getcsv(trim($norm['nombres_uso_comun'], '{}'), ',', '"')) : [];
    }
    
    echo json_encode([
        'status' => 'ok',
        'count' => count($norms),
        'norms' => $norms,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
