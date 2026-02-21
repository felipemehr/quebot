<?php
header('Content-Type: application/json');
echo json_encode([
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'php_sapi' => php_sapi_name(),
    'php_version' => phpversion()
]);
