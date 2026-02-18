<?php
/**
 * QueBot Legal Library - Cron Worker
 * 
 * This file is called by Railway Cron or a scheduler.
 * It runs the daily sync of all core norms.
 * 
 * Usage:
 *   php worker.php                    # Full sync
 *   php worker.php --norm=141599      # Sync single norm
 *   php worker.php --migrate          # Run migrations only
 * 
 * Environment:
 *   DATABASE_URL    - PostgreSQL connection string
 *   ADMIN_TOKEN     - Not required for CLI execution
 */

// Change to script directory for relative requires
chdir(__DIR__);

require_once __DIR__ . '/database.php';

// Parse CLI arguments
$options = getopt('', ['norm:', 'migrate', 'help']);

if (isset($options['help'])) {
    echo "Usage: php worker.php [--norm=IDNORMA] [--migrate] [--help]\n";
    exit(0);
}

// Run migrations if requested
if (isset($options['migrate'])) {
    echo "[" . date('c') . "] Running migrations...\n";
    $migrationsDir = __DIR__ . '/migrations';
    $files = glob($migrationsDir . '/*.sql');
    sort($files);
    foreach ($files as $file) {
        $result = LegalDatabase::migrate($file);
        echo "  " . $result['file'] . ": " . $result['status'];
        if (isset($result['error'])) echo " - " . $result['error'];
        echo "\n";
    }
    echo "Done.\n";
    
    if (!isset($options['norm'])) {
        exit(0);
    }
}

// Run sync
require_once __DIR__ . '/LegalSync.php';

echo "[" . date('c') . "] Starting legal library sync...\n";

$sync = new LegalSync();

if (isset($options['norm'])) {
    echo "Syncing single norm: {$options['norm']}\n";
    $result = $sync->syncOne($options['norm'], 'cron');
} else {
    echo "Syncing all core norms...\n";
    $result = $sync->syncAll('cron');
}

echo "[" . date('c') . "] Sync finished.\n";
echo "Status: {$result['status']}\n";
echo "Summary: {$result['summary']}\n";

if ($result['norms_failed'] > 0) {
    $errors = json_decode($result['errors'], true);
    echo "Errors:\n";
    foreach ($errors as $err) {
        echo "  - Norm {$err['norm_id']}: {$err['error']}\n";
    }
    exit(1);
}

exit(0);
