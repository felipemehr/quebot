<?php
$apiKey = getenv('CLAUDE_API_KEY') ?: ($_ENV['CLAUDE_API_KEY'] ?? '');
define('CLAUDE_API_KEY', $apiKey);
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');
define('API_CONFIGURED', !empty($apiKey));
