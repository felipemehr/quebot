<?php
/**
 * UrlHealthChecker — Verifies property URLs before presenting to users.
 * 
 * Detects:
 * - Expired listings (redirected to category pages)
 * - Dead links (404, 500, etc.)
 * - Redirects to generic listing pages
 * - Soft-404s: pages that return HTTP 200 but show "publicación no disponible" etc.
 * 
 * Uses GET requests with real browser UA for property portals to detect soft-404s.
 * Uses curl_multi for parallelism.
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
    
    // Property portal domains that need GET + content check for soft-404 detection
    private static $PROPERTY_PORTALS = [
        'portalinmobiliario.com', 'toctoc.com', 'yapo.cl', 
        'chilepropiedades.cl', 'goplaceit.com', 'icasas.cl'
    ];
    
    // Patterns in response body that indicate expired/unavailable listings
    private static $EXPIRED_CONTENT_PATTERNS = [
        'publicación no disponible',
        'publicación eliminada',
        'este aviso ya no está disponible',
        'esta publicación ya no existe',
        'aviso no encontrado',
        'propiedad no encontrada',
        'propiedad no disponible',
        'listing not available',
        'este aviso ha sido eliminado',
        'este aviso expiró',
        'aviso expirado',
        'publicación pausada',
        'publicación finalizada',
        'aviso finalizado',
        'ya no está disponible',
        'no pudimos encontrar',
        'contenido no disponible',
        'página no encontrada',
    ];
    
    private static $BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    
    /**
     * Check if a URL's host matches any known property portal.
     */
    private static function isPropertyPortal(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        foreach (self::$PROPERTY_PORTALS as $portal) {
            if (stripos($host, $portal) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check response content for expired/unavailable listing patterns.
     * Returns matched pattern or null.
     */
    private static function checkContentForExpired(string $content): ?string {
        $contentLower = mb_strtolower($content);
        foreach (self::$EXPIRED_CONTENT_PATTERNS as $pattern) {
            if (mb_strpos($contentLower, $pattern) !== false) {
                return $pattern;
            }
        }
        return null;
    }
    
    /**
     * Check for portal-specific redirect patterns that indicate expired listings.
     */
    private static function checkPortalRedirect(string $originalUrl, string $finalUrl): ?string {
        $originalHost = parse_url($originalUrl, PHP_URL_HOST) ?? '';
        
        // yapo.cl: redirects to /yapo.cl/ or shows generic page
        if (stripos($originalHost, 'yapo.cl') !== false) {
            if (preg_match('#/yapo\\.cl/?$#i', $finalUrl)) {
                return 'yapo_redirect_to_home';
            }
        }
        
        // portalinmobiliario.com: redirects to category with #redirectedFromVip or _NoIndex_True
        if (stripos($originalHost, 'portalinmobiliario.com') !== false) {
            if (stripos($finalUrl, '#redirectedFromVip') !== false || stripos($finalUrl, '_NoIndex_True') !== false) {
                return 'pi_redirect_to_category';
            }
        }
        
        // goplaceit.com: redirects to /propiedades/ listing
        if (stripos($originalHost, 'goplaceit.com') !== false) {
            if (preg_match('#goplaceit\\.com/propiedades/(venta|arriendo)/?($|\\?)#i', $finalUrl)) {
                return 'goplaceit_redirect_to_listing';
            }
        }
        
        return null;
    }
    
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
        
        $isPortal = self::isPropertyPortal($url);
        
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::$BROWSER_UA,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        
        if ($isPortal) {
            // GET request with limited body for content-based soft-404 detection
            $opts[CURLOPT_NOBODY] = false;
            $opts[CURLOPT_RANGE] = '0-8191';
        } else {
            // HEAD request for non-portal URLs
            $opts[CURLOPT_NOBODY] = true;
        }
        
        curl_setopt_array($ch, $opts);
        
        $body = curl_exec($ch);
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
        
        // Check portal-specific redirect patterns
        if ($result['redirected']) {
            $portalRedirect = self::checkPortalRedirect($url, $finalUrl);
            if ($portalRedirect) {
                $result['reason'] = $portalRedirect;
                return $result;
            }
            
            // Check generic listing redirect patterns
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
        
        // Content-based soft-404 detection for portal URLs
        if ($isPortal && !empty($body) && is_string($body)) {
            $expiredMatch = self::checkContentForExpired($body);
            if ($expiredMatch) {
                $result['reason'] = 'soft_404: ' . $expiredMatch;
                return $result;
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
        return self::checkBatchWithContent($urls, $timeout);
    }
    
    /**
     * Enhanced batch check: uses GET with browser UA for property portals to detect soft-404s.
     * Returns array of results keyed by URL.
     */
    public static function checkBatchWithContent(array $urls, int $timeout = 5): array {
        if (empty($urls)) return [];
        
        $results = [];
        $handles = [];
        $portalFlags = []; // Track which URLs are portal URLs
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
            $isPortal = self::isPropertyPortal($url);
            $portalFlags[$url] = $isPortal;
            
            $ch = curl_init();
            $opts = [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => self::$BROWSER_UA,
                CURLOPT_SSL_VERIFYPEER => false,
            ];
            
            if ($isPortal) {
                // GET request with limited download for content-based soft-404 detection
                $opts[CURLOPT_NOBODY] = false;
                $opts[CURLOPT_RANGE] = '0-8191';
            } else {
                // HEAD request for non-portal URLs
                $opts[CURLOPT_NOBODY] = true;
            }
            
            curl_setopt_array($ch, $opts);
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
            $body = curl_multi_getcontent($ch);
            $isPortal = $portalFlags[$url] ?? false;
            
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
                // Check portal-specific redirect patterns first
                $portalRedirect = self::checkPortalRedirect($url, $finalUrl);
                if ($portalRedirect) {
                    $result['reason'] = $portalRedirect;
                } else {
                    // Check generic listing redirect patterns
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
                            // Check content for soft-404 even on redirected portal URLs
                            if ($isPortal && !empty($body) && is_string($body)) {
                                $expiredMatch = self::checkContentForExpired($body);
                                if ($expiredMatch) {
                                    $result['reason'] = 'soft_404: ' . $expiredMatch;
                                } else {
                                    $result['alive'] = ($httpCode >= 200 && $httpCode < 400);
                                    $result['reason'] = $result['alive'] ? 'ok' : 'http_' . $httpCode;
                                }
                            } else {
                                $result['alive'] = ($httpCode >= 200 && $httpCode < 400);
                                $result['reason'] = $result['alive'] ? 'ok' : 'http_' . $httpCode;
                            }
                        }
                    }
                }
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                // Not redirected, but check content for soft-404 on portal URLs
                if ($isPortal && !empty($body) && is_string($body)) {
                    $expiredMatch = self::checkContentForExpired($body);
                    if ($expiredMatch) {
                        $result['reason'] = 'soft_404: ' . $expiredMatch;
                    } else {
                        $result['alive'] = true;
                        $result['reason'] = 'ok';
                    }
                } else {
                    $result['alive'] = true;
                    $result['reason'] = 'ok';
                }
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
