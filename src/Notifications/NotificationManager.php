<?php
/**
 * NotificationManager
 *
 * Central notification management system.
 * Supports multiple channels: Email, Slack, Discord, Telegram, Gotify.
 */

class NotificationManager
{
    private static array $config = [];
    private static array $channels = [];

    /**
     * Initialize notification manager
     */
    public static function init(array $config): void
    {
        self::$config = $config;
        self::loadChannels();
    }

    /**
     * Load enabled notification channels
     */
    private static function loadChannels(): void
    {
        $enabledChannels = Database::select(
            'SELECT * FROM notification_channels WHERE enabled = 1'
        );

        foreach ($enabledChannels as $channel) {
            $config = json_decode($channel['config_json'], true);

            switch ($channel['type']) {
                case 'email':
                    self::$channels[] = new EmailChannel($config);
                    break;
                case 'slack':
                    self::$channels[] = new SlackChannel($config);
                    break;
                case 'discord':
                    self::$channels[] = new DiscordChannel($config);
                    break;
                case 'telegram':
                    self::$channels[] = new TelegramChannel($config);
                    break;
                case 'gotify':
                    self::$channels[] = new GotifyChannel($config);
                    break;
            }
        }
    }

    /**
     * Send notification to all enabled channels
     */
    public static function send(string $title, string $message, string $severity = 'info', array $context = []): bool
    {
        $notification = [
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Check if we should send based on severity
        if (!self::shouldNotify($severity)) {
            return false;
        }

        // Log notification
        Database::insert(
            'INSERT INTO notifications (title, message, severity, context_json, sent_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$title, $message, $severity, json_encode($context)]
        );

        $sentCount = 0;
        $errors = [];

        foreach (self::$channels as $channel) {
            try {
                if ($channel->send($notification)) {
                    $sentCount++;
                }
            } catch (Exception $e) {
                $errors[] = get_class($channel) . ': ' . $e->getMessage();
                EventLogger::error('notification_error',
                    'Failed to send notification via ' . get_class($channel),
                    null,
                    ['error' => $e->getMessage()]
                );
            }
        }

        if (!empty($errors)) {
            EventLogger::warning('notification_partial',
                "Notification sent to $sentCount channels, " . count($errors) . " failed",
                null,
                ['errors' => $errors]
            );
        }

        return $sentCount > 0;
    }

    /**
     * Check if notification should be sent based on severity and cooldown
     */
    private static function shouldNotify(string $severity): bool
    {
        $minSeverity = self::$config['min_severity'] ?? 'info';

        $severityLevels = [
            'debug' => 1,
            'info' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
        ];

        return ($severityLevels[$severity] ?? 0) >= ($severityLevels[$minSeverity] ?? 0);
    }

    /**
     * Send alert for specific event types
     */
    public static function alert(string $type, string $message, array $context = []): bool
    {
        $titles = [
            'vm_down' => '🔴 VM Down',
            'backup_failed' => '⚠️ Backup Failed',
            'node_offline' => '🔴 Node Offline',
            'resource_critical' => '⚠️ Resource Critical',
            'policy_failed' => '⚠️ Policy Execution Failed',
        ];

        $title = $titles[$type] ?? '📢 Alert';

        return self::send($title, $message, 'warning', $context);
    }

    /**
     * Send critical alert
     */
    public static function critical(string $message, array $context = []): bool
    {
        return self::send('🚨 CRITICAL ALERT', $message, 'critical', $context);
    }

    /**
     * Send info notification
     */
    public static function info(string $message, array $context = []): bool
    {
        return self::send('ℹ️ Information', $message, 'info', $context);
    }

    /**
     * Send success notification
     */
    public static function success(string $message, array $context = []): bool
    {
        return self::send('✅ Success', $message, 'info', $context);
    }
}
