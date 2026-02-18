<?php
/**
 * QueBot Legal Library - Database Connection
 * Uses DATABASE_URL environment variable (Railway PostgreSQL)
 */

class LegalDatabase {
    private static ?PDO $instance = null;
    
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $url = getenv('DATABASE_URL');
            if (!$url) {
                throw new RuntimeException('DATABASE_URL environment variable not set');
            }
            
            $parts = parse_url($url);
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $parts['host'],
                $parts['port'] ?? 5432,
                ltrim($parts['path'] ?? '/railway', '/')
            );
            
            self::$instance = new PDO($dsn, $parts['user'] ?? '', $parts['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }
    
    /**
     * Run a migration SQL file
     */
    public static function migrate(string $sqlFile): array {
        $db = self::getConnection();
        $sql = file_get_contents($sqlFile);
        
        if ($sql === false) {
            throw new RuntimeException("Cannot read migration file: $sqlFile");
        }
        
        try {
            $db->exec($sql);
            return ['status' => 'ok', 'file' => basename($sqlFile)];
        } catch (PDOException $e) {
            return ['status' => 'error', 'file' => basename($sqlFile), 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if tables exist
     */
    public static function checkTables(): array {
        $db = self::getConnection();
        $tables = ['legal_sources', 'legal_norms', 'legal_versions', 'legal_chunks', 'legal_sync_runs'];
        $result = [];
        
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_name = ?");
            $stmt->execute([$table]);
            $row = $stmt->fetch();
            $result[$table] = ($row['cnt'] > 0);
        }
        
        return $result;
    }
    
    /**
     * Get table row counts
     */
    public static function getStats(): array {
        $db = self::getConnection();
        $stats = [];
        $tables = ['legal_sources', 'legal_norms', 'legal_versions', 'legal_chunks', 'legal_sync_runs'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT COUNT(*) as cnt FROM $table");
                $stats[$table] = (int)$stmt->fetchColumn();
            } catch (PDOException $e) {
                $stats[$table] = -1; // table doesn't exist
            }
        }
        
        return $stats;
    }
}
