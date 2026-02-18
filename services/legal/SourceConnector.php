<?php
/**
 * QueBot Legal Library - Source Connector Interface
 * 
 * Implement this interface to add new legal data sources.
 * Current implementations: BcnConnector (BCN LeyChile)
 * Future: Diario Oficial, Contraloría, SII, MINVU/OGUC
 */

interface SourceConnectorInterface {
    
    /**
     * Fetch a norm's full content
     * @param string $identifier Source-specific norm identifier
     * @return array{xml: string, http_code: int, error: ?string}
     */
    public function fetchNorm(string $identifier): array;
    
    /**
     * Parse raw content into structured data
     * @param string $rawContent Raw response from source
     * @return array Parsed norm data
     */
    public function parseNorm(string $rawContent): array;
    
    /**
     * Search for norms by text query
     * @param string $query Search text
     * @param int $limit Max results
     * @return array{xml: string, http_code: int, error: ?string}
     */
    public function searchNorms(string $query, int $limit = 10): array;
}
