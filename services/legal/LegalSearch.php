<?php
/**
 * QueBot Legal Library - Search Service
 * Queries PostgreSQL full-text search for legal content
 */

require_once __DIR__ . '/database.php';

class LegalSearch {
    
    /**
     * Detect if a message has legal intent
     */
    public static function hasLegalIntent(string $message): bool {
        $lower = mb_strtolower($message);
        
        // Direct law references: "ley 19628", "artículo 12", "DFL 1"
        if (preg_match('/\b(ley|decreto|dfl|dl|ds)\s*n?[°º]?\s*\d+/i', $message)) return true;
        if (preg_match('/\bart[ií]culo\s+\d+/i', $message)) return true;
        
        // Legal topic keywords
        $legalKeywords = [
            'protección de datos', 'datos personales', 'habeas data',
            'transparencia', 'acceso a información', 'información pública',
            'derechos del consumidor', 'consumidor', 'garantía legal', 'sernac',
            'copropiedad inmobiliaria', 'condominio', 'reglamento copropiedad',
            'norma legal', 'normativa', 'legislación', 'ley chilena',
            'derecho a', 'derecho de', 'derechos fundamentales',
            'código civil', 'código penal', 'código del trabajo',
            'regulación', 'regulado por ley', 'según la ley',
            'jornada laboral', '40 horas', 'jornada de trabajo',
            'urbanismo', 'construcción', 'permiso de edificación',
            'privacidad', 'vida privada',
        ];
        
        foreach ($legalKeywords as $kw) {
            if (strpos($lower, $kw) !== false) return true;
        }
        
        return false;
    }
    
    /**
     * Extract law number from message if present
     */
    public static function extractLawReference(string $message): ?array {
        if (preg_match('/\b(ley|decreto|dfl|dl|ds)\s*n?[°º]?\s*([\d.]+)/i', $message, $m)) {
            $tipo = strtoupper($m[1]);
            $numero = str_replace('.', '', $m[2]);
            return ['tipo' => $tipo, 'numero' => $numero];
        }
        return null;
    }
    
    /**
     * Full-text search across all legal chunks
     */
    public static function search(string $query, int $limit = 8): array {
        try {
            $db = LegalDatabase::getConnection();
            $stmt = $db->prepare("
                SELECT 
                    c.id, c.chunk_type, c.chunk_path, c.nombre_parte,
                    c.texto_plain, c.char_count,
                    n.tipo, n.numero, n.titulo AS norm_titulo, n.url_canonica,
                    ts_rank(c.tsv, plainto_tsquery('spanish', :query)) AS rank
                FROM legal_chunks c
                JOIN legal_norms n ON c.norm_id = n.id
                WHERE c.tsv @@ plainto_tsquery('spanish', :query2)
                  AND c.chunk_type IN ('article', 'inciso', 'transitory')
                ORDER BY rank DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':query', $query);
            $stmt->bindValue(':query2', $query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("LegalSearch::search error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search chunks for a specific law
     */
    public static function searchByNorm(string $numero, int $limit = 15): array {
        try {
            $db = LegalDatabase::getConnection();
            $stmt = $db->prepare("
                SELECT 
                    c.chunk_type, c.chunk_path, c.nombre_parte, c.texto_plain, c.char_count,
                    n.tipo, n.numero, n.titulo AS norm_titulo, n.url_canonica
                FROM legal_chunks c
                JOIN legal_norms n ON c.norm_id = n.id
                WHERE n.numero = :numero
                  AND c.chunk_type IN ('article', 'transitory')
                ORDER BY c.ordering
                LIMIT :limit
            ");
            $stmt->bindValue(':numero', $numero);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("LegalSearch::searchByNorm error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get a specific article from a law
     */
    public static function getArticle(string $numero, string $artNum): array {
        try {
            $db = LegalDatabase::getConnection();
            $stmt = $db->prepare("
                SELECT 
                    c.chunk_type, c.chunk_path, c.nombre_parte, c.texto_plain,
                    n.tipo, n.numero, n.titulo AS norm_titulo, n.url_canonica
                FROM legal_chunks c
                JOIN legal_norms n ON c.norm_id = n.id
                WHERE n.numero = :numero
                  AND c.nombre_parte = :art
                  AND c.chunk_type = 'article'
            ");
            $stmt->bindValue(':numero', $numero);
            $stmt->bindValue(':art', $artNum);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("LegalSearch::getArticle error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Build context string for Claude from legal search results
     */
    public static function buildContext(string $message): string {
        $context = '';
        $results = [];
        
        // 1. Check for specific law reference
        $lawRef = self::extractLawReference($message);
        if ($lawRef) {
            // Check for specific article
            if (preg_match('/art[ií]culo\s+(\d+)/i', $message, $artMatch)) {
                $results = self::getArticle($lawRef['numero'], $artMatch[1]);
                if (!empty($results)) {
                    $context .= "\n\n📚 BIBLIOTECA LEGAL - Artículo específico:\n";
                }
            }
            // If no specific article or no results, get law overview
            if (empty($results)) {
                $results = self::searchByNorm($lawRef['numero']);
                if (!empty($results)) {
                    $context .= "\n\n📚 BIBLIOTECA LEGAL - {$lawRef['tipo']} {$lawRef['numero']}:\n";
                }
            }
        }
        
        // 2. If no specific reference, do full-text search
        if (empty($results) && self::hasLegalIntent($message)) {
            $results = self::search($message, 8);
            if (!empty($results)) {
                $context .= "\n\n📚 BIBLIOTECA LEGAL - Resultados relevantes:\n";
            }
        }
        
        if (empty($results)) return '';
        
        // Build context from results
        $seenNorms = [];
        foreach ($results as $r) {
            $normKey = ($r['tipo'] ?? '') . ' ' . ($r['numero'] ?? '');
            if (!isset($seenNorms[$normKey])) {
                $seenNorms[$normKey] = true;
                $context .= "\n🏛️ {$normKey}: {$r['norm_titulo']}\n";
                $context .= "   Fuente: {$r['url_canonica']}\n";
            }
            $path = $r['chunk_path'] ?? '';
            $texto = $r['texto_plain'] ?? '';
            if (strlen($texto) > 800) {
                $texto = substr($texto, 0, 800) . '... [texto truncado]';
            }
            $context .= "\n   📄 [{$path}] {$texto}\n";
        }
        
        $context .= "\nIMPORTANTE: Los textos legales anteriores provienen de la Biblioteca Legal de QueBot (fuente: BCN LeyChile, textos vigentes oficiales). Cita siempre el artículo y ley específica. Incluye el link de LeyChile.\n";
        
        return $context;
    }
}
