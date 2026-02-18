<?php
/**
 * GET /api/legal/search?q=datos+personales
 * Full-text search across legal chunks (PostgreSQL tsvector)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../services/legal/database.php';

$query = $_GET['q'] ?? '';
$limit = min((int)($_GET['limit'] ?? 20), 100);
$offset = max((int)($_GET['offset'] ?? 0), 0);

if (!$query) {
    http_response_code(400);
    echo json_encode(['error' => 'q parameter required']);
    exit;
}

try {
    $db = LegalDatabase::getConnection();
    
    // Use PostgreSQL full-text search with Spanish config
    $tsQuery = implode(' & ', array_filter(array_map('trim', explode(' ', $query))));
    
    $stmt = $db->prepare("
        SELECT c.id, c.chunk_type, c.chunk_path, c.nombre_parte, c.titulo_parte,
               c.texto_plain, c.char_count, c.derogado,
               n.id_norma, n.tipo, n.numero, n.titulo as norm_titulo, n.url_canonica,
               ts_rank(c.tsv, to_tsquery('spanish', ?)) as rank
        FROM legal_chunks c
        JOIN legal_norms n ON c.norm_id = n.id
        WHERE c.tsv @@ to_tsquery('spanish', ?)
        ORDER BY rank DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute([$tsQuery, $tsQuery, $limit, $offset]);
    $results = $stmt->fetchAll();
    
    // Also count total
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM legal_chunks c WHERE c.tsv @@ to_tsquery('spanish', ?)
    ");
    $countStmt->execute([$tsQuery]);
    $total = (int)$countStmt->fetchColumn();
    
    // Highlight matches in text (simple version)
    $queryWords = array_filter(explode(' ', strtolower($query)));
    foreach ($results as &$r) {
        $text = $r['texto_plain'];
        // Show snippet around first match
        $pos = false;
        foreach ($queryWords as $w) {
            $pos = mb_stripos($text, $w);
            if ($pos !== false) break;
        }
        $start = max(0, ($pos ?: 0) - 100);
        $r['snippet'] = '...' . mb_substr($text, $start, 300) . '...';
    }
    
    echo json_encode([
        'status' => 'ok',
        'query' => $query,
        'total' => $total,
        'count' => count($results),
        'offset' => $offset,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
