<?php
/**
 * Test endpoint para verificar búsqueda web
 */
require_once __DIR__ . '/search.php';

header('Content-Type: application/json; charset=utf-8');

$query = $_GET['q'] ?? 'parcelas melipeuco';

$results = searchWeb($query, 5);

echo json_encode([
    'query' => $query,
    'results' => $results,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
