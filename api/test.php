<?php
header('Content-Type: text/plain');
echo "ENV methods:\n";
echo "getenv: " . (getenv('CLAUDE_API_KEY') ? 'FOUND' : 'NOT FOUND') . "\n";
echo "_ENV: " . (isset($_ENV['CLAUDE_API_KEY']) ? 'FOUND' : 'NOT FOUND') . "\n";
echo "_SERVER: " . (isset($_SERVER['CLAUDE_API_KEY']) ? 'FOUND' : 'NOT FOUND') . "\n";
