<?php
/**
 * POST /api/legal/sync - Run full sync
 * POST /api/legal/sync?idNorma=141599 - Sync single norm
 * Protected by ADMIN_TOKEN
 */

header('Content-Type: application/json');

// Auth check
$adminToken = getenv('ADMIN_TOKEN');
if (!$adminToken) {
    http_response_code(500);
    echo json_encode(['error' => 'ADMIN_TOKEN not configured']);
    exit;
}

$providedToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_GET['token'] ?? '';
if ($providedToken !== $adminToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../services/legal/LegalSync.php';

try {
    $sync = new LegalSync();
    $idNorma = $_GET['idNorma'] ?? '';
    $triggerType = $_GET['trigger'] ?? 'api';
    
    if ($idNorma) {
        $result = $sync->syncOne($idNorma, $triggerType);
    } else {
        $result = $sync->syncAll($triggerType);
    }
    
    echo json_encode([
        'status' => 'ok',
        'sync_run' => $result,
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
