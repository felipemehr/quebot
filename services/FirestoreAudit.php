<?php
/**
 * FirestoreAudit — Lightweight Firestore REST API client for search audit trail.
 *
 * Logs search runs and events to Firestore without requiring Firebase Admin SDK.
 * Uses Firestore REST API v1 (no auth required given current security rules).
 *
 * Collections used:
 * - search_runs: Full search pipeline record per query
 * - events: Individual timestamped events (SEARCH_STARTED, SEARCH_COMPLETED, etc.)
 * - cases: Updated with last_search metadata
 */
class FirestoreAudit {

    private const PROJECT_ID = 'quebot-2d931';
    private const BASE_URL = 'https://firestore.googleapis.com/v1/projects/quebot-2d931/databases/(default)/documents';
    private const TIMEOUT = 5; // seconds — non-blocking, best-effort

    /**
     * Log a complete search run to Firestore.
     *
     * @param array $data {
     *   case_id: string,
     *   user_id: string,
     *   user_query: string,
     *   vertical: string,
     *   intent: ?array,
     *   queries_built: string[],
     *   provider: string,
     *   raw_results_count: int,
     *   valid_listings: int,
     *   insufficient: bool,
     *   expansion_suggestions: string[],
     *   top_results: array (title, url, domain, score — max 15),
     *   timing_ms: float,
     *   diagnostics: array,
     * }
     * @return ?string Document ID if successful
     */
    public static function logSearchRun(array $data): ?string {
        $docId = 'sr_' . bin2hex(random_bytes(8));

        $fields = [
            'case_id' => self::stringVal($data['case_id'] ?? ''),
            'user_id' => self::stringVal($data['user_id'] ?? 'anonymous'),
            'user_query' => self::stringVal($data['user_query'] ?? ''),
            'vertical' => self::stringVal($data['vertical'] ?? 'unknown'),
            'provider' => self::stringVal($data['provider'] ?? 'unknown'),
            'raw_results_count' => self::intVal($data['raw_results_count'] ?? 0),
            'valid_listings' => self::intVal($data['valid_listings'] ?? 0),
            'insufficient' => self::boolVal($data['insufficient'] ?? false),
            'timing_ms' => self::doubleVal($data['timing_ms'] ?? 0),
            'created_at' => self::timestampVal(),
        ];

        // Store queries as array
        if (!empty($data['queries_built'])) {
            $fields['queries_built'] = self::arrayVal(
                array_map([self::class, 'stringVal'], array_slice($data['queries_built'], 0, 10))
            );
        }

        // Store intent as map (if present)
        if (!empty($data['intent'])) {
            $intentFields = [];
            foreach (['tipo_propiedad', 'ubicacion', 'operacion'] as $key) {
                if (!empty($data['intent'][$key])) {
                    $intentFields[$key] = self::stringVal($data['intent'][$key]);
                }
            }
            if (!empty($data['intent']['presupuesto'])) {
                $p = $data['intent']['presupuesto'];
                $intentFields['presupuesto_amount'] = self::doubleVal($p['amount'] ?? 0);
                $intentFields['presupuesto_unit'] = self::stringVal($p['unit'] ?? '');
            }
            if (!empty($data['intent']['superficie'])) {
                $s = $data['intent']['superficie'];
                $intentFields['superficie_m2'] = self::doubleVal($s['m2'] ?? 0);
            }
            if (!empty($data['intent']['must_have'])) {
                $intentFields['must_have'] = self::arrayVal(
                    array_map([self::class, 'stringVal'], $data['intent']['must_have'])
                );
            }
            $intentFields['confidence'] = self::doubleVal($data['intent']['confidence'] ?? 0);
            $fields['intent'] = ['mapValue' => ['fields' => $intentFields]];
        }

        // Store top results (max 15, trimmed)
        if (!empty($data['top_results'])) {
            $topResults = [];
            foreach (array_slice($data['top_results'], 0, 15) as $r) {
                $resultFields = [];
                $resultFields['title'] = self::stringVal(mb_substr($r['title'] ?? '', 0, 200));
                $resultFields['url'] = self::stringVal($r['url'] ?? '');
                $resultFields['domain'] = self::stringVal(parse_url($r['url'] ?? '', PHP_URL_HOST) ?? '');
                $resultFields['score'] = self::doubleVal($r['score'] ?? 0);
                $resultFields['url_type'] = self::stringVal($r['extracted']['url_type'] ?? 'unknown');
                if (!empty($r['extracted']['price_uf'])) {
                    $resultFields['price_uf'] = self::doubleVal($r['extracted']['price_uf']);
                }
                if (!empty($r['extracted']['area_m2'])) {
                    $resultFields['area_m2'] = self::doubleVal($r['extracted']['area_m2']);
                }
                $topResults[] = ['mapValue' => ['fields' => $resultFields]];
            }
            $fields['top_results'] = self::arrayVal($topResults);
        }

        // Store expansion suggestions
        if (!empty($data['expansion_suggestions'])) {
            $fields['expansion_suggestions'] = self::arrayVal(
                array_map([self::class, 'stringVal'], $data['expansion_suggestions'])
            );
        }

        // Store diagnostics as map
        if (!empty($data['diagnostics'])) {
            $diagFields = [];
            foreach ($data['diagnostics'] as $k => $v) {
                if (is_int($v)) $diagFields[$k] = self::intVal($v);
                elseif (is_float($v)) $diagFields[$k] = self::doubleVal($v);
                elseif (is_bool($v)) $diagFields[$k] = self::boolVal($v);
                else $diagFields[$k] = self::stringVal((string)$v);
            }
            $fields['diagnostics'] = ['mapValue' => ['fields' => $diagFields]];
        }

        $success = self::createDocument('search_runs', $docId, $fields);
        return $success ? $docId : null;
    }

    /**
     * Log an event to the events collection.
     */
    public static function logEvent(string $type, array $payload = [], ?string $caseId = null, ?string $runId = null): ?string {
        $docId = 'ev_' . bin2hex(random_bytes(8));

        $fields = [
            'type' => self::stringVal($type),
            'timestamp' => self::timestampVal(),
        ];

        if ($caseId) $fields['case_id'] = self::stringVal($caseId);
        if ($runId) $fields['run_id'] = self::stringVal($runId);

        if (!empty($payload)) {
            $payloadFields = [];
            foreach ($payload as $k => $v) {
                if (is_string($v)) $payloadFields[$k] = self::stringVal(mb_substr($v, 0, 500));
                elseif (is_int($v)) $payloadFields[$k] = self::intVal($v);
                elseif (is_float($v)) $payloadFields[$k] = self::doubleVal($v);
                elseif (is_bool($v)) $payloadFields[$k] = self::boolVal($v);
            }
            $fields['payload'] = ['mapValue' => ['fields' => $payloadFields]];
        }

        $success = self::createDocument('events', $docId, $fields);
        return $success ? $docId : null;
    }

    /**
     * Update case with last search metadata.
     */
    public static function updateCaseSearchMeta(string $caseId, array $meta): bool {
        $fields = [
            'last_search_at' => self::timestampVal(),
        ];

        if (!empty($meta['vertical'])) $fields['last_vertical'] = self::stringVal($meta['vertical']);
        if (!empty($meta['location'])) $fields['last_location'] = self::stringVal($meta['location']);
        if (!empty($meta['budget'])) $fields['last_budget'] = self::stringVal($meta['budget']);
        if (!empty($meta['property_type'])) $fields['last_property_type'] = self::stringVal($meta['property_type']);

        return self::updateDocument('cases', $caseId, $fields);
    }

    // ===== FIRESTORE REST API HELPERS =====

    private static function createDocument(string $collection, string $docId, array $fields): bool {
        $url = self::BASE_URL . "/{$collection}?documentId={$docId}";

        // Auto-inject environment into every document
        $fields['environment'] = self::stringVal(self::getEnvironment());

        $body = json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("FirestoreAudit::createDocument failed: HTTP {$httpCode} for {$collection}/{$docId}: " . substr($response, 0, 200));
            return false;
        }

        return true;
    }

    private static function updateDocument(string $collection, string $docId, array $fields): bool {
        // Auto-inject environment into every update
        $fields['environment'] = self::stringVal(self::getEnvironment());

        $updateMasks = array_map(fn($k) => "updateMask.fieldPaths={$k}", array_keys($fields));
        $maskQuery = implode('&', $updateMasks);

        $url = self::BASE_URL . "/{$collection}/{$docId}?{$maskQuery}";

        $body = json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("FirestoreAudit::updateDocument failed: HTTP {$httpCode} for {$collection}/{$docId}: " . substr($response, 0, 200));
            return false;
        }

        return true;
    }


    // ===== ENVIRONMENT DETECTION =====

    /**
     * Detect current environment from server hostname.
     * Used to tag all Firestore writes so staging/production data doesn't mix.
     */
    private static function getEnvironment(): string {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'unknown');
        
        if (strpos($host, 'quebot-production') !== false) {
            return 'production';
        } elseif (strpos($host, 'spirited-purpose') !== false) {
            return 'staging';
        } elseif (strpos($host, 'charming-embrace') !== false) {
            return 'lab';
        } elseif (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            return 'local';
        }
        
        // Check RAILWAY_ENVIRONMENT or similar env vars as fallback
        $railwayEnv = getenv('RAILWAY_ENVIRONMENT');
        if ($railwayEnv) return strtolower($railwayEnv);
        
        return 'unknown';
    }

    // ===== VALUE FORMATTERS =====

    private static function stringVal(string $v): array {
        return ['stringValue' => $v];
    }

    private static function intVal(int $v): array {
        return ['integerValue' => (string) $v];
    }

    private static function doubleVal(float $v): array {
        return ['doubleValue' => $v];
    }

    private static function boolVal(bool $v): array {
        return ['booleanValue' => $v];
    }

    private static function timestampVal(): array {
        return ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    private static function arrayVal(array $values): array {
        return ['arrayValue' => ['values' => $values]];
    }
}
