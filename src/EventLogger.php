<?php
/**
 * EventLogger Class
 *
 * Centralized event and audit logging system.
 * Logs events to database and optionally to file system.
 */

class EventLogger
{
    private static array $config = [];

    /**
     * Initialize event logger
     *
     * @param array $config Logging configuration
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Log an event
     *
     * @param string $type Event type (e.g., policy_action, backup_result, pve_error, user_action)
     * @param string $severity Severity level (debug, info, warning, error, critical)
     * @param string $message Event message
     * @param int|null $userId User ID (if applicable)
     * @param array|null $context Additional context data
     * @return int Event ID
     */
    public static function log(
        string $type,
        string $severity,
        string $message,
        ?int $userId = null,
        ?array $context = null
    ): int {
        // Log to database if enabled
        $eventId = 0;
        if (self::shouldLogToDatabase($severity)) {
            $eventId = self::logToDatabase($type, $severity, $message, $userId, $context);
        }

        // Log to file if enabled
        if (self::shouldLogToFile($severity)) {
            self::logToFile($type, $severity, $message, $userId, $context);
        }

        return $eventId;
    }

    /**
     * Log to database
     */
    private static function logToDatabase(
        string $type,
        string $severity,
        string $message,
        ?int $userId,
        ?array $context
    ): int {
        try {
            return Database::insert(
                'INSERT INTO events (type, severity, message, user_id, context_json) VALUES (?, ?, ?, ?, ?)',
                [
                    $type,
                    $severity,
                    $message,
                    $userId,
                    $context ? json_encode($context) : null
                ]
            );
        } catch (Exception $e) {
            // Fallback to file logging if database fails
            self::logToFile('system_error', 'error', 'Failed to log to database: ' . $e->getMessage(), null, [
                'original_event' => [
                    'type' => $type,
                    'severity' => $severity,
                    'message' => $message
                ]
            ]);
            return 0;
        }
    }

    /**
     * Log to file
     */
    private static function logToFile(
        string $type,
        string $severity,
        string $message,
        ?int $userId,
        ?array $context
    ): void {
        $logFile = self::$config['file'] ?? '/tmp/matdan-control-nexus.log';

        // Create log directory if it doesn't exist
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Format log entry
        $timestamp = date('Y-m-d H:i:s');
        $userInfo = $userId ? "[User:$userId]" : '';
        $contextInfo = $context ? ' ' . json_encode($context) : '';
        $logEntry = "[$timestamp] [$severity] [$type] $userInfo $message$contextInfo\n";

        // Check file size and rotate if needed
        if (file_exists($logFile)) {
            $maxSize = self::$config['max_size'] ?? 10485760; // 10 MB
            if (filesize($logFile) > $maxSize) {
                self::rotateLogFile($logFile);
            }
        }

        // Write to log file
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate log file
     */
    private static function rotateLogFile(string $logFile): void
    {
        $maxFiles = self::$config['max_files'] ?? 5;

        // Shift existing rotated files
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $oldFile = "$logFile.$i";
            $newFile = "$logFile." . ($i + 1);

            if (file_exists($oldFile)) {
                if ($i >= $maxFiles - 1) {
                    @unlink($oldFile);
                } else {
                    @rename($oldFile, $newFile);
                }
            }
        }

        // Rotate current log file
        @rename($logFile, "$logFile.1");
    }

    /**
     * Check if should log to database based on severity
     */
    private static function shouldLogToDatabase(string $severity): bool
    {
        if (!isset(self::$config['database_logging']) || !self::$config['database_logging']) {
            return false;
        }

        $minimumLevel = self::$config['database_log_level'] ?? 'info';
        return self::compareSeverity($severity, $minimumLevel) >= 0;
    }

    /**
     * Check if should log to file based on severity
     */
    private static function shouldLogToFile(string $severity): bool
    {
        $minimumLevel = self::$config['level'] ?? 'info';
        return self::compareSeverity($severity, $minimumLevel) >= 0;
    }

    /**
     * Compare severity levels
     *
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    private static function compareSeverity(string $a, string $b): int
    {
        $levels = [
            'debug' => 1,
            'info' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
        ];

        $levelA = $levels[$a] ?? 0;
        $levelB = $levels[$b] ?? 0;

        return $levelA <=> $levelB;
    }

    /**
     * Shorthand methods for different severity levels
     */
    public static function debug(string $type, string $message, ?int $userId = null, ?array $context = null): int
    {
        return self::log($type, 'debug', $message, $userId, $context);
    }

    public static function info(string $type, string $message, ?int $userId = null, ?array $context = null): int
    {
        return self::log($type, 'info', $message, $userId, $context);
    }

    public static function warning(string $type, string $message, ?int $userId = null, ?array $context = null): int
    {
        return self::log($type, 'warning', $message, $userId, $context);
    }

    public static function error(string $type, string $message, ?int $userId = null, ?array $context = null): int
    {
        return self::log($type, 'error', $message, $userId, $context);
    }

    public static function critical(string $type, string $message, ?int $userId = null, ?array $context = null): int
    {
        return self::log($type, 'critical', $message, $userId, $context);
    }

    /**
     * Get recent events
     *
     * @param int $limit Number of events to retrieve
     * @param array $filters Optional filters (type, severity, user_id)
     * @return array Events list
     */
    public static function getRecentEvents(int $limit = 100, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }

        if (isset($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }

        if (isset($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT e.*, u.username
                  FROM events e
                  LEFT JOIN users u ON e.user_id = u.id
                  $whereClause
                  ORDER BY e.created_at DESC
                  LIMIT ?";

        $params[] = $limit;

        return Database::select($query, $params);
    }

    /**
     * Get event statistics
     *
     * @param string|null $timeframe Timeframe (hour, day, week, month)
     * @return array Statistics
     */
    public static function getStatistics(?string $timeframe = 'day'): array
    {
        $intervals = [
            'hour' => 'DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            'day' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
            'week' => 'DATE_SUB(NOW(), INTERVAL 1 WEEK)',
            'month' => 'DATE_SUB(NOW(), INTERVAL 1 MONTH)',
        ];

        $since = $intervals[$timeframe] ?? $intervals['day'];

        // Count by severity
        $bySeverity = Database::select(
            "SELECT severity, COUNT(*) as count
             FROM events
             WHERE created_at >= $since
             GROUP BY severity"
        );

        // Count by type
        $byType = Database::select(
            "SELECT type, COUNT(*) as count
             FROM events
             WHERE created_at >= $since
             GROUP BY type
             ORDER BY count DESC
             LIMIT 10"
        );

        return [
            'by_severity' => $bySeverity,
            'by_type' => $byType,
        ];
    }

    /**
     * Clean up old events
     *
     * @param int $daysToKeep Number of days to keep events
     * @return int Number of deleted events
     */
    public static function cleanup(int $daysToKeep = 90): int
    {
        return Database::delete(
            'DELETE FROM events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$daysToKeep]
        );
    }
}
