<?php
/**
 * QueBot Legal Library - Sync Test Endpoint
 * GET /api/legal/sync-test?idNorma=...&token=...
 * 
 * Processes a single norm and returns a JSON summary.
 * Protected by ADMIN_TOKEN.
 */

header('Content-Type: application/json; charset=utf-8');

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

// Auth check (same pattern as sync.php)
$adminToken = getenv('ADMIN_TOKEN');
if (!$adminToken) {
    http_response_code(500);
    echo json_encode(['error' => 'ADMIN_TOKEN not configured']);
    exit;
}

$providedToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_GET['token'] ?? '';
if ($providedToken !== $adminToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Provide ?token= or X-Admin-Token header.']);
    exit;
}

// Require idNorma
$idNorma = $_GET['idNorma'] ?? '';
if (empty($idNorma)) {
    echo json_encode([
        'error' => 'Missing required parameter: idNorma',
        'usage' => 'GET /api/legal/sync-test?idNorma=210676&token=YOUR_TOKEN',
        'known_norms' => [
            '172986' => 'Código Civil (DFL 1, 2000)',
            '22740' => 'Código de Procedimiento Civil',
            '13560' => 'DFL 458 Ley General de Urbanismo y Construcciones',
            '1174663' => 'Ley 21.442 Copropiedad Inmobiliaria',
            '210676' => 'Ley 19.880 Procedimiento Administrativo',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Extended timeout for large norms (Código Civil = 2.9MB, CPC = 57MB)
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../services/legal/LegalSync.php';

$startTime = microtime(true);

try {
    $sync = new LegalSync();
    $result = $sync->syncOne($idNorma, 'api-test');
    
    $durationMs = round((microtime(true) - $startTime) * 1000);
    
    // Get norm details for the response
    $db = LegalDatabase::getConnection();
    $stmt = $db->prepare("SELECT * FROM legal_norms WHERE id_norma = ?");
    $stmt->execute([$idNorma]);
    $norm = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get latest version info
    $versionInfo = null;
    if ($norm) {
        $stmt = $db->prepare("SELECT * FROM legal_versions WHERE norm_id = ? AND status = 'active' ORDER BY fetched_at DESC LIMIT 1");
        $stmt->execute([$norm['id']]);
        $versionInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get chunk count
    $chunkCount = 0;
    if ($norm) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM legal_chunks WHERE norm_id = ?");
        $stmt->execute([$norm['id']]);
        $chunkCount = (int)$stmt->fetchColumn();
    }
    
    echo json_encode([
        'norma' => $norm['titulo'] ?? "idNorma $idNorma",
        'id_norma' => $idNorma,
        'tipo' => $norm['tipo'] ?? null,
        'numero' => $norm['numero'] ?? null,
        'version_detected' => $versionInfo['fecha_version'] ?? null,
        'text_hash' => substr($versionInfo['text_hash'] ?? '', 0, 16) . '...',
        'article_count' => $versionInfo ? (int)$versionInfo['article_count'] : 0,
        'chunks_in_db' => $chunkCount,
        'xml_size_bytes' => $versionInfo ? (int)$versionInfo['xml_size'] : 0,
        'status' => $result['status'] ?? 'unknown',
        'norms_updated' => (int)($result['norms_updated'] ?? 0),
        'duration_ms' => $durationMs,
        'sync_run_id' => (int)($result['id'] ?? 0),
        'summary' => $result['summary'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $durationMs = round((microtime(true) - $startTime) * 1000);
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'id_norma' => $idNorma,
        'duration_ms' => $durationMs,
        'status' => 'error',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
