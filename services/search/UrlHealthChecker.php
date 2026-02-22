<?php
/**
 * UrlHealthChecker — Verifies property URLs before presenting to users.
 * 
 * Detects:
 * - Expired listings (redirected to category pages)
 * - Dead links (404, 500, etc.)
 * - Redirects to generic listing pages
 * 
 * Uses HEAD requests for speed, curl_multi for parallelism.
 */
class UrlHealthChecker {
    
    private static $LISTING_PATTERNS = [
        '#redirectedFromVip',
        '/venta/casa/propiedades',
        '/venta/departamento/propiedades',
        '/arriendo/casa/propiedades',
        '/arriendo/departamento/propiedades',
        '/venta/terreno/propiedades',
        '/inmuebles/venta',
        '/inmuebles/arriendo',
        '/_CategoriaDeInmueble_',
        '/_NoIndex_True',
        '/resultados?',
        '/buscar?',
    ];
    
    private static $SPECIFIC_PATTERNS = [
        '/MLC-',           // Portal Inmobiliario
        '/MLU-',           // MercadoLibre Uruguay
        '/publicacion/',   // TocToc
        '/aviso/',         // Yapo
        '/propiedad/',     // Various
        '/ficha/',         // Various
    ];
    
    /**
     * Check a single URL for health.
     * Returns: [alive, final_url, redirected, reason, http_code]
     */
    public static function check(string $url, int $timeout = 5): array {
        $result = [
            'url' => $url,
            'alive' => false,
            'final_url' => $url,
            'redirected' => false,
            'reason' => 'unknown',
            'http_code' => 0
        ];
        
        // Quick check: if URL already contains listing patterns, it's dead
        foreach (self::$LISTING_PATTERNS as $pattern) {
            if (stripos($url, $pattern) !== false) {
                $result['reason'] = 'url_is_listing_page';
                return $result;
            }
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,           // HEAD request
            CURLOPT_FOLLOWLOCATION => true,    // Follow redirects
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; QueBotChecker/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        
        $result['http_code'] = $httpCode;
        $result['final_url'] = $finalUrl;
        $result['redirected'] = ($finalUrl !== $url);
        
        // HTTP error codes
        if ($httpCode === 0) {
            $result['reason'] = 'connection_failed';
            return $result;
        }
        
        if ($httpCode === 404) {
            $result['reason'] = 'not_found';
            return $result;
        }
        
        if ($httpCode >= 500) {
            $result['reason'] = 'server_error';
            return $result;
        }
        
        // Check if redirected to a listing page
        if ($result['redirected']) {
            foreach (self::$LISTING_PATTERNS as $pattern) {
                if (stripos($finalUrl, $pattern) !== false) {
                    $result['reason'] = 'redirect_to_listing';
                    return $result;
                }
            }
            
            // Check if original was specific but final is not
            $wasSpecific = false;
            foreach (self::$SPECIFIC_PATTERNS as $pattern) {
                if (stripos($url, $pattern) !== false) {
                    $wasSpecific = true;
                    break;
                }
            }
            
            if ($wasSpecific) {
                $isStillSpecific = false;
                foreach (self::$SPECIFIC_PATTERNS as $pattern) {
                    if (stripos($finalUrl, $pattern) !== false) {
                        $isStillSpecific = true;
                        break;
                    }
                }
                
                if (!$isStillSpecific) {
                    $result['reason'] = 'expired_listing';
                    return $result;
                }
            }
        }
        
        // Alive!
        if ($httpCode >= 200 && $httpCode < 400) {
            $result['alive'] = true;
            $result['reason'] = 'ok';
        } else {
            $result['reason'] = 'http_' . $httpCode;
        }
        
        return $result;
    }
    
    /**
     * Check multiple URLs in parallel using curl_multi.
     * Returns array of results keyed by URL.
     */
    public static function checkBatch(array $urls, int $timeout = 5): array {
        if (empty($urls)) return [];
        
        $results = [];
        $handles = [];
        $mh = curl_multi_init();
        
        // Pre-filter URLs that are obviously listings
        $toCheck = [];
        foreach ($urls as $url) {
            $isListing = false;
            foreach (self::$LISTING_PATTERNS as $pattern) {
                if (stripos($url, $pattern) !== false) {
                    $isListing = true;
                    break;
                }
            }
            
            if ($isListing) {
                $results[$url] = [
                    'url' => $url,
                    'alive' => false,
                    'final_url' => $url,
                    'redirected' => false,
                    'reason' => 'url_is_listing_page',
                    'http_code' => 0
                ];
            } else {
                $toCheck[] = $url;
            }
        }
        
        // Create curl handles for remaining URLs
        foreach ($toCheck as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; QueBotChecker/1.0)',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            
            curl_multi_add_handle($mh, $ch);
            $handles[$url] = $ch;
        }
        
        // Execute all in parallel
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);
        
        // Collect results
        foreach ($handles as $url => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            $result = [
                'url' => $url,
                'alive' => false,
                'final_url' => $finalUrl,
                'redirected' => ($finalUrl !== $url),
                'reason' => 'unknown',
                'http_code' => $httpCode
            ];
            
            if ($httpCode === 0) {
                $result['reason'] = 'connection_failed';
            } elseif ($httpCode === 404) {
                $result['reason'] = 'not_found';
            } elseif ($httpCode >= 500) {
                $result['reason'] = 'server_error';
            } elseif ($result['redirected']) {
                // Check if redirected to listing
                $isListing = false;
                foreach (self::$LISTING_PATTERNS as $pattern) {
                    if (stripos($finalUrl, $pattern) !== false) {
                        $isListing = true;
                        break;
                    }
                }
                
                if ($isListing) {
                    $result['reason'] = 'redirect_to_listing';
                } else {
                    // Check if was specific but now isn't
                    $wasSpecific = false;
                    foreach (self::$SPECIFIC_PATTERNS as $pat) {
                        if (stripos($url, $pat) !== false) { $wasSpecific = true; break; }
                    }
                    $isStillSpecific = false;
                    foreach (self::$SPECIFIC_PATTERNS as $pat) {
                        if (stripos($finalUrl, $pat) !== false) { $isStillSpecific = true; break; }
                    }
                    
                    if ($wasSpecific && !$isStillSpecific) {
                        $result['reason'] = 'expired_listing';
                    } else {
                        $result['alive'] = ($httpCode >= 200 && $httpCode < 400);
                        $result['reason'] = $result['alive'] ? 'ok' : 'http_' . $httpCode;
                    }
                }
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                $result['alive'] = true;
                $result['reason'] = 'ok';
            } else {
                $result['reason'] = 'http_' . $httpCode;
            }
            
            $results[$url] = $result;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($mh);
        return $results;
    }
}
