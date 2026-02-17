<?php
/**
 * QueBot - Render Storage API
 * Stores and serves dynamic HTML visualizations
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Directory to store renders
$renderDir = __DIR__ . '/../renders';
if (!is_dir($renderDir)) {
    mkdir($renderDir, 0755, true);
}

// Clean old renders (older than 24 hours)
function cleanOldRenders($dir) {
    $files = glob($dir . '/*.html');
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > 86400) {
            unlink($file);
        }
    }
}

function generateRenderId() {
    return 'r_' . bin2hex(random_bytes(8));
}

// GET: Serve a render
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['id']) : '';
    
    if (empty($id)) {
        http_response_code(400);
        echo 'Missing render ID';
        exit;
    }
    
    $file = $renderDir . '/' . $id . '.html';
    
    if (!file_exists($file)) {
        http_response_code(404);
        echo 'Render not found';
        exit;
    }
    
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
}

// POST: Store a render
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['html']) || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing html or type']);
        exit;
    }
    
    if (rand(1, 10) === 1) {
        cleanOldRenders($renderDir);
    }
    
    $id = generateRenderId();
    $type = preg_replace('/[^a-zA-Z0-9]/', '', $input['type']);
    $title = isset($input['title']) ? substr($input['title'], 0, 100) : 'Visualización';
    
    $fullHtml = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { 
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
        }
        #map { 
            width: 100% !important; 
            height: 100% !important; 
            min-height: 400px;
        }
        #chart-container { 
            width: 100%; 
            height: 100%; 
            padding: 20px; 
        }
        canvas { max-height: 100%; }
        
        /* Table styles */
        .table-container { width: 100%; height: 100%; overflow: auto; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .data-table th, .data-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .data-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
        }
        .data-table tr:hover {
            background: #f0fdf4;
        }
        .data-table a {
            color: #2563eb;
            text-decoration: none;
        }
        .data-table a:hover {
            text-decoration: underline;
        }
        .price {
            color: #059669;
            font-weight: 600;
        }
        
        /* Map popup styles */
        .leaflet-popup-content { min-width: 220px; max-width: 300px; }
        .popup-title { font-weight: bold; color: #2d5a3d; margin-bottom: 8px; font-size: 14px; }
        .popup-price { font-size: 18px; font-weight: bold; color: #059669; margin-bottom: 6px; }
        .popup-size { color: #666; margin-bottom: 8px; font-size: 13px; }
        .popup-desc { font-size: 12px; color: #444; margin-bottom: 10px; line-height: 1.4; }
        .popup-link { 
            display: inline-block; 
            background: #2563eb; 
            color: white !important; 
            padding: 6px 12px; 
            border-radius: 4px; 
            text-decoration: none; 
            font-size: 12px;
        }
        .popup-link:hover { background: #1d4ed8; }
        
        /* Legend */
        .legend {
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            font-size: 13px;
            line-height: 1.8;
        }
        .legend h4 { margin-bottom: 8px; color: #333; font-size: 14px; }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .legend-color {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
' . $input['html'] . '
</body>
</html>';
    
    $file = $renderDir . '/' . $id . '.html';
    file_put_contents($file, $fullHtml);
    
    echo json_encode([
        'success' => true,
        'id' => $id,
        'url' => 'api/render.php?id=' . $id,
        'type' => $type,
        'title' => $title
    ]);
    exit;
}

http_response_code(405);
echo 'Method not allowed';
