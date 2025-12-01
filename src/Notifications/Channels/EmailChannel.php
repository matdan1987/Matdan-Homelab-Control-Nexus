<?php
/**
 * EmailChannel
 *
 * Sends notifications via Email (SMTP).
 */

class EmailChannel
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $notification): bool
    {
        $to = $this->config['to'] ?? '';
        $from = $this->config['from'] ?? 'matdan-control-nexus@homelab.local';
        $fromName = $this->config['from_name'] ?? 'Matdan Control Nexus';

        if (empty($to)) {
            return false;
        }

        $subject = $notification['title'];
        $message = $this->formatMessage($notification);

        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . phpversion(),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];

        return mail($to, $subject, $message, implode("\r\n", $headers));
    }

    private function formatMessage(array $notification): string
    {
        $severity = $notification['severity'];
        $severityColors = [
            'debug' => '#6c757d',
            'info' => '#17a2b8',
            'warning' => '#ffc107',
            'error' => '#dc3545',
            'critical' => '#721c24',
        ];

        $color = $severityColors[$severity] ?? '#17a2b8';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: {$color}; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
        .content { background-color: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none; }
        .footer { font-size: 12px; color: #6c757d; margin-top: 20px; text-align: center; }
        .timestamp { color: #6c757d; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">{$notification['title']}</h2>
        </div>
        <div class="content">
            <p>{$notification['message']}</p>
            <p class="timestamp">Time: {$notification['timestamp']}</p>
        </div>
        <div class="footer">
            <p>Matdan Control Nexus - Homelab Control Center</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
