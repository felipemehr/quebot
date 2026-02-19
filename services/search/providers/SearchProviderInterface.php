<?php
/**
 * Interface for search providers.
 * All providers must return results in normalized format.
 */
interface SearchProviderInterface {
    /** Provider identifier (e.g., 'duckduckgo', 'serpapi') */
    public function getName(): string;

    /**
     * Execute search query.
     * @return array<int, array{title:string, url:string, snippet:string, position:int, source_provider:string}>
     */
    public function search(string $query, int $maxResults = 10): array;

    /**
     * Scrape page content for deeper extraction.
     * @return string|null Plain text content or null on failure.
     */
    public function scrapeContent(string $url, int $maxLength = 3000): ?string;
}
