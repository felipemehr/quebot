<?php
/**
 * Curated News Source Registry for NEWS_MODE
 * ID: qb_news_mode_sources_v1
 */

class NewsSourceRegistry {
    private static array $sources = [
        // Chile - Mainstream
        ['name' => 'El Mercurio', 'domain' => 'emol.com', 'country' => 'CL', 'type' => 'mainstream'],
        ['name' => 'La Tercera', 'domain' => 'latercera.com', 'country' => 'CL', 'type' => 'mainstream'],
        ['name' => 'CNN Chile', 'domain' => 'cnnchile.com', 'country' => 'CL', 'type' => 'mainstream'],
        ['name' => 'Radio Biobío', 'domain' => 'biobiochile.cl', 'country' => 'CL', 'type' => 'mainstream'],
        ['name' => 'CIPER Chile', 'domain' => 'ciperchile.cl', 'country' => 'CL', 'type' => 'investigative'],
        ['name' => 'El Ciudadano', 'domain' => 'elciudadano.com', 'country' => 'CL', 'type' => 'opinion'],
        ['name' => '24 Horas', 'domain' => '24horas.cl', 'country' => 'CL', 'type' => 'public'],
        ['name' => 'Diario Financiero', 'domain' => 'df.cl', 'country' => 'CL', 'type' => 'financial'],

        // Global
        ['name' => 'BBC', 'domain' => 'bbc.com', 'country' => 'UK', 'type' => 'global'],
        ['name' => 'Reuters', 'domain' => 'reuters.com', 'country' => 'UK', 'type' => 'global'],
        ['name' => 'AP News', 'domain' => 'apnews.com', 'country' => 'US', 'type' => 'global'],
        ['name' => 'Al Jazeera', 'domain' => 'aljazeera.com', 'country' => 'QA', 'type' => 'global'],

        // Europe West
        ['name' => 'Deutsche Welle', 'domain' => 'dw.com', 'country' => 'DE', 'type' => 'europe_west'],
        ['name' => 'Le Monde', 'domain' => 'lemonde.fr', 'country' => 'FR', 'type' => 'europe_west'],
        ['name' => 'El País', 'domain' => 'elpais.com', 'country' => 'ES', 'type' => 'europe_west'],

        // Europe East
        ['name' => 'Polskie Radio', 'domain' => 'polskieradio.pl', 'country' => 'PL', 'type' => 'europe_east'],
        ['name' => 'Ukrainska Pravda', 'domain' => 'pravda.com.ua', 'country' => 'UA', 'type' => 'europe_east'],

        // Asia
        ['name' => 'South China Morning Post', 'domain' => 'scmp.com', 'country' => 'HK', 'type' => 'asia'],
        ['name' => 'Xinhua', 'domain' => 'xinhuanet.com', 'country' => 'CN', 'type' => 'asia'],
        ['name' => 'The Japan Times', 'domain' => 'japantimes.co.jp', 'country' => 'JP', 'type' => 'asia'],
        ['name' => 'The Hindu', 'domain' => 'thehindu.com', 'country' => 'IN', 'type' => 'asia'],

        // Financial Global
        ['name' => 'Financial Times', 'domain' => 'ft.com', 'country' => 'UK', 'type' => 'financial_global'],
        ['name' => 'The Economist', 'domain' => 'economist.com', 'country' => 'UK', 'type' => 'analysis'],
    ];

    public static function getSources(): array {
        return self::$sources;
    }

    public static function getDomains(): array {
        return array_column(self::$sources, 'domain');
    }

    public static function getDomainString(): string {
        return implode('|', self::getDomains());
    }

    /**
     * Build site: restriction string for search queries.
     * E.g. "site:emol.com OR site:latercera.com OR ..."
     */
    public static function buildSiteRestriction(?string $type = null): string {
        $domains = $type !== null
            ? array_column(self::getByType($type), 'domain')
            : self::getDomains();

        return implode(' OR ', array_map(fn($d) => "site:{$d}", $domains));
    }

    /**
     * Build site: restriction for Chilean mainstream sources only.
     */
    public static function buildChileSiteRestriction(): string {
        $clSources = self::getByCountry('CL');
        $domains = array_column($clSources, 'domain');
        return implode(' OR ', array_map(fn($d) => "site:{$d}", $domains));
    }

    public static function isWhitelisted(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        foreach (self::getDomains() as $domain) {
            if (strpos($host, $domain) !== false) return true;
        }
        return false;
    }

    public static function getSourceByDomain(string $url): ?array {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        foreach (self::$sources as $source) {
            if (strpos($host, $source['domain']) !== false) return $source;
        }
        return null;
    }

    public static function getByType(string $type): array {
        return array_values(array_filter(self::$sources, fn($s) => $s['type'] === $type));
    }

    public static function getByCountry(string $country): array {
        return array_values(array_filter(self::$sources, fn($s) => $s['country'] === $country));
    }
}
