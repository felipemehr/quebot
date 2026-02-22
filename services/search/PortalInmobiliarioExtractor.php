<?php
/**
 * PortalInmobiliarioExtractor — Structured property data extraction
 * 
 * Extracts real estate data from Portal Inmobiliario (MercadoLibre infrastructure)
 * using 5 embedded data sources in priority order:
 *   1. JSON-LD (Schema.org) — price, title, availability
 *   2. BreadcrumbList JSON-LD — property type, operation, location
 *   3. GTM DataLayer — status (active/closed), seller, category
 *   4. Nordic Rendering Context — coordinates, surface, bedrooms, photos
 *   5. Meta tags — fallback title, description, image
 * 
 * @version 1.0.0 (Lab)
 * @date 2026-02-22
 */

class PortalInmobiliarioExtractor {
    
    /** Domains this extractor handles */
    private const SUPPORTED_DOMAINS = [
        'portalinmobiliario.com',
        'www.portalinmobiliario.com',
        'inmueble.mercadolibre.cl',
    ];

    /**
     * Check if a URL is handled by this extractor
     */
    public static function supports(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        foreach (self::SUPPORTED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract structured property data from HTML
     * 
     * @param string $html Raw HTML of the property page
     * @param string $originalUrl The URL that was fetched
     * @return array Structured property data
     */
    public function extract(string $html, string $originalUrl): array {
        $data = [
            'source' => 'portalinmobiliario',
            'url' => $originalUrl,
            'extracted_at' => date('c'),
            'extraction_success' => false,
        ];

        // Track which sources yielded data
        $sourcesUsed = [];

        // 1. JSON-LD (most reliable for price + availability)
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd) {
            $sourcesUsed[] = 'json_ld';
            $data['id'] = $jsonLd['productID'] ?? ($jsonLd['sku'] ?? null);
            $data['title'] = $jsonLd['name'] ?? null;
            
            $offers = $jsonLd['offers'] ?? [];
            $data['price'] = isset($offers['price']) ? (float)$offers['price'] : null;
            $data['currency'] = $offers['priceCurrency'] ?? null; // CLF=UF, CLP=pesos
            $data['available'] = isset($offers['availability']) 
                ? str_contains($offers['availability'], 'InStock') 
                : null;
            $data['image'] = $jsonLd['image'] ?? null;
            $data['condition_schema'] = $jsonLd['itemCondition'] ?? null;
            $data['price_valid_until'] = $offers['priceValidUntil'] ?? null;
        }

        // 2. Breadcrumb (property type, operation, region, city)
        $breadcrumb = $this->extractBreadcrumb($html);
        if ($breadcrumb) {
            $sourcesUsed[] = 'breadcrumb';
            // Typical breadcrumb: [type, operation, condition, region, city]
            // But may vary — try to identify each
            $data['property_type'] = $breadcrumb[0] ?? null;
            $data['operation'] = $breadcrumb[1] ?? null;
            
            // Region & city are typically positions 3 and 4
            if (count($breadcrumb) >= 5) {
                $data['region'] = $breadcrumb[3] ?? null;
                $data['city'] = $breadcrumb[4] ?? null;
            } elseif (count($breadcrumb) >= 4) {
                $data['region'] = $breadcrumb[2] ?? null;
                $data['city'] = $breadcrumb[3] ?? null;
            }
        }

        // 3. GTM DataLayer (status is CRITICAL for detecting expired listings)
        $gtm = $this->extractGtm($html);
        if ($gtm) {
            $sourcesUsed[] = 'gtm';
            $data['status'] = $gtm['status'] ?? null; // "active", "closed"
            $data['seller_id'] = $gtm['sellerId'] ?? null;
            $data['listing_type'] = $gtm['listingType'] ?? null; // silver, gold, premium
            $data['category_id'] = $gtm['categoryId'] ?? null;
            $data['condition'] = $gtm['condition'] ?? null; // new, used
            $data['page_type'] = $gtm['pageId'] ?? null; // VIP = detail, SEARCH = listing
        }

        // 4. Nordic Rendering Context (the treasure — coords, specs, photos)
        $nordic = $this->extractNordic($html);
        if (!empty($nordic)) {
            $sourcesUsed[] = 'nordic';
            $data = array_merge($data, $nordic);
        }

        // 5. Meta tags (fallback)
        $ogTitle = $this->extractMeta($html, 'og:title');
        $ogDesc = $this->extractMeta($html, 'og:description');
        if ($ogTitle || $ogDesc) {
            $sourcesUsed[] = 'meta';
        }
        if (!isset($data['title']) && $ogTitle) {
            $data['title'] = $ogTitle;
        }
        $data['og_description'] = $ogDesc;
        
        // If no title from JSON-LD or meta, try HTML title
        if (empty($data['title'])) {
            if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
                $data['title'] = trim($m[1]);
            }
        }

        // Derive price_uf if we have price in CLF
        if (isset($data['price']) && $data['currency'] === 'CLF' && !isset($data['price_uf'])) {
            $data['price_uf'] = (int)$data['price'];
        }

        // Determine if this is a valid property detail page
        $data['is_detail_page'] = $this->isDetailPage($data);
        $data['is_active'] = $this->isActive($data);
        $data['sources_used'] = $sourcesUsed;
        $data['extraction_success'] = !empty($sourcesUsed) && !empty($data['title']);

        // Clean up null values for compact output
        return array_filter($data, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Extract JSON-LD Product data
     */
    private function extractJsonLd(string $html): ?array {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);
        foreach ($matches[1] as $json) {
            $decoded = @json_decode(trim($json), true);
            if ($decoded && ($decoded['@type'] ?? '') === 'Product') {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Extract BreadcrumbList JSON-LD for location hierarchy
     */
    private function extractBreadcrumb(string $html): ?array {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);
        foreach ($matches[1] as $json) {
            $decoded = @json_decode(trim($json), true);
            if ($decoded && ($decoded['@type'] ?? '') === 'BreadcrumbList') {
                $items = $decoded['itemListElement'] ?? [];
                return array_map(function($item) {
                    return $item['item']['name'] ?? $item['name'] ?? null;
                }, $items);
            }
        }
        return null;
    }

    /**
     * Extract GTM DataLayer (status, seller, category)
     */
    private function extractGtm(string $html): ?array {
        // GTM push format: w[l].push({...})
        if (preg_match('/w\[l\]\.push\((\{[^}]+\})\)/s', $html, $m)) {
            $decoded = @json_decode($m[1], true);
            if ($decoded && isset($decoded['itemId'])) {
                return $decoded;
            }
        }
        
        // Alternate format: dataLayer.push({...})
        if (preg_match('/dataLayer\.push\((\{[^}]+\})\)/s', $html, $m)) {
            $decoded = @json_decode($m[1], true);
            if ($decoded && isset($decoded['itemId'])) {
                return $decoded;
            }
        }
        
        return null;
    }

    /**
     * Extract data from Nordic Rendering Context
     * This is an 80KB+ JS object containing full property state
     */
    private function extractNordic(string $html): array {
        $data = [];

        // Coordinates
        if (preg_match('/"latitude":"(-?\d+\.?\d*)","longitude":"(-?\d+\.?\d*)"/', $html, $m)) {
            $data['latitude'] = (float)$m[1];
            $data['longitude'] = (float)$m[2];
        }

        // Prices (UF and CLP)
        preg_match_all('/"type":"price","value":(\d+),"currency_symbol":"([^"]+)","currency_id":"([^"]+)"/', $html, $prices, PREG_SET_ORDER);
        foreach ($prices as $p) {
            if ($p[3] === 'CLF') $data['price_uf'] = (int)$p[1];
            if ($p[3] === 'CLP') $data['price_clp'] = (int)$p[1];
        }
        
        // Fallback price extraction
        if (empty($data['price_clp'])) {
            if (preg_match('/"currency_id":"CLP"[^}]*"value":(\d+)/', $html, $m)) {
                $data['price_clp'] = (int)$m[1];
            }
        }

        // Address
        if (preg_match('/"location_label"[^}]*"text":"([^"]+)"/', $html, $m)) {
            $data['address'] = $m[1];
        } elseif (preg_match('/"location".*?"content_rows".*?"text":"([^"]+)".*?(?="icon")/s', $html, $m)) {
            $data['address'] = $m[1];
        }

        // Surface area
        preg_match_all('/"text":"(\d[\d.]*\s*m²\s*(?:totales|útiles|construidos)?)"/', $html, $surfaces);
        foreach ($surfaces[1] as $s) {
            if (str_contains($s, 'totales')) $data['surface_total'] = $s;
            elseif (str_contains($s, 'útiles')) $data['surface_useful'] = $s;
            elseif (str_contains($s, 'construidos')) $data['surface_built'] = $s;
            else $data['surface'] = $data['surface'] ?? $s;
        }

        // Bedrooms, bathrooms, parking
        if (preg_match('/"text":"(\d+)\s*dormitorio/', $html, $m)) $data['bedrooms'] = (int)$m[1];
        if (preg_match('/"text":"(\d+)\s*baño/', $html, $m)) $data['bathrooms'] = (int)$m[1];
        if (preg_match('/"text":"(\d+)\s*estacionamiento/', $html, $m)) $data['parking'] = (int)$m[1];

        // Age
        if (preg_match('/"text":"(\d+)\s*año/', $html, $m)) $data['age_years'] = (int)$m[1];

        // Description
        if (preg_match('/"plain_text":"([^"]{20,})"/', $html, $m)) {
            $desc = str_replace(['\\n', '\\r', '\\t'], ["\n", '', ''], $m[1]);
            $data['description'] = mb_substr($desc, 0, 500); // Cap at 500 chars
        }

        // Photo count
        preg_match_all('/"id":"(\d+-MLC\d+_\d+)"/', $html, $pics);
        if (!empty($pics[1])) {
            $uniquePhotos = array_unique($pics[1]);
            $data['photo_count'] = count($uniquePhotos);
            // Store first 3 photo URLs
            $data['photos'] = array_values(array_slice(
                array_map(
                    fn($id) => "https://http2.mlstatic.com/D_NQ_NP_{$id}-O.webp",
                    $uniquePhotos
                ), 0, 3
            ));
        }

        // Seller name
        if (preg_match('/"seller_custom_field".*?"text":"([^"]+)"/', $html, $m)) {
            $data['seller_name'] = $m[1];
        }

        // Detect "Publicación finalizada" banner
        if (preg_match('/[Pp]ublicaci[oó]n\s+finalizada/', $html)) {
            $data['listing_ended'] = true;
        }

        return $data;
    }

    /**
     * Extract meta tag value
     */
    private function extractMeta(string $html, string $property): ?string {
        $escaped = preg_quote($property, '/');
        if (preg_match('/<meta\s+(?:property|name)=["\']' . $escaped . '["\']\s+content=["\']([^"\']*)["\']/', $html, $m)) {
            return $m[1];
        }
        // Also try reversed order (content before property)
        if (preg_match('/<meta\s+content=["\']([^"\']*?)["\']\s+(?:property|name)=["\']' . $escaped . '["\']/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Determine if this is a property detail page (VIP) vs listing page
     */
    private function isDetailPage(array $data): bool {
        // GTM pageId "VIP" = detail page
        if (isset($data['page_type']) && $data['page_type'] === 'VIP') {
            return true;
        }
        // Has an MLC ID = detail page
        if (isset($data['id']) && str_starts_with($data['id'] ?? '', 'MLC')) {
            return true;
        }
        // Has coordinates = detail page
        if (isset($data['latitude'])) {
            return true;
        }
        return false;
    }

    /**
     * Determine if listing is currently active
     */
    private function isActive(array $data): bool {
        // GTM status is most reliable
        if (isset($data['status'])) {
            return $data['status'] === 'active';
        }
        // JSON-LD availability
        if (isset($data['available'])) {
            return $data['available'] === true;
        }
        // Banner detection
        if (!empty($data['listing_ended'])) {
            return false;
        }
        // Unknown — assume active
        return true;
    }

    /**
     * Format extracted data as a compact context string for the LLM
     */
    public static function formatForContext(array $data): string {
        if (empty($data['extraction_success'])) {
            return '';
        }

        $parts = [];
        
        // Title and status
        $title = $data['title'] ?? 'Propiedad sin título';
        $status = ($data['is_active'] ?? true) ? '' : ' ⚠️ FINALIZADA';
        $parts[] = "**{$title}**{$status}";
        
        // Type and operation
        $typeOp = array_filter([
            $data['property_type'] ?? null,
            $data['operation'] ?? null,
        ]);
        if ($typeOp) $parts[] = implode(' · ', $typeOp);
        
        // Location
        $loc = array_filter([
            $data['city'] ?? null,
            $data['region'] ?? null,
        ]);
        if ($loc) $parts[] = '📍 ' . implode(', ', $loc);
        if (!empty($data['address'])) $parts[] = 'Dirección: ' . $data['address'];
        
        // Price
        $priceStr = [];
        if (isset($data['price_uf'])) $priceStr[] = number_format($data['price_uf']) . ' UF';
        if (isset($data['price_clp'])) $priceStr[] = '$' . number_format($data['price_clp']);
        if (!empty($priceStr)) $parts[] = '💰 ' . implode(' / ', $priceStr);
        
        // Specs
        $specs = [];
        if (isset($data['surface_total'])) $specs[] = $data['surface_total'];
        elseif (isset($data['surface'])) $specs[] = $data['surface'];
        if (isset($data['bedrooms'])) $specs[] = $data['bedrooms'] . 'D';
        if (isset($data['bathrooms'])) $specs[] = $data['bathrooms'] . 'B';
        if (isset($data['parking'])) $specs[] = $data['parking'] . 'E';
        if ($specs) $parts[] = '📐 ' . implode(' · ', $specs);
        
        // Coordinates
        if (isset($data['latitude']) && isset($data['longitude'])) {
            $parts[] = "🗺️ [{$data['latitude']}, {$data['longitude']}]";
        }
        
        // Seller
        if (isset($data['seller_name'])) $parts[] = '🏪 ' . $data['seller_name'];
        
        // Photos
        if (isset($data['photo_count'])) $parts[] = "📷 {$data['photo_count']} fotos";

        return implode("\n", $parts);
    }
}
