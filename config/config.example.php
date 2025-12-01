<?php
/**
 * Matdan Control Nexus - Configuration File
 *
 * This file contains all configuration settings for the application.
 * Make sure to update the values below according to your environment.
 *
 * SECURITY WARNING: This file contains sensitive information.
 * Ensure it is not accessible from the web!
 */

// Prevent direct access
if (!defined('MCN_CONFIG')) {
    http_response_code(403);
    die('Direct access not permitted');
}

return [
    // ========================================================================
    // DATABASE CONFIGURATION
    // ========================================================================
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'matdan_control_nexus',
        'username' => 'mcn_user',
        'password' => 'your_secure_password_here',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    // ========================================================================
    // APPLICATION SETTINGS
    // ========================================================================
    'app' => [
        'name' => 'Matdan Control Nexus',
        'version' => '1.0.0',
        'timezone' => 'Europe/Berlin',
        'debug' => false, // Set to true for development, false for production
        'maintenance_mode' => false,
    ],

    // ========================================================================
    // SESSION CONFIGURATION
    // ========================================================================
    'session' => [
        'name' => 'MCN_SESSION',
        'lifetime' => 3600, // Session lifetime in seconds (1 hour)
        'cookie_secure' => false, // Set to true if using HTTPS
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ],

    // ========================================================================
    // SECURITY SETTINGS
    // ========================================================================
    'security' => [
        // CSRF protection
        'csrf_enabled' => true,
        'csrf_token_name' => 'csrf_token',

        // Password hashing
        'password_algorithm' => PASSWORD_BCRYPT,
        'password_options' => ['cost' => 10],

        // IP whitelist (empty array = allow all)
        // Example: ['192.168.1.0/24', '10.0.0.0/8']
        'ip_whitelist' => [],

        // Maximum login attempts
        'max_login_attempts' => 5,
        'login_lockout_duration' => 900, // 15 minutes in seconds
    ],

    // ========================================================================
    // PROXMOX VE SETTINGS
    // ========================================================================
    'proxmox' => [
        // Default timeout for API requests in seconds
        'api_timeout' => 10,

        // Cache TTL for resource data in seconds
        'cache_ttl' => 300, // 5 minutes

        // Verify SSL certificates (set to false for self-signed certs)
        'verify_ssl' => false,

        // Maximum concurrent API requests
        'max_concurrent_requests' => 5,
    ],

    // ========================================================================
    // POLICY ENGINE SETTINGS
    // ========================================================================
    'policies' => [
        // How often to check policies (used by cron)
        'check_interval' => 60, // seconds

        // Minimum time between policy actions on same resource
        'cooldown_period' => 300, // 5 minutes

        // Enable dry-run mode (log actions but don't execute)
        'dry_run' => false,
    ],

    // ========================================================================
    // BACKUP SETTINGS
    // ========================================================================
    'backups' => [
        // Default backup storage location (Proxmox storage ID)
        'default_storage' => 'local',

        // Default retention count
        'default_retention' => 7,

        // Default compression
        'default_compression' => 'zstd',

        // Backup job timeout in seconds
        'job_timeout' => 3600, // 1 hour
    ],

    // ========================================================================
    // INTEGRATIONS SETTINGS
    // ========================================================================
    'integrations' => [
        // Sync interval in seconds
        'sync_interval' => 600, // 10 minutes

        // API request timeout
        'api_timeout' => 10,

        // Retry failed requests
        'retry_attempts' => 3,
        'retry_delay' => 5, // seconds
    ],

    // ========================================================================
    // LOGGING SETTINGS
    // ========================================================================
    'logging' => [
        // Log level: debug, info, warning, error, critical
        'level' => 'info',

        // Log file path (relative to project root or absolute)
        'file' => '/var/log/matdan-control-nexus/app.log',

        // Maximum log file size in bytes (0 = unlimited)
        'max_size' => 10485760, // 10 MB

        // Number of rotated log files to keep
        'max_files' => 5,

        // Log to database (events table)
        'database_logging' => true,

        // Minimum severity to log to database
        'database_log_level' => 'warning',
    ],

    // ========================================================================
    // DASHBOARD SETTINGS
    // ========================================================================
    'dashboard' => [
        // Default widgets for new users
        'default_widgets' => [
            ['type' => 'nodes_overview', 'position' => 0],
            ['type' => 'critical_services', 'position' => 1],
            ['type' => 'recent_events', 'position' => 2],
            ['type' => 'top_cpu_consumers', 'position' => 3],
        ],

        // Available widget types
        'available_widgets' => [
            'nodes_overview',
            'critical_services',
            'recent_events',
            'top_cpu_consumers',
            'top_memory_consumers',
            'maintenance_windows',
            'backup_status',
            'integration_status',
        ],

        // Refresh interval for dashboard data in seconds
        'refresh_interval' => 30,
    ],

    // ========================================================================
    // PATHS
    // ========================================================================
    'paths' => [
        'root' => dirname(__DIR__),
        'public' => dirname(__DIR__) . '/public',
        'src' => dirname(__DIR__) . '/src',
        'api' => dirname(__DIR__) . '/api',
        'config' => __DIR__,
    ],

    // ========================================================================
    // EMAIL SETTINGS (for notifications - optional)
    // ========================================================================
    'email' => [
        'enabled' => false,
        'from_address' => 'matdan-control-nexus@homelab.local',
        'from_name' => 'Matdan Control Nexus',

        // SMTP settings
        'smtp' => [
            'host' => 'smtp.homelab.local',
            'port' => 587,
            'encryption' => 'tls', // tls or ssl
            'username' => '',
            'password' => '',
        ],
    ],

    // ========================================================================
    // ALERT SETTINGS
    // ========================================================================
    'alerts' => [
        // Enable alerting system
        'enabled' => true,

        // Alert thresholds
        'thresholds' => [
            'cpu_critical' => 90, // percent
            'cpu_warning' => 75,
            'memory_critical' => 90,
            'memory_warning' => 75,
            'disk_critical' => 90,
            'disk_warning' => 80,
        ],

        // Alert cooldown (don't re-alert within this period)
        'cooldown' => 3600, // 1 hour
    ],
];
