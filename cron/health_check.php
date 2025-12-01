#!/usr/bin/env php
<?php
/**
 * Health Check Cron Script
 *
 * Checks health of all Proxmox nodes and updates cache.
 *
 * Usage:
 * Add to crontab: */5 * * * * /usr/bin/php /path/to/Matdan-Homelab-Control-Nexus/cron/health_check.php
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

echo "[" . date('Y-m-d H:i:s') . "] Starting health check...\n";

try {
    // Get all enabled nodes
    $nodes = NodesRepository::getAll(true);

    $onlineCount = 0;
    $offlineCount = 0;
    $errorCount = 0;

    foreach ($nodes as $node) {
        echo "Checking node {$node['name']}... ";

        try {
            // Test connection
            $success = NodesRepository::testConnection($node['id']);

            if ($success) {
                echo "OK\n";
                $onlineCount++;

                // Update resource cache
                try {
                    $resourceCount = NodesRepository::updateResourceCache($node['id']);
                    echo "  Cached {$resourceCount} resources\n";
                } catch (Exception $e) {
                    echo "  [WARNING] Failed to update cache: " . $e->getMessage() . "\n";
                }
            } else {
                echo "OFFLINE\n";
                $offlineCount++;
            }
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Health check completed: {$onlineCount} online, {$offlineCount} offline, {$errorCount} errors\n";

    // Log summary
    EventLogger::info('cron', "Health check: {$onlineCount} online, {$offlineCount} offline, {$errorCount} errors", null, [
        'online_count' => $onlineCount,
        'offline_count' => $offlineCount,
        'error_count' => $errorCount,
    ]);

    // Cleanup old events (keep last 90 days)
    $deletedEvents = EventLogger::cleanup(90);
    if ($deletedEvents > 0) {
        echo "Cleaned up {$deletedEvents} old events\n";
    }

    // Cleanup old sessions (keep last 24 hours)
    Auth::cleanupOldSessions(86400);

    exit(0);
} catch (Exception $e) {
    echo "[ERROR] Fatal error: " . $e->getMessage() . "\n";
    EventLogger::critical('cron_error', 'Health check fatal error: ' . $e->getMessage(), null);
    exit(1);
}
