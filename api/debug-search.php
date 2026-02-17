<?php
header('Content-Type: text/plain; charset=utf-8');

$query = $_GET['q'] ?? 'parcelas';
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
curl_close($ch);

echo "Query: $query\n";
echo "HTML Length: " . strlen($html) . "\n\n";

// Buscar clases de resultado
echo "=== Clases encontradas ===\n";
preg_match_all('/class="([^"]*result[^"]*)"/i', $html, $classes);
print_r(array_unique($classes[1]));

echo "\n=== Buscando <a con result__a ===\n";
preg_match_all('/<a[^>]+class="[^"]*result__a[^"]*"[^>]*>([^<]{0,100})/i', $html, $m1);
print_r($m1[1]);

echo "\n=== Buscando href con duckduckgo ===\n";
preg_match_all('/href="([^"]*duckduckgo[^"]*uddg[^"]*)"/i', $html, $m2);
print_r(array_slice($m2[1], 0, 5));

echo "\n=== Fragment donde aparece 'result' ===\n";
$pos = strpos($html, 'result__');
if ($pos !== false) {
    echo substr($html, $pos - 50, 500);
} else {
    echo "No se encontró 'result__' en el HTML\n";
    echo "\n=== Buscando otras estructuras ===\n";
    $pos2 = strpos($html, '<div class="links');
    if ($pos2 !== false) {
        echo substr($html, $pos2, 500);
    }
}
