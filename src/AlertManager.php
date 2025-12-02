<?php
/**
 * AlertManager
 *
 * Complete alert management system with rule evaluation,
 * notification routing, and alert history tracking.
 */

class AlertManager
{
    /**
     * Process alert from Prometheus webhook
     */
    public static function processWebhook(array $payload): void
    {
        if (!isset($payload['alerts']) || !is_array($payload['alerts'])) {
            throw new Exception('Invalid webhook payload');
        }

        foreach ($payload['alerts'] as $alert) {
            self::processAlert($alert);
        }
    }

    /**
     * Process individual alert
     */
    private static function processAlert(array $alert): void
    {
        $labels = $alert['labels'] ?? [];
        $annotations = $alert['annotations'] ?? [];
        $status = $alert['status'] ?? 'firing';

        $alertName = $labels['alertname'] ?? 'Unknown Alert';
        $severity = $labels['severity'] ?? 'warning';
        $instance = $labels['instance'] ?? 'unknown';

        // Check if alert already exists
        $fingerprint = self::generateFingerprint($labels);

        $existing = Database::selectOne(
            'SELECT * FROM alerts WHERE fingerprint = ? AND status = "firing"',
            [$fingerprint]
        );

        if ($status === 'firing') {
            if (!$existing) {
                // Create new alert
                $alertId = Database::insert(
                    'INSERT INTO alerts (fingerprint, name, severity, instance, labels_json, annotations_json, status, fired_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $fingerprint,
                        $alertName,
                        $severity,
                        $instance,
                        json_encode($labels),
                        json_encode($annotations),
                        'firing'
                    ]
                );

                EventLogger::warning('alert_fired', "Alert fired: $alertName", null, [
                    'alert_id' => $alertId,
                    'severity' => $severity,
                    'instance' => $instance,
                ]);

                // Send notifications
                self::sendAlertNotification($alertName, $annotations['description'] ?? $annotations['summary'] ?? 'No description', $severity, $labels);

                // Check if alert rule has actions
                self::executeAlertActions($alertName, $labels);

            } else {
                // Update existing alert
                Database::update(
                    'UPDATE alerts SET last_seen_at = NOW() WHERE id = ?',
                    [$existing['id']]
                );
            }
        } elseif ($status === 'resolved') {
            if ($existing) {
                // Resolve alert
                Database::update(
                    'UPDATE alerts SET status = ?, resolved_at = NOW() WHERE id = ?',
                    ['resolved', $existing['id']]
                );

                EventLogger::info('alert_resolved', "Alert resolved: $alertName", null, [
                    'alert_id' => $existing['id'],
                    'instance' => $instance,
                ]);

                // Send resolution notification
                NotificationManager::success("Alert resolved: $alertName on $instance", $labels);
            }
        }
    }

    /**
     * Generate unique fingerprint for alert based on labels
     */
    private static function generateFingerprint(array $labels): string
    {
        ksort($labels);
        return md5(json_encode($labels));
    }

    /**
     * Send alert notification
     */
    private static function sendAlertNotification(string $alertName, string $description, string $severity, array $labels): void
    {
        $instance = $labels['instance'] ?? 'unknown';
        $message = "$description\n\nInstance: $instance";

        if ($severity === 'critical') {
            NotificationManager::critical("$alertName", $message, $labels);
        } else {
            NotificationManager::alert('resource_critical', "$alertName: $description", $labels);
        }
    }

    /**
     * Execute automated actions for alert
     */
    private static function executeAlertActions(string $alertName, array $labels): void
    {
        // Get alert rule
        $rule = Database::selectOne(
            'SELECT * FROM alert_rules WHERE name = ? AND enabled = 1',
            [$alertName]
        );

        if (!$rule || !$rule['actions_json']) {
            return;
        }

        $actions = json_decode($rule['actions_json'], true);

        foreach ($actions as $action) {
            try {
                self::executeAction($action, $labels);
            } catch (Exception $e) {
                EventLogger::error('alert_action_failed', "Failed to execute alert action: {$e->getMessage()}", null, [
                    'alert' => $alertName,
                    'action' => $action['type'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute individual action
     */
    private static function executeAction(array $action, array $labels): void
    {
        $type = $action['type'] ?? null;

        switch ($type) {
            case 'restart_vm':
                if (isset($action['vmid']) && isset($action['node'])) {
                    $node = Database::selectOne('SELECT * FROM pve_nodes WHERE name = ?', [$action['node']]);
                    if ($node) {
                        $pve = new ProxmoxApiClient($node['hostname'], $node['api_token']);
                        $pve->stopVM($node['name'], $action['vmid'], 'qemu');
                        sleep(5);
                        $pve->startVM($node['name'], $action['vmid'], 'qemu');

                        EventLogger::info('alert_action_executed', "Restarted VM {$action['vmid']} due to alert", null, [
                            'vmid' => $action['vmid'],
                            'node' => $action['node'],
                        ]);
                    }
                }
                break;

            case 'scale_up':
                // Trigger auto-scaling if implemented
                EventLogger::info('alert_action_executed', 'Scale-up action triggered', null, $action);
                break;

            case 'run_policy':
                if (isset($action['policy_id'])) {
                    // Execute policy
                    $policy = Database::selectOne('SELECT * FROM policies WHERE id = ?', [$action['policy_id']]);
                    if ($policy) {
                        PoliciesEngine::executePolicyById($action['policy_id']);
                    }
                }
                break;

            case 'webhook':
                if (isset($action['url'])) {
                    // Call external webhook
                    $ch = curl_init($action['url']);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($labels));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_exec($ch);
                    curl_close($ch);

                    EventLogger::info('alert_action_executed', "Webhook called: {$action['url']}", null, $action);
                }
                break;
        }
    }

    /**
     * Get active alerts
     */
    public static function getActiveAlerts(?string $severity = null, ?string $instance = null): array
    {
        $where = ['status = ?'];
        $params = ['firing'];

        if ($severity) {
            $where[] = 'severity = ?';
            $params[] = $severity;
        }

        if ($instance) {
            $where[] = 'instance = ?';
            $params[] = $instance;
        }

        $alerts = Database::select(
            'SELECT * FROM alerts WHERE ' . implode(' AND ', $where) . ' ORDER BY fired_at DESC',
            $params
        );

        foreach ($alerts as &$alert) {
            $alert['labels'] = json_decode($alert['labels_json'], true);
            $alert['annotations'] = json_decode($alert['annotations_json'], true);
            unset($alert['labels_json'], $alert['annotations_json']);
        }

        return $alerts;
    }

    /**
     * Get alert history
     */
    public static function getAlertHistory(int $days = 7): array
    {
        $alerts = Database::select(
            'SELECT * FROM alerts
             WHERE fired_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY fired_at DESC',
            [$days]
        );

        foreach ($alerts as &$alert) {
            $alert['labels'] = json_decode($alert['labels_json'], true);
            $alert['annotations'] = json_decode($alert['annotations_json'], true);
            unset($alert['labels_json'], $alert['annotations_json']);
        }

        return $alerts;
    }

    /**
     * Create custom alert rule
     */
    public static function createRule(string $name, string $query, string $severity, int $duration, ?array $actions = null, ?string $description = null): int
    {
        $ruleId = Database::insert(
            'INSERT INTO alert_rules (name, query, severity, for_duration, actions_json, description, enabled)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $name,
                $query,
                $severity,
                $duration,
                $actions ? json_encode($actions) : null,
                $description
            ]
        );

        EventLogger::info('alert_rule_created', "Alert rule created: $name", null, ['rule_id' => $ruleId]);

        return $ruleId;
    }

    /**
     * Update alert rule
     */
    public static function updateRule(int $ruleId, array $updates): void
    {
        $fields = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, ['name', 'query', 'severity', 'for_duration', 'description', 'enabled'])) {
                $fields[] = "$field = ?";
                $params[] = $value;
            } elseif ($field === 'actions' && is_array($value)) {
                $fields[] = "actions_json = ?";
                $params[] = json_encode($value);
            }
        }

        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }

        $params[] = $ruleId;

        Database::update(
            'UPDATE alert_rules SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('alert_rule_updated', "Alert rule updated: ID $ruleId", null, ['rule_id' => $ruleId]);
    }

    /**
     * Delete alert rule
     */
    public static function deleteRule(int $ruleId): void
    {
        Database::update(
            'UPDATE alert_rules SET enabled = 0 WHERE id = ?',
            [$ruleId]
        );

        EventLogger::info('alert_rule_deleted', "Alert rule disabled: ID $ruleId", null, ['rule_id' => $ruleId]);
    }

    /**
     * Get all alert rules
     */
    public static function getRules(?bool $enabledOnly = true): array
    {
        $where = $enabledOnly ? 'WHERE enabled = 1' : '';

        $rules = Database::select(
            "SELECT * FROM alert_rules $where ORDER BY name"
        );

        foreach ($rules as &$rule) {
            if ($rule['actions_json']) {
                $rule['actions'] = json_decode($rule['actions_json'], true);
            }
            unset($rule['actions_json']);
        }

        return $rules;
    }

    /**
     * Acknowledge alert (silence notification)
     */
    public static function acknowledgeAlert(int $alertId, int $userId, ?string $comment = null): void
    {
        Database::update(
            'UPDATE alerts SET acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW(), ack_comment = ?
             WHERE id = ?',
            [$userId, $comment, $alertId]
        );

        EventLogger::info('alert_acknowledged', "Alert acknowledged: ID $alertId", $userId, [
            'alert_id' => $alertId,
            'comment' => $comment,
        ]);
    }

    /**
     * Get monitoring sources
     */
    public static function getMonitoringSources(): array
    {
        $sources = Database::select(
            'SELECT * FROM monitoring_sources WHERE enabled = 1 ORDER BY name'
        );

        foreach ($sources as &$source) {
            if ($source['config_json']) {
                $source['config'] = json_decode($source['config_json'], true);
            }
            unset($source['config_json']);
        }

        return $sources;
    }

    /**
     * Sync monitoring data from Prometheus
     */
    public static function syncFromPrometheus(int $sourceId): array
    {
        $source = Database::selectOne(
            'SELECT * FROM monitoring_sources WHERE id = ? AND type = "prometheus"',
            [$sourceId]
        );

        if (!$source) {
            throw new Exception('Prometheus source not found');
        }

        $config = json_decode($source['config_json'], true);
        $prometheus = new PrometheusClient($config['url'], $config['auth_token'] ?? null);

        // Get current alerts from Prometheus
        $prometheusAlerts = $prometheus->getAlerts();

        $syncedCount = 0;
        foreach ($prometheusAlerts['alerts'] ?? [] as $alert) {
            self::processAlert($alert);
            $syncedCount++;
        }

        Database::update(
            'UPDATE monitoring_sources SET last_sync_at = NOW() WHERE id = ?',
            [$sourceId]
        );

        return [
            'synced_count' => $syncedCount,
            'source' => $source['name'],
        ];
    }
}
