<?php
/**
 * File-based search cache. TTL: 6 hours.
 * Keyed by (vertical + query) SHA256 hash.
 */
class SearchCache {
    private string $cacheDir;
    private int $ttlSeconds;

    public function __construct(string $cacheDir = '/tmp/quebot_search_cache', int $ttlHours = 6) {
        $this->cacheDir = $cacheDir;
        $this->ttlSeconds = $ttlHours * 3600;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    private function keyPath(string $vertical, string $query): string {
        $hash = hash('sha256', strtolower(trim($vertical)) . '|' . strtolower(trim($query)));
        return $this->cacheDir . '/' . $hash . '.json';
    }

    public function get(string $vertical, string $query): ?array {
        $path = $this->keyPath($vertical, $query);
        if (!file_exists($path)) return null;

        $mtime = filemtime($path);
        if (time() - $mtime > $this->ttlSeconds) {
            @unlink($path);
            return null;
        }

        $data = @file_get_contents($path);
        if (!$data) return null;

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $vertical, string $query, array $results): void {
        $path = $this->keyPath($vertical, $query);
        $json = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($path, $json, LOCK_EX);
    }

    /** Purge all expired entries */
    public function purge(): int {
        $count = 0;
        $files = glob($this->cacheDir . '/*.json');
        if (!$files) return 0;
        foreach ($files as $f) {
            if (time() - filemtime($f) > $this->ttlSeconds) {
                @unlink($f);
                $count++;
            }
        }
        return $count;
    }
}
