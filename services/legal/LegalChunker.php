<?php
/**
 * QueBot Legal Library - Legal Text Chunker
 * 
 * Strategy:
 * - Chunk base: Artículo (each article = 1 chunk)
 * - Sub-chunk if article > ~700 words: split by incisos → numerales → letras
 * - Path format: Art_19, Art_19/Inc_2, Art_19/Inc_2/Num_3, Art_19/Inc_2/Let_b
 * - Each chunk: 150-700 words, juridically coherent
 * - Always keep full article text reconstructable
 * - Groupers (Título, Capítulo) are stored as parent chunks
 */

class LegalChunker {
    
    /** Max chars before sub-chunking an article (~700 words) */
    private const SUB_CHUNK_THRESHOLD = 4500;
    
    /** Minimum chars for a standalone chunk (~150 words) */
    private const MIN_CHUNK_SIZE = 900;
    
    /**
     * Convert parsed norm articles into flat chunk list
     * @param array $articles Parsed articles from BcnConnector
     * @param string $parentPath Current path prefix
     * @return array Flat list of chunks
     */
    public function chunkArticles(array $articles, string $parentPath = ''): array {
        $chunks = [];
        $order = 0;
        
        foreach ($articles as $item) {
            $order++;
            $chunk = $this->processItem($item, $parentPath, $order);
            $chunks = array_merge($chunks, $chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Process a single article/grouper into chunks
     */
    private function processItem(array $item, string $parentPath, int $order): array {
        $chunks = [];
        $tipoParte = $item['tipo_parte'] ?? '';
        $nombreParte = trim($item['nombre_parte'] ?? '');
        $tituloParte = trim($item['titulo_parte'] ?? '');
        $texto = trim($item['texto'] ?? '');
        
        // Determine chunk type and path
        $chunkInfo = $this->classifyChunk($tipoParte, $nombreParte);
        $path = $parentPath ? $parentPath . '/' . $chunkInfo['path'] : $chunkInfo['path'];
        
        // Create the main chunk
        $mainChunk = [
            'chunk_type' => $chunkInfo['type'],
            'chunk_path' => $path,
            'id_parte' => $item['id_parte'] ?? null,
            'nombre_parte' => $nombreParte,
            'titulo_parte' => $tituloParte,
            'texto' => $texto,
            'texto_plain' => $this->toPlainText($texto),
            'ordering' => $order,
            'derogado' => $item['derogado'] ?? false,
            'transitorio' => $item['transitorio'] ?? false,
            'children' => [],
        ];
        
        // If it's an article and exceeds threshold, create sub-chunks
        if ($chunkInfo['type'] === 'article' && strlen($mainChunk['texto_plain']) > self::SUB_CHUNK_THRESHOLD) {
            $subChunks = $this->subChunkArticle($mainChunk['texto_plain'], $path);
            // Merge small sub-chunks to meet minimum size
            $mainChunk['children'] = $this->mergeSmallChunks($subChunks);
        }
        
        $chunks[] = $mainChunk;
        
        // Process nested structures (e.g., articles inside Títulos)
        if (!empty($item['children'])) {
            $childChunks = $this->chunkArticles($item['children'], $path);
            $chunks = array_merge($chunks, $childChunks);
        }
        
        return $chunks;
    }
    
    /**
     * Classify a part into a chunk type and path segment
     */
    private function classifyChunk(string $tipoParte, string $nombreParte): array {
        $tipoMap = [
            'Artículo' => ['type' => 'article', 'prefix' => 'Art'],
            'Artículo Transitorio' => ['type' => 'transitory', 'prefix' => 'ArtTrans'],
            'Disposición Transitoria' => ['type' => 'transitory', 'prefix' => 'DispTrans'],
            'Disposición' => ['type' => 'disposition', 'prefix' => 'Disp'],
            'Disposiciones Preliminares' => ['type' => 'preliminary', 'prefix' => 'DispPrelim'],
            'Título' => ['type' => 'title', 'prefix' => 'Tit'],
            'Capítulo' => ['type' => 'chapter', 'prefix' => 'Cap'],
            'Libro' => ['type' => 'book', 'prefix' => 'Lib'],
            'Parágrafo' => ['type' => 'paragraph', 'prefix' => 'Par'],
            'Párrafo' => ['type' => 'section', 'prefix' => 'Sec'],
            'Enumeración' => ['type' => 'enumeration', 'prefix' => 'Enum'],
            'Doble Articulado' => ['type' => 'double_article', 'prefix' => 'DblArt'],
        ];
        
        $info = $tipoMap[$tipoParte] ?? ['type' => 'other', 'prefix' => 'X'];
        $safeName = $this->sanitizePath($nombreParte ?: '0');
        
        return [
            'type' => $info['type'],
            'path' => $info['prefix'] . '_' . $safeName,
        ];
    }
    
    /**
     * Sub-chunk a long article into incisos → numerales → letras
     * Hierarchy: Article → Incisos → Numerales → Letras
     */
    private function subChunkArticle(string $text, string $parentPath): array {
        $subChunks = [];
        $order = 0;
        
        // Split by incisos (paragraphs separated by double newlines or indentation)
        $lines = preg_split('/\n\s*\n|\n(?=\s{3,})/', $text);
        
        if (count($lines) <= 1) {
            // If no incisos found, try numerals first, then letters
            $numChunks = $this->splitByNumerals($text, $parentPath);
            if (!empty($numChunks)) return $numChunks;
            return $this->splitByLetters($text, $parentPath);
        }
        
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $order++;
            $incPath = $parentPath . '/Inc_' . $order;
            
            $subChunk = [
                'chunk_type' => 'inciso',
                'chunk_path' => $incPath,
                'id_parte' => null,
                'nombre_parte' => "Inciso $order",
                'titulo_parte' => '',
                'texto' => $line,
                'texto_plain' => $line,
                'ordering' => $order,
                'derogado' => false,
                'transitorio' => false,
                'children' => [],
            ];
            
            // Check if this inciso has numbered items: 1., 2., 3. or 1°, 2°, 3°
            if (preg_match_all('/(?:^|\n)\s*\d+[\.\°\)\-]\s/', $line, $matches) > 2) {
                $subChunk['children'] = $this->splitByNumerals($line, $incPath);
            }
            // Check if this inciso has lettered items: a), b), c)
            elseif (preg_match_all('/\b[a-z]\)\s/', $line, $matches) > 2) {
                $subChunk['children'] = $this->splitByLetters($line, $incPath);
            }
            
            $subChunks[] = $subChunk;
        }
        
        return $subChunks;
    }
    
    /**
     * Split text by numbered items: 1., 2., 3. or 1°, 2° or 1), 2) or 1.-, 2.-
     */
    private function splitByNumerals(string $text, string $parentPath): array {
        // Match patterns: "1. ", "1° ", "1) ", "1.- "
        $parts = preg_split('/(?=(?:^|\n)\s*(\d+)[\.\°\)\-]+\s)/m', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) <= 1) return [];
        
        $chunks = [];
        $order = 0;
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            $order++;
            // Extract numeral if present
            $numeral = $order;
            if (preg_match('/^(\d+)[\.\°\)\-]/', $part, $m)) {
                $numeral = (int)$m[1];
            }
            
            $numPath = $parentPath . '/Num_' . $numeral;
            
            $numChunk = [
                'chunk_type' => 'numeral',
                'chunk_path' => $numPath,
                'id_parte' => null,
                'nombre_parte' => "Numeral $numeral",
                'titulo_parte' => '',
                'texto' => $part,
                'texto_plain' => $part,
                'ordering' => $order,
                'derogado' => false,
                'transitorio' => false,
                'children' => [],
            ];
            
            // Check if this numeral has lettered sub-items
            if (preg_match_all('/\b[a-z]\)\s/', $part, $matches) > 2) {
                $numChunk['children'] = $this->splitByLetters($part, $numPath);
            }
            
            $chunks[] = $numChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Split text by lettered items: a), b), c)
     */
    private function splitByLetters(string $text, string $parentPath): array {
        $parts = preg_split('/(?=\b[a-z]\)\s)/', $text);
        if (count($parts) <= 1) return [];
        
        $chunks = [];
        $order = 0;
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            $order++;
            // Extract letter if present
            $letter = '';
            if (preg_match('/^([a-z])\)/', $part, $m)) {
                $letter = $m[1];
            }
            
            $path = $parentPath . '/Let_' . ($letter ?: $order);
            
            $chunks[] = [
                'chunk_type' => 'letra',
                'chunk_path' => $path,
                'id_parte' => null,
                'nombre_parte' => $letter ? "Letra $letter" : "Parte $order",
                'titulo_parte' => '',
                'texto' => $part,
                'texto_plain' => $part,
                'ordering' => $order,
                'derogado' => false,
                'transitorio' => false,
                'children' => [],
            ];
        }
        
        return $chunks;
    }
    
    /**
     * Merge chunks that are too small (< MIN_CHUNK_SIZE) with their neighbor
     * Ensures each chunk has at least ~150 words for meaningful retrieval
     */
    private function mergeSmallChunks(array $chunks): array {
        if (count($chunks) <= 1) return $chunks;
        
        $merged = [];
        $buffer = null;
        
        foreach ($chunks as $chunk) {
            $size = strlen($chunk['texto_plain'] ?? $chunk['texto']);
            
            if ($buffer !== null) {
                $bufferSize = strlen($buffer['texto_plain'] ?? $buffer['texto']);
                
                if ($bufferSize < self::MIN_CHUNK_SIZE) {
                    // Merge buffer into current chunk
                    $chunk['texto_plain'] = $buffer['texto_plain'] . "\n\n" . ($chunk['texto_plain'] ?? $chunk['texto']);
                    $chunk['texto'] = $buffer['texto'] . "\n\n" . $chunk['texto'];
                    $chunk['nombre_parte'] = $buffer['nombre_parte'] . ' + ' . $chunk['nombre_parte'];
                    // Keep buffer's path if it was first
                    $buffer = null;
                } else {
                    $merged[] = $buffer;
                    $buffer = null;
                }
            }
            
            if ($size < self::MIN_CHUNK_SIZE && count($chunks) > 1) {
                $buffer = $chunk;
            } else {
                $merged[] = $chunk;
            }
        }
        
        // Handle remaining buffer
        if ($buffer !== null) {
            if (!empty($merged)) {
                // Merge with last chunk
                $last = array_pop($merged);
                $last['texto_plain'] .= "\n\n" . ($buffer['texto_plain'] ?? $buffer['texto']);
                $last['texto'] .= "\n\n" . $buffer['texto'];
                $last['nombre_parte'] .= ' + ' . $buffer['nombre_parte'];
                $merged[] = $last;
            } else {
                $merged[] = $buffer;
            }
        }
        
        // Re-number ordering
        foreach ($merged as $i => &$chunk) {
            $chunk['ordering'] = $i + 1;
        }
        
        return $merged;
    }
    
    /**
     * Convert text to plain (remove formatting artifacts)
     */
    private function toPlainText(string $text): string {
        // Remove HTML tags if any leaked through
        $text = strip_tags($text);
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
    
    /**
     * Sanitize a string for use in chunk paths
     */
    private function sanitizePath(string $s): string {
        $s = trim($s);
        // Remove "bis", "ter" etc. but keep them readable
        $s = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        $s = trim($s, '_');
        return $s ?: '0';
    }
}
