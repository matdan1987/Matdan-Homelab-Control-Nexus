<?php
/**
 * GotifyChannel
 *
 * Sends notifications to Gotify self-hosted notification server.
 */

class GotifyChannel
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $notification): bool
    {
        $serverUrl = rtrim($this->config['server_url'] ?? '', '/');
        $appToken = $this->config['app_token'] ?? '';

        if (empty($serverUrl) || empty($appToken)) {
            return false;
        }

        $severity = $notification['severity'];
        $priorityMap = [
            'debug' => 0,
            'info' => 2,
            'warning' => 5,
            'error' => 7,
            'critical' => 10,
        ];

        $priority = $priorityMap[$severity] ?? 2;

        // Build message with context
        $message = $notification['message'];

        if (!empty($notification['context'])) {
            $message .= "\n\n";
            foreach ($notification['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $label = ucfirst(str_replace('_', ' ', $key));
                    $message .= "{$label}: {$value}\n";
                }
            }
        }

        $payload = [
            'title' => $notification['title'],
            'message' => $message,
            'priority' => $priority,
            'extras' => [
                'mcn::severity' => $severity,
                'mcn::timestamp' => $notification['timestamp'],
            ],
        ];

        $url = "{$serverUrl}/message?token={$appToken}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}
