#!/usr/bin/env php
<?php
/**
 * Backups Runner Cron Script
 *
 * Executes scheduled backup policies.
 *
 * Usage:
 * Add to crontab: 0 2 * * * /usr/bin/php /path/to/Matdan-Homelab-Control-Nexus/cron/backups_runner.php
 */

// Change to project root directory
chdir(dirname(__DIR__));

// Prevent direct access via web
define('MCN_CONFIG', true);

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoloader
spl_autoload_register(function ($className) use ($config) {
    $srcDir = $config['paths']['src'];
    $classFile = $srcDir . '/' . $className . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Initialize Database
try {
    Database::init($config['database']);
} catch (Exception $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Initialize Event Logger
EventLogger::init($config['logging']);

echo "[" . date('Y-m-d H:i:s') . "] Starting backup execution...\n";

try {
    // Execute all due backup policies
    $results = BackupManager::executeAll();

    $successCount = 0;
    $failCount = 0;

    foreach ($results as $result) {
        if ($result['success']) {
            $policySuccessCount = count(array_filter($result['backups'] ?? [], fn($b) => $b['success']));
            $policyFailCount = count($result['backups'] ?? []) - $policySuccessCount;

            $successCount += $policySuccessCount;
            $failCount += $policyFailCount;

            echo "[OK] Policy {$result['policy_id']}: {$policySuccessCount} successful, {$policyFailCount} failed\n";
        } else {
            $failCount++;
            echo "[ERROR] Policy {$result['policy_id']}: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Backup execution completed: {$successCount} successful, {$failCount} failed\n";

    // Log summary
    EventLogger::info('cron', "Backup runner: {$successCount} successful, {$failCount} failed", null, [
        'success_count' => $successCount,
        'fail_count' => $failCount,
    ]);

    exit(0);
} catch (Exception $e) {
    echo "[ERROR] Fatal error: " . $e->getMessage() . "\n";
    EventLogger::critical('cron_error', 'Backup runner fatal error: ' . $e->getMessage(), null);
    exit(1);
}
