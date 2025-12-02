<?php
/**
 * DiscordChannel
 *
 * Sends notifications to Discord via Webhook.
 */

class DiscordChannel
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

        $severity = $notification['severity'];
        $severityColors = [
            'debug' => 0x6c757d,
            'info' => 0x17a2b8,
            'warning' => 0xffc107,
            'error' => 0xdc3545,
            'critical' => 0x721c24,
        ];

        $color = $severityColors[$severity] ?? 0x17a2b8;

        $payload = [
            'embeds' => [
                [
                    'title' => $notification['title'],
                    'description' => $notification['message'],
                    'color' => $color,
                    'fields' => [
                        [
                            'name' => 'Severity',
                            'value' => strtoupper($severity),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Time',
                            'value' => $notification['timestamp'],
                            'inline' => true,
                        ],
                    ],
                    'footer' => [
                        'text' => 'Matdan Control Nexus',
                    ],
                ],
            ],
        ];

        // Add context fields if present
        if (!empty($notification['context'])) {
            foreach ($notification['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $payload['embeds'][0]['fields'][] = [
                        'name' => ucfirst(str_replace('_', ' ', $key)),
                        'value' => (string)$value,
                        'inline' => true,
                    ];
                }
            }
        }

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 204 || $httpCode === 200;
    }
}
