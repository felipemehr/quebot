<?php
// Try multiple methods to get env variable
$apiKey = getenv('CLAUDE_API_KEY');
if (empty($apiKey)) {
    $apiKey = $_ENV['CLAUDE_API_KEY'] ?? '';
}
if (empty($apiKey)) {
    $apiKey = $_SERVER['CLAUDE_API_KEY'] ?? '';
}

define('CLAUDE_API_KEY', $apiKey);
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');
