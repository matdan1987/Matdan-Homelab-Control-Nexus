<?php
/**
 * SlackChannel
 *
 * Sends notifications to Slack via Webhook.
 */

class SlackChannel
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $notification): bool
    {
        $webhookUrl = $this->config['webhook_url'] ?? '';

        if (empty($webhookUrl)) {
            return false;
        }

        $payload = [
            'text' => $notification['title'],
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $notification['title'],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $notification['message'],
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '*Severity:* ' . $notification['severity'] . ' | *Time:* ' . $notification['timestamp'],
                        ],
                    ],
                ],
            ],
        ];

        $ch = curl_init($webhookUrl);
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
