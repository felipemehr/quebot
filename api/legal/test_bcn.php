<?php
header('Content-Type: application/json');
$token = $_GET['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($token !== getenv('ADMIN_TOKEN')) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
error_reporting(E_ALL); ini_set('display_errors', 0);
try {
    require_once __DIR__ . '/../../services/legal/database.php';
    require_once __DIR__ . '/../../services/legal/BcnConnector.php';
    require_once __DIR__ . '/../../services/legal/LegalChunker.php';
    $results = ['steps' => []];
    $action = $_GET['action'] ?? 'test';
    if ($action === 'reset_sync') {
        $db = LegalDatabase::getConnection();
        $db->exec("UPDATE legal_sync_runs SET status='cancelled', finished_at=NOW() WHERE status='running'");
        $results['reset'] = 'done';
        echo json_encode($results, JSON_PRETTY_PRINT);
        exit;
    }
    $connector = new BcnConnector();
    $idNorma = $_GET['idNorma'] ?? '141599';
    $fetchResult = $connector->fetchNorm($idNorma);
    $results['fetch'] = ['http' => $fetchResult['http_code'], 'error' => $fetchResult['error'], 'xml_len' => strlen($fetchResult['xml'] ?? '')];
    if (!empty($fetchResult['xml'])) {
        $parsed = $connector->parseNorm($fetchResult['xml']);
        $results['parse'] = ['id' => $parsed['id_norma'] ?? null, 'titulo' => $parsed['titulo'] ?? null, 'tipo' => $parsed['tipo'] ?? null, 'numero' => $parsed['numero'] ?? null, 'organismo' => $parsed['organismo'] ?? null, 'articles' => count($parsed['articles'] ?? []), 'error' => $parsed['error'] ?? null];
        $results['article_tree'] = [];
        foreach (($parsed['articles'] ?? []) as $i => $art) {
            $artInfo = ['tipo' => $art['tipo_parte'] ?? '', 'nombre' => $art['nombre_parte'] ?? '', 'text_len' => strlen($art['texto'] ?? ''), 'children' => count($art['children'] ?? [])];
            if (!empty($art['children'])) {
                $artInfo['first_children'] = [];
                foreach (array_slice($art['children'], 0, 3) as $child) {
                    $artInfo['first_children'][] = ['tipo' => $child['tipo_parte'] ?? '', 'nombre' => $child['nombre_parte'] ?? '', 'text_len' => strlen($child['texto'] ?? ''), 'children' => count($child['children'] ?? [])];
                }
            }
            $results['article_tree'][] = $artInfo;
            if ($i >= 4) { $results['article_tree'][] = '...truncated...'; break; }
        }
        if (empty($parsed['error']) && !empty($parsed['articles'])) {
            $chunker = new LegalChunker();
            $chunks = $chunker->chunkArticles($parsed['articles']);
            $results['chunks'] = ['total' => count($chunks)];
            $withText = array_filter($chunks, fn($c) => strlen($c['texto_plain'] ?? '') > 0);
            $results['chunks']['with_text'] = count($withText);
            if (!empty($chunks)) {
                $results['chunks']['first_3'] = array_map(fn($c) => ['path' => $c['chunk_path'] ?? null, 'type' => $c['chunk_type'] ?? null, 'text_len' => strlen($c['texto_plain'] ?? '')], array_slice($chunks, 0, 3));
            }
        }
    }
    $results['status'] = 'ok';
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}
