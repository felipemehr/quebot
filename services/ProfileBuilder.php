<?php
/**
 * ProfileBuilder — Extracts user preferences using Claude Haiku
 * Builds a structured profile from conversation exchanges.
 * Part of QueBot Memory System (B1).
 */
class ProfileBuilder {
    private string $apiKey;
    private string $model = 'claude-3-5-haiku-20241022';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    private int $timeout = 8;

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Determine if a message is worth extracting preferences from.
     * Skip trivial/short messages to save API calls.
     */
    public function shouldExtract(string $message): bool {
        $msg = trim($message);
        if (mb_strlen($msg) < 12) return false;

        $trivials = [
            'hola', 'gracias', 'ok', 'dale', 'sí', 'si', 'no', 
            'chao', 'bueno', 'listo', 'perfecto', 'genial', 'vale',
            'claro', 'ya', 'ah', 'oh', 'mmm', 'jaja', 'xd',
            'buenos días', 'buenas tardes', 'buenas noches',
            'muchas gracias', 'de nada', 'hasta luego', 'adiós'
        ];
        
        $lower = mb_strtolower($msg);
        foreach ($trivials as $t) {
            if ($lower === $t) return false;
        }
        
        return true;
    }

    /**
     * Extract user preferences from a conversation exchange.
     * 
     * @param string $userMessage The user's message
     * @param string $assistantResponse The assistant's response
     * @param array|null $existingProfile Current profile to merge with
     * @return array|null Updated profile or null if nothing new
     */
    public function extractProfile(
        string $userMessage, 
        string $assistantResponse, 
        ?array $existingProfile = null
    ): ?array {
        $existingJson = $existingProfile 
            ? json_encode($existingProfile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) 
            : '{}';

        // Truncate assistant response to save tokens (we mainly need user intent)
        $shortResponse = mb_substr($assistantResponse, 0, 500);

        $prompt = "Analiza este intercambio. Extrae SOLO preferencias que el usuario revela EXPLÍCITAMENTE.\n\n";
        $prompt .= "PERFIL ACTUAL:\n{$existingJson}\n\n";
        $prompt .= "USUARIO:\n{$userMessage}\n\n";
        $prompt .= "ASISTENTE (resumen):\n{$shortResponse}\n\n";
        $prompt .= "Responde SOLO JSON válido (sin markdown, sin ```).\n";
        $prompt .= "Solo incluye campos con info NUEVA y EXPLÍCITA:\n\n";
        $prompt .= "{\n";
        $prompt .= "  \"locations\": [\"ciudades/comunas mencionadas\"],\n";
        $prompt .= "  \"property_types\": [\"casa\",\"departamento\",\"parcela\",\"terreno\"],\n";
        $prompt .= "  \"bedrooms\": null,\n";
        $prompt .= "  \"bathrooms\": null,\n";
        $prompt .= "  \"budget\": {\"min\": 0, \"max\": 0, \"unit\": \"UF\"} ,\n";
        $prompt .= "  \"min_area_m2\": null,\n";
        $prompt .= "  \"purpose\": \"inversión|uso personal|null\",\n";
        $prompt .= "  \"interests\": [\"propiedades\",\"legal\",\"noticias\",\"retail\",\"autos\"],\n";
        $prompt .= "  \"key_requirements\": [\"agua\",\"luz\",\"internet\",\"acceso\"],\n";
        $prompt .= "  \"family_info\": null\n";
        $prompt .= "}\n\n";
        $prompt .= "REGLAS:\n";
        $prompt .= "- NO inventes. Solo lo TEXTUAL del usuario.\n";
        $prompt .= "- Merge: agregar a arrays sin duplicar, actualizar escalares si hay dato nuevo.\n";
        $prompt .= "- Sin info nueva → responde: {}\n";

        $requestData = [
            'model' => $this->model,
            'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("ProfileBuilder curl error: {$curlError}");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("ProfileBuilder: Haiku HTTP {$httpCode}");
            return null;
        }

        $result = json_decode($response, true);
        $text = trim($result['content'][0]['text'] ?? '');

        // Clean markdown wrappers if present
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $extracted = json_decode($text, true);
        if (!$extracted || !is_array($extracted)) {
            error_log("ProfileBuilder: Could not parse JSON: " . mb_substr($text, 0, 100));
            return null;
        }

        // If empty (no new info), return null
        $filtered = array_filter($extracted, fn($v) => $v !== null && $v !== '' && $v !== []);
        if (empty($filtered)) return null;

        // Track Haiku token usage
        $haikuTokens = [
            'input' => $result['usage']['input_tokens'] ?? 0,
            'output' => $result['usage']['output_tokens'] ?? 0
        ];
        error_log("ProfileBuilder: Haiku tokens in={$haikuTokens['input']} out={$haikuTokens['output']}");

        return $this->mergeProfiles($existingProfile ?? [], $extracted);
    }

    /**
     * Merge extracted preferences into existing profile.
     * Arrays are deduplicated. Scalars are overwritten only if new value is non-null.
     */
    private function mergeProfiles(array $existing, array $new): array {
        $merged = $existing;

        // Merge array fields (deduplicate case-insensitive)
        $arrayFields = ['locations', 'property_types', 'interests', 'key_requirements'];
        foreach ($arrayFields as $field) {
            if (!empty($new[$field]) && is_array($new[$field])) {
                $existingArr = $merged[$field] ?? [];
                $combined = array_merge($existingArr, $new[$field]);
                $seen = [];
                $unique = [];
                foreach ($combined as $item) {
                    $lower = mb_strtolower(trim((string)$item));
                    if ($lower !== '' && !in_array($lower, $seen)) {
                        $seen[] = $lower;
                        $unique[] = trim((string)$item);
                    }
                }
                $merged[$field] = $unique;
            }
        }

        // Merge scalar fields
        $scalarFields = ['bedrooms', 'bathrooms', 'min_area_m2', 'purpose', 'family_info'];
        foreach ($scalarFields as $field) {
            if (isset($new[$field]) && $new[$field] !== null) {
                $merged[$field] = $new[$field];
            }
        }

        // Merge budget (structured)
        if (!empty($new['budget']) && is_array($new['budget'])) {
            $merged['budget'] = $new['budget'];
        }

        $merged['updated_at'] = date('c');

        return $merged;
    }
}
