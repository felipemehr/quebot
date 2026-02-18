<?php
/**
 * QueBot Legal Library - Migration Runner
 * POST /api/legal/migrate - Runs pending migrations
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

require_once __DIR__ . '/../services/legal/database.php';

try {
    $migrationsDir = __DIR__ . '/../services/legal/migrations';
    $files = glob($migrationsDir . '/*.sql');
    sort($files);
    
    $results = [];
    foreach ($files as $file) {
        $results[] = LegalDatabase::migrate($file);
    }
    
    $tables = LegalDatabase::checkTables();
    
    echo json_encode([
        'status' => 'ok',
        'migrations' => $results,
        'tables' => $tables
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
