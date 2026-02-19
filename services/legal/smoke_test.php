<?php
/**
 * QueBot Legal Library - Smoke Test
 * 
 * Usage: php smoke_test.php <base_url> <admin_token>
 * Example: php smoke_test.php https://quebot-production.up.railway.app my_secret_token
 */

$baseUrl = $argv[1] ?? 'https://quebot-production.up.railway.app';
$adminToken = $argv[2] ?? '';

$baseUrl = rtrim($baseUrl, '/');
$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    echo "  [$name] ";
    try {
        $result = $fn();
        if ($result === true) {
            echo "✅ PASS\n";
            $passed++;
        } else {
            echo "❌ FAIL: $result\n";
            $failed++;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function httpGet(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'QueBot-SmokeTest/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

function httpPost(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'QueBot-SmokeTest/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

echo "\n🧪 QueBot Legal Library - Smoke Tests\n";
echo "Base URL: $baseUrl\n\n";

// Test 1: Health endpoint
echo "1. Health Check\n";
test("GET /api/legal/health returns 200", function() use ($baseUrl) {
    $r = httpGet("$baseUrl/api/legal/health");
    return $r['code'] === 200 ? true : "HTTP {$r['code']}";
});
test("Health shows database connected", function() use ($baseUrl) {
    $r = httpGet("$baseUrl/api/legal/health");
    return ($r['json']['database'] ?? '') === 'connected' ? true : "DB not connected";
});

if (!$adminToken) {
    echo "\n⚠️  No ADMIN_TOKEN provided - skipping protected endpoint tests\n";
    echo "Usage: php smoke_test.php $baseUrl YOUR_ADMIN_TOKEN\n\n";
} else {
    // Test 2: Migration
    echo "\n2. Database Migration\n";
    test("POST /api/legal/migrate runs OK", function() use ($baseUrl, $adminToken) {
        $r = httpPost("$baseUrl/api/legal/migrate?token=$adminToken");
        return ($r['json']['status'] ?? '') === 'ok' ? true : "Status: " . ($r['json']['status'] ?? $r['code']);
    });
    test("All tables exist after migration", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/health");
        $tables = $r['json']['tables'] ?? [];
        $missing = array_filter($tables, fn($v) => !$v);
        return empty($missing) ? true : "Missing: " . implode(', ', array_keys($missing));
    });
    
    // Test 3: Sync single norm
    echo "\n3. Sync Single Norm (Ley 19628)\n";
    test("POST /api/legal/sync?idNorma=141599 succeeds", function() use ($baseUrl, $adminToken) {
        $r = httpPost("$baseUrl/api/legal/sync?idNorma=141599&token=$adminToken");
        $status = $r['json']['sync_run']['status'] ?? '';
        return in_array($status, ['completed', 'partial']) ? true : "Status: $status - " . json_encode($r['json']);
    });
    
    // Test 4: Verify data was stored
    echo "\n4. Verify Stored Data\n";
    test("GET /api/legal/norms returns norms", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/norms");
        return ($r['json']['count'] ?? 0) > 0 ? true : "No norms found";
    });
    test("GET /api/legal/norm?idNorma=141599 returns data", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/norm?idNorma=141599");
        return !empty($r['json']['norm']['titulo']) ? true : "No titulo";
    });
    test("Norm has chunks", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/norm?idNorma=141599");
        return count($r['json']['structure'] ?? []) > 0 ? true : "No chunks";
    });
    test("GET /api/legal/article?idNorma=141599&art=1 returns text", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/article?idNorma=141599&art=1");
        return ($r['json']['count'] ?? 0) > 0 ? true : "No article chunks";
    });
    
    // Test 5: Search
    echo "\n5. Full-Text Search\n";
    test("GET /api/legal/search?q=datos+personales returns results", function() use ($baseUrl) {
        $r = httpGet("$baseUrl/api/legal/search?q=datos+personales");
        return ($r['json']['total'] ?? 0) > 0 ? true : "No search results";
    });
}

// Summary
echo "\n" . str_repeat("─", 50) . "\n";
echo "Results: $passed passed, $failed failed\n";
echo $failed === 0 ? "✅ All tests passed!\n" : "❌ Some tests failed\n";
exit($failed > 0 ? 1 : 0);
