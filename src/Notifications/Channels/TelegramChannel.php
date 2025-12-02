<?php
/**
 * TelegramChannel
 *
 * Sends notifications to Telegram via Bot API.
 */

class TelegramChannel
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $notification): bool
    {
        $botToken = $this->config['bot_token'] ?? '';
        $chatId = $this->config['chat_id'] ?? '';

        if (empty($botToken) || empty($chatId)) {
            return false;
        }

        $severity = $notification['severity'];
        $severityEmojis = [
            'debug' => '🔍',
            'info' => 'ℹ️',
            'warning' => '⚠️',
            'error' => '❌',
            'critical' => '🚨',
        ];

        $emoji = $severityEmojis[$severity] ?? 'ℹ️';

        // Build message in Markdown format
        $message = "*{$emoji} {$notification['title']}*\n\n";
        $message .= "{$notification['message']}\n\n";
        $message .= "*Severity:* " . strtoupper($severity) . "\n";
        $message .= "*Time:* {$notification['timestamp']}\n";

        // Add context if present
        if (!empty($notification['context'])) {
            $message .= "\n*Additional Details:*\n";
            foreach ($notification['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $label = ucfirst(str_replace('_', ' ', $key));
                    $message .= "• *{$label}:* {$value}\n";
                }
            }
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $result = json_decode($response, true);
        return isset($result['ok']) && $result['ok'] === true;
    }
}
