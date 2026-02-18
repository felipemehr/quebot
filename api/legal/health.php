<?php
/**
 * GET /api/legal/health
 * Public health check endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../services/legal/database.php';

try {
    $stats = LegalDatabase::getStats();
    $tables = LegalDatabase::checkTables();
    
    // Get last sync info
    $db = LegalDatabase::getConnection();
    $lastSync = null;
    try {
        $stmt = $db->query("SELECT * FROM legal_sync_runs ORDER BY started_at DESC LIMIT 1");
        $lastSync = $stmt->fetch();
    } catch (Exception $e) {}
    
    $allTablesExist = !in_array(false, $tables, true);
    
    echo json_encode([
        'status' => $allTablesExist ? 'ok' : 'not_initialized',
        'database' => 'connected',
        'tables' => $tables,
        'counts' => $stats,
        'last_sync' => $lastSync ? [
            'id' => $lastSync['id'],
            'status' => $lastSync['status'],
            'started_at' => $lastSync['started_at'],
            'finished_at' => $lastSync['finished_at'],
            'norms_checked' => $lastSync['norms_checked'],
            'norms_updated' => $lastSync['norms_updated'],
            'summary' => $lastSync['summary'],
        ] : null,
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'database' => 'disconnected',
        'error' => $e->getMessage(),
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT);
}
