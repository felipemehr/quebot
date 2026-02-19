<?php
/**
 * QueBot Legal Library - Sync Runner
 * Orchestrates: fetch from BCN → parse → chunk → store in DB
 * Detects changes via text hash comparison
 * Uses DB transactions for consistency
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/BcnConnector.php';
require_once __DIR__ . '/LegalChunker.php';

class LegalSync {
    
    private PDO $db;
    private BcnConnector $connector;
    private LegalChunker $chunker;
    private int $runId;
    private array $stats;
    
    public function __construct() {
        $this->db = LegalDatabase::getConnection();
        $this->connector = new BcnConnector();
        $this->chunker = new LegalChunker();
        $this->stats = [
            'norms_checked' => 0,
            'norms_updated' => 0,
            'norms_failed' => 0,
            'chunks_created' => 0,
            'chunks_deleted' => 0,
            'errors' => [],
        ];
    }
    
    /**
     * Run sync for all core norms
     * @param string $triggerType 'cron', 'manual', or 'api'
     * @return array Summary
     */
    public function syncAll(string $triggerType = 'manual'): array {
        $this->startRun($triggerType);
        
        try {
            // Get core norms list
            $coreNorms = $this->getCoreNorms();
            
            if (empty($coreNorms)) {
                // If no norms in DB, seed from environment
                $coreNorms = $this->seedCoreNorms();
            }
            
            foreach ($coreNorms as $norm) {
                $this->syncNorm($norm);
                // Be nice to BCN servers
                usleep(500000); // 0.5s between requests
            }
            
            $this->finishRun('completed');
        } catch (Exception $e) {
            $this->stats['errors'][] = [
                'norm_id' => null,
                'error' => $e->getMessage(),
                'timestamp' => date('c'),
            ];
            $this->finishRun('failed');
        }
        
        return $this->getRunSummary();
    }
    
    /**
     * Sync a single norm by idNorma
     * @param string $idNorma BCN norm ID
     * @param string $triggerType 'cron', 'manual', or 'api'
     * @return array Result
     */
    public function syncOne(string $idNorma, string $triggerType = 'manual'): array {
        $this->startRun($triggerType);
        
        // Check if norm exists, if not create it
        $stmt = $this->db->prepare("SELECT * FROM legal_norms WHERE id_norma = ?");
        $stmt->execute([$idNorma]);
        $norm = $stmt->fetch();
        
        if (!$norm) {
            // Create a placeholder norm entry
            $sourceId = $this->getSourceId('bcn_leychile');
            $stmt = $this->db->prepare("INSERT INTO legal_norms (source_id, id_norma, in_core_set) VALUES (?, ?, TRUE) RETURNING *");
            $stmt->execute([$sourceId, $idNorma]);
            $norm = $stmt->fetch();
        }
        
        $this->syncNorm($norm);
        $this->finishRun($this->stats['norms_failed'] > 0 ? 'partial' : 'completed');
        
        return $this->getRunSummary();
    }
    
    /**
     * Sync a single norm (fetch, parse, compare, store)
     * Steps 1-3 are read-only (fetch, parse, compare hash).
     * Steps 4-8 modify DB and are wrapped in a transaction for consistency.
     */
    private function syncNorm(array $norm): void {
        $this->stats['norms_checked']++;
        $idNorma = $norm['id_norma'];
        
        try {
            // --- READ-ONLY PHASE (no transaction needed) ---
            
            // 1. Fetch XML from BCN
            $result = $this->connector->fetchNorm($idNorma);
            if ($result['error']) {
                throw new RuntimeException("BCN fetch failed: {$result['error']}");
            }
            
            // 2. Parse XML
            $parsed = $this->connector->parseNorm($result['xml']);
            if (isset($parsed['error'])) {
                throw new RuntimeException("XML parse failed: {$parsed['error']}");
            }
            
            // 3. Check if changed (compare hash)
            $currentHash = $this->getCurrentHash($norm['id']);
            if ($currentHash === $parsed['text_hash']) {
                // No changes - skip
                return;
            }
            
            // --- WRITE PHASE (wrapped in transaction) ---
            
            $this->db->beginTransaction();
            
            try {
                // 4. Update norm metadata
                $this->updateNormMetadata($norm['id'], $parsed);
                
                // 5. Create new version
                $versionId = $this->createVersion($norm['id'], $parsed);
                
                // 6. Delete old chunks for this norm
                $deleted = $this->deleteOldChunks($norm['id']);
                $this->stats['chunks_deleted'] += $deleted;
                
                // 7. Chunk and store articles
                $chunks = $this->chunker->chunkArticles($parsed['articles']);
                $created = $this->storeChunks($chunks, $versionId, $norm['id']);
                $this->stats['chunks_created'] += $created;
                
                // 8. Mark previous versions as superseded
                $this->supersedePreviousVersions($norm['id'], $versionId);
                
                $this->db->commit();
                $this->stats['norms_updated']++;
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e; // Re-throw to be caught by outer catch
            }
            
        } catch (Exception $e) {
            $this->stats['norms_failed']++;
            $this->stats['errors'][] = [
                'norm_id' => $idNorma,
                'error' => $e->getMessage(),
                'timestamp' => date('c'),
            ];
        }
    }
    
    /**
     * Get current text hash for change detection
     */
    private function getCurrentHash(int $normId): ?string {
        $stmt = $this->db->prepare(
            "SELECT text_hash FROM legal_versions WHERE norm_id = ? AND status = 'active' ORDER BY fetched_at DESC LIMIT 1"
        );
        $stmt->execute([$normId]);
        $row = $stmt->fetch();
        return $row ? $row['text_hash'] : null;
    }
    
    /**
     * Update norm metadata from parsed data
     */
    private function updateNormMetadata(int $normId, array $parsed): void {
        $stmt = $this->db->prepare("UPDATE legal_norms SET 
            tipo = ?, numero = ?, titulo = ?, organismo = ?,
            fecha_publicacion = ?, fecha_promulgacion = ?,
            url_canonica = ?, estado = ?, es_tratado = ?,
            materias = ?, nombres_uso_comun = ?,
            updated_at = NOW()
            WHERE id = ?");
        
        $stmt->execute([
            $parsed['tipo'] ?? null,
            $parsed['numero'] ?? null,
            $parsed['titulo'] ?? null,
            $parsed['organismo'] ?? null,
            $parsed['fecha_publicacion'] ?: null,
            $parsed['fecha_promulgacion'] ?: null,
            $parsed['url_canonica'] ?? null,
            $parsed['derogado'] ? 'derogado' : 'vigente',
            $parsed['es_tratado'] ? 't' : 'f',
            '{' . implode(',', array_map(function($m) { return '"' . str_replace('"', '\\"', $m) . '"'; }, $parsed['materias'] ?? [])) . '}',
            '{' . implode(',', array_map(function($n) { return '"' . str_replace('"', '\\"', $n) . '"'; }, $parsed['nombres_uso_comun'] ?? [])) . '}',
            $normId,
        ]);
    }
    
    /**
     * Create a new version record
     */
    private function createVersion(int $normId, array $parsed): int {
        $stmt = $this->db->prepare("INSERT INTO legal_versions 
            (norm_id, fecha_version, text_hash, xml_size, article_count, status)
            VALUES (?, ?, ?, ?, ?, 'active')
            RETURNING id");
        
        $articleCount = $this->countArticles($parsed['articles']);
        
        $stmt->execute([
            $normId,
            $parsed['fecha_version'] ?: date('Y-m-d'),
            $parsed['text_hash'],
            $parsed['xml_size'],
            $articleCount,
        ]);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Count articles recursively
     */
    private function countArticles(array $items): int {
        $count = 0;
        foreach ($items as $item) {
            $tipo = $item['tipo_parte'] ?? '';
            if (in_array($tipo, ['Artículo', 'Artículo Transitorio', 'Disposición Transitoria'])) {
                $count++;
            }
            $count += $this->countArticles($item['children'] ?? []);
        }
        return $count;
    }
    
    /**
     * Delete old chunks for a norm
     */
    private function deleteOldChunks(int $normId): int {
        $stmt = $this->db->prepare("DELETE FROM legal_chunks WHERE norm_id = ?");
        $stmt->execute([$normId]);
        return $stmt->rowCount();
    }
    
    /**
     * Store chunks in DB (recursive for parent-child)
     */
    private function storeChunks(array $chunks, int $versionId, int $normId, ?int $parentId = null): int {
        $count = 0;
        
        foreach ($chunks as $chunk) {
            $stmt = $this->db->prepare("INSERT INTO legal_chunks 
                (version_id, norm_id, chunk_type, chunk_path, id_parte, nombre_parte, 
                 titulo_parte, texto, texto_plain, parent_chunk_id, ordering, 
                 derogado, transitorio, char_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id");
            
            $textoPlain = $chunk['texto_plain'] ?? $chunk['texto'];
            
            $stmt->execute([
                $versionId,
                $normId,
                $chunk['chunk_type'],
                $chunk['chunk_path'],
                $chunk['id_parte'],
                $chunk['nombre_parte'],
                $chunk['titulo_parte'],
                $chunk['texto'],
                $textoPlain,
                $parentId,
                $chunk['ordering'],
                $chunk['derogado'] ? 't' : 'f',
                $chunk['transitorio'] ? 't' : 'f',
                strlen($textoPlain),
            ]);
            
            $chunkId = (int)$stmt->fetchColumn();
            $count++;
            
            // Store children
            if (!empty($chunk['children'])) {
                $count += $this->storeChunks($chunk['children'], $versionId, $normId, $chunkId);
            }
        }
        
        return $count;
    }
    
    /**
     * Mark previous versions as superseded
     */
    private function supersedePreviousVersions(int $normId, int $currentVersionId): void {
        $stmt = $this->db->prepare(
            "UPDATE legal_versions SET status = 'superseded' WHERE norm_id = ? AND id != ? AND status = 'active'"
        );
        $stmt->execute([$normId, $currentVersionId]);
    }
    
    /**
     * Get all core norms from DB
     */
    private function getCoreNorms(): array {
        $stmt = $this->db->query("SELECT * FROM legal_norms WHERE in_core_set = TRUE ORDER BY id");
        return $stmt->fetchAll();
    }
    
    /**
     * Seed core norms from LEGAL_CORE_NORMS env variable
     */
    private function seedCoreNorms(): array {
        $coreNormsEnv = getenv('LEGAL_CORE_NORMS');
        if (!$coreNormsEnv) {
            // Default core set: important Chilean laws
            $defaultNorms = [
                '141599',  // Ley 19628 - Protección de datos personales
                '242302',  // Ley 20285 - Transparencia y acceso a la información pública
                '29726',   // DFL 1 Código Civil
                '276268',  // Ley 21131 - Pago a 30 días
            ];
        } else {
            // Parse JSON array or CSV
            $defaultNorms = json_decode($coreNormsEnv, true);
            if (!is_array($defaultNorms)) {
                $defaultNorms = array_map('trim', explode(',', $coreNormsEnv));
            }
        }
        
        $sourceId = $this->getSourceId('bcn_leychile');
        $norms = [];
        
        foreach ($defaultNorms as $idNorma) {
            $idNorma = trim($idNorma);
            if (empty($idNorma)) continue;
            
            $stmt = $this->db->prepare(
                "INSERT INTO legal_norms (source_id, id_norma, in_core_set) 
                 VALUES (?, ?, TRUE) 
                 ON CONFLICT (source_id, id_norma) DO UPDATE SET in_core_set = TRUE
                 RETURNING *"
            );
            $stmt->execute([$sourceId, $idNorma]);
            $norms[] = $stmt->fetch();
        }
        
        return $norms;
    }
    
    /**
     * Get source ID for a given code
     */
    private function getSourceId(string $code): int {
        $stmt = $this->db->prepare("SELECT id FROM legal_sources WHERE code = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Source '$code' not found. Run migrations first.");
        }
        return (int)$row['id'];
    }
    
    // --- Run tracking ---
    
    private function startRun(string $triggerType): void {
        $stmt = $this->db->prepare(
            "INSERT INTO legal_sync_runs (trigger_type, status) VALUES (?, 'running') RETURNING id"
        );
        $stmt->execute([$triggerType]);
        $this->runId = (int)$stmt->fetchColumn();
    }
    
    private function finishRun(string $status): void {
        $stmt = $this->db->prepare("UPDATE legal_sync_runs SET 
            finished_at = NOW(), status = ?, 
            norms_checked = ?, norms_updated = ?, norms_failed = ?,
            chunks_created = ?, chunks_deleted = ?,
            errors = ?,
            summary = ?
            WHERE id = ?");
        
        $summary = sprintf(
            "Checked %d norms: %d updated, %d failed. Chunks: +%d -%d.",
            $this->stats['norms_checked'],
            $this->stats['norms_updated'],
            $this->stats['norms_failed'],
            $this->stats['chunks_created'],
            $this->stats['chunks_deleted']
        );
        
        $stmt->execute([
            $status,
            $this->stats['norms_checked'],
            $this->stats['norms_updated'],
            $this->stats['norms_failed'],
            $this->stats['chunks_created'],
            $this->stats['chunks_deleted'],
            json_encode($this->stats['errors']),
            $summary,
            $this->runId,
        ]);
    }
    
    private function getRunSummary(): array {
        $stmt = $this->db->prepare("SELECT * FROM legal_sync_runs WHERE id = ?");
        $stmt->execute([$this->runId]);
        return $stmt->fetch();
    }
}
