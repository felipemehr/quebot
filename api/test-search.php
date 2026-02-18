<?php
/**
 * Search test endpoint - for debugging only
 */
header('Content-Type: application/json');

require_once 'search.php';

$query = isset($_GET['q']) ? $_GET['q'] : 'noticias de Chile hoy';

$result = performWebSearch($query);

echo json_encode([
    'query' => $query,
    'resultCount' => count($result['results']),
    'results' => $result['results'],
    'error' => isset($result['error']) ? $result['error'] : null
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
