#!/usr/bin/env php
<?php
/**
 * Policies Runner Cron Script
 *
 * Executes automated policies based on schedules and conditions.
 *
 * Usage:
 * Add to crontab: * * * * * /usr/bin/php /path/to/Matdan-Homelab-Control-Nexus/cron/policies_runner.php
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

echo "[" . date('Y-m-d H:i:s') . "] Starting policies execution...\n";

try {
    // Execute all enabled policies
    $results = PoliciesEngine::executeAll();

    $successCount = count(array_filter($results, fn($r) => $r['success']));
    $failCount = count($results) - $successCount;

    echo "[" . date('Y-m-d H:i:s') . "] Policies execution completed: {$successCount} successful, {$failCount} failed\n";

    // Log summary
    EventLogger::info('cron', "Policies runner: {$successCount} successful, {$failCount} failed", null, [
        'success_count' => $successCount,
        'fail_count' => $failCount,
    ]);

    // Display results
    foreach ($results as $result) {
        if (!$result['success']) {
            echo "[ERROR] Policy {$result['policy_id']}: " . ($result['message'] ?? 'Unknown error') . "\n";
        } elseif (!isset($result['skipped'])) {
            echo "[OK] Policy {$result['policy_id']}: " . ($result['message'] ?? 'Success') . "\n";
        }
    }

    exit(0);
} catch (Exception $e) {
    echo "[ERROR] Fatal error: " . $e->getMessage() . "\n";
    EventLogger::critical('cron_error', 'Policies runner fatal error: ' . $e->getMessage(), null);
    exit(1);
}
