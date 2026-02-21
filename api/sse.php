<?php
/**
 * SSE (Server-Sent Events) streaming helpers.
 * 
 * Provides:
 *  - initSSE()            — set headers & disable buffering
 *  - emitSSE($event,$data)— emit one SSE event
 *  - streamClaude(...)    — stream Claude API with token callback
 *  - streamOpenAI(...)    — stream OpenAI API with token callback
 */

function initSSE(): void {
    // Remove any previously set Content-Type (PHP allows overwrite)
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // nginx: don't buffer

    // Kill every output buffer layer
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
}

function emitSSE(string $event, $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    flush();
}

/* ── Claude streaming ─────────────────────────────────────────── */

function streamClaude(string $apiKey, array $apiData, callable $onToken): array {
    $apiData['stream'] = true;

    $payload = json_encode($apiData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!$payload) {
        return ['success' => false, 'error' => 'JSON encode failed'];
    }

    $fullReply     = '';
    $inputTokens   = 0;
    $outputTokens  = 0;
    $sseBuffer     = '';

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$fullReply, &$inputTokens, &$outputTokens, &$sseBuffer, $onToken) {
            $sseBuffer .= $chunk;

            // Process complete lines
            while (($nl = strpos($sseBuffer, "\n")) !== false) {
                $line = substr($sseBuffer, 0, $nl);
                $sseBuffer = substr($sseBuffer, $nl + 1);
                $line = trim($line);

                if (strpos($line, 'data: ') !== 0) continue;
                $jsonStr = substr($line, 6);
                $ev = json_decode($jsonStr, true);
                if (!$ev) continue;

                $type = $ev['type'] ?? '';
                if ($type === 'message_start') {
                    $inputTokens = $ev['message']['usage']['input_tokens'] ?? 0;
                } elseif ($type === 'content_block_delta') {
                    $text = $ev['delta']['text'] ?? '';
                    if ($text !== '') {
                        $fullReply .= $text;
                        $onToken($text);
                    }
                } elseif ($type === 'message_delta') {
                    $outputTokens = $ev['usage']['output_tokens'] ?? $outputTokens;
                } elseif ($type === 'error') {
                    error_log('Claude stream error: ' . ($ev['error']['message'] ?? 'unknown'));
                }
            }
            return strlen($chunk);
        }
    ]);

    curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $fullReply === '') {
        return [
            'success'  => false,
            'error'    => $curlError ?: "HTTP {$httpCode}",
            'httpCode' => $httpCode,
        ];
    }
    return [
        'success' => true,
        'reply'   => $fullReply,
        'model'   => $apiData['model'] ?? MODEL,
        'usage'   => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
    ];
}

/* ── OpenAI streaming ─────────────────────────────────────────── */

function streamOpenAI(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens, callable $onToken): array {
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'OPENAI_API_KEY not configured'];
    }

    $oaiMessages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($messages as $m) {
        $oaiMessages[] = ['role' => $m['role'], 'content' => $m['content']];
    }

    $payload = json_encode([
        'model'       => $model,
        'messages'    => $oaiMessages,
        'max_tokens'  => $maxTokens,
        'temperature' => 0.7,
        'stream'      => true,
        'stream_options' => ['include_usage' => true],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $fullReply    = '';
    $inputTokens  = 0;
    $outputTokens = 0;
    $sseBuffer    = '';

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$fullReply, &$inputTokens, &$outputTokens, &$sseBuffer, $onToken) {
            $sseBuffer .= $chunk;

            while (($nl = strpos($sseBuffer, "\n")) !== false) {
                $line = substr($sseBuffer, 0, $nl);
                $sseBuffer = substr($sseBuffer, $nl + 1);
                $line = trim($line);

                if (strpos($line, 'data: ') !== 0) continue;
                $content = substr($line, 6);
                if ($content === '[DONE]') continue;

                $ev = json_decode($content, true);
                if (!$ev) continue;

                $text = $ev['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') {
                    $fullReply .= $text;
                    $onToken($text);
                }
                if (isset($ev['usage'])) {
                    $inputTokens  = $ev['usage']['prompt_tokens']     ?? $inputTokens;
                    $outputTokens = $ev['usage']['completion_tokens'] ?? $outputTokens;
                }
            }
            return strlen($chunk);
        }
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $fullReply === '') {
        return ['success' => false, 'error' => "HTTP {$httpCode}"];
    }
    return [
        'success' => true,
        'reply'   => $fullReply,
        'model'   => $model,
        'usage'   => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
    ];
}
