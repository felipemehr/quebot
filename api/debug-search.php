<?php
/**
 * Debug: ver HTML de DuckDuckGo
 */
header('Content-Type: text/plain; charset=utf-8');

$query = $_GET['q'] ?? 'parcelas melipeuco';
$url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_TIMEOUT => 15
]);

$html = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Query: $query\n";
echo "URL: $url\n";
echo "HTTP Code: $httpCode\n";
echo "Error: $error\n\n";
echo "=== Primeros 5000 chars del HTML ===\n\n";
echo substr($html, 0, 5000);
