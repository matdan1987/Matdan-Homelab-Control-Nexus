<?php
/**
 * Monitoring API Endpoint
 *
 * Handles monitoring integration, alerts, and metrics.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/Integrations/PrometheusClient.php';
require_once __DIR__ . '/../src/AlertManager.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'webhook':
            // Webhook endpoint for Prometheus Alertmanager (no auth required)
            $payload = json_decode(file_get_contents('php://input'), true);

            if (!$payload) {
                throw new Exception('Invalid webhook payload');
            }

            AlertManager::processWebhook($payload);

            jsonResponse(['message' => 'Webhook processed successfully']);
            break;

        case 'active_alerts':
            // Get active alerts
            Auth::require();

            $severity = getParam('severity', false);
            $instance = getParam('instance', false);

            $alerts = AlertManager::getActiveAlerts($severity, $instance);

            jsonResponse($alerts);
            break;

        case 'alert_history':
            // Get alert history
            Auth::require();

            $days = min((int)getParam('days', false) ?: 7, 90);

            $alerts = AlertManager::getAlertHistory($days);

            jsonResponse($alerts);
            break;

        case 'acknowledge_alert':
            // Acknowledge an alert
            Auth::require('operator');

            $alertId = getParam('id', true);
            $comment = getParam('comment', false);
            $userId = $_SESSION['user_id'];

            AlertManager::acknowledgeAlert($alertId, $userId, $comment);

            jsonResponse(['message' => 'Alert acknowledged successfully']);
            break;

        case 'alert_rules':
            // List alert rules
            Auth::require();

            $enabledOnly = getParam('enabled_only', false) !== '0';

            $rules = AlertManager::getRules($enabledOnly);

            jsonResponse($rules);
            break;

        case 'create_rule':
            // Create alert rule
            Auth::require('admin');

            $name = getParam('name', true);
            $query = getParam('query', true);
            $severity = getParam('severity', true);
            $duration = getParam('duration', true);
            $description = getParam('description', false);
            $actions = getParam('actions', false);

            if ($actions && is_string($actions)) {
                $actions = json_decode($actions, true);
            }

            $ruleId = AlertManager::createRule($name, $query, $severity, $duration, $actions, $description);

            jsonResponse([
                'id' => $ruleId,
                'message' => 'Alert rule created successfully'
            ]);
            break;

        case 'update_rule':
            // Update alert rule
            Auth::require('admin');

            $ruleId = getParam('id', true);

            $updates = [];
            $fields = ['name', 'query', 'severity', 'for_duration', 'description', 'enabled', 'actions'];

            foreach ($fields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    $updates[$field] = $value;
                }
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            AlertManager::updateRule($ruleId, $updates);

            jsonResponse(['message' => 'Alert rule updated successfully']);
            break;

        case 'delete_rule':
            // Delete alert rule
            Auth::require('admin');

            $ruleId = getParam('id', true);

            AlertManager::deleteRule($ruleId);

            jsonResponse(['message' => 'Alert rule deleted successfully']);
            break;

        case 'prometheus_query':
            // Execute Prometheus query
            Auth::require();

            $sourceId = getParam('source_id', true);
            $query = getParam('query', true);
            $timestamp = getParam('timestamp', false);

            $source = Database::selectOne(
                'SELECT * FROM monitoring_sources WHERE id = ? AND type = "prometheus"',
                [$sourceId]
            );

            if (!$source) {
                throw new Exception('Prometheus source not found');
            }

            $config = json_decode($source['config_json'], true);
            $prometheus = new PrometheusClient($config['url'], $config['auth_token'] ?? null);

            $result = $prometheus->query($query, $timestamp);

            jsonResponse($result);
            break;

        case 'prometheus_query_range':
            // Execute Prometheus range query
            Auth::require();

            $sourceId = getParam('source_id', true);
            $query = getParam('query', true);
            $start = getParam('start', true);
            $end = getParam('end', true);
            $step = getParam('step', false) ?: '15s';

            $source = Database::selectOne(
                'SELECT * FROM monitoring_sources WHERE id = ? AND type = "prometheus"',
                [$sourceId]
            );

            if (!$source) {
                throw new Exception('Prometheus source not found');
            }

            $config = json_decode($source['config_json'], true);
            $prometheus = new PrometheusClient($config['url'], $config['auth_token'] ?? null);

            $result = $prometheus->queryRange($query, $start, $end, $step);

            jsonResponse($result);
            break;

        case 'system_metrics':
            // Get system metrics for instance
            Auth::require();

            $sourceId = getParam('source_id', true);
            $instance = getParam('instance', true);

            $source = Database::selectOne(
                'SELECT * FROM monitoring_sources WHERE id = ? AND type = "prometheus"',
                [$sourceId]
            );

            if (!$source) {
                throw new Exception('Prometheus source not found');
            }

            $config = json_decode($source['config_json'], true);
            $prometheus = new PrometheusClient($config['url'], $config['auth_token'] ?? null);

            $metrics = $prometheus->getSystemMetrics($instance);

            jsonResponse($metrics);
            break;

        case 'monitoring_sources':
            // List monitoring sources
            Auth::require();

            $sources = AlertManager::getMonitoringSources();

            jsonResponse($sources);
            break;

        case 'create_source':
            // Create monitoring source
            Auth::require('admin');

            $name = getParam('name', true);
            $type = getParam('type', true);
            $config = getParam('config', true);

            if (is_string($config)) {
                $config = json_decode($config, true);
            }

            $sourceId = Database::insert(
                'INSERT INTO monitoring_sources (name, type, config_json, enabled)
                 VALUES (?, ?, ?, 1)',
                [$name, $type, json_encode($config)]
            );

            EventLogger::info('monitoring_source_created', "Monitoring source created: $name", null, ['source_id' => $sourceId]);

            jsonResponse([
                'id' => $sourceId,
                'message' => 'Monitoring source created successfully'
            ]);
            break;

        case 'update_source':
            // Update monitoring source
            Auth::require('admin');

            $sourceId = getParam('id', true);
            $name = getParam('name', false);
            $config = getParam('config', false);
            $enabled = getParam('enabled', false);

            $updates = [];
            $params = [];

            if ($name !== null) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            if ($config !== null) {
                if (is_string($config)) {
                    $config = json_decode($config, true);
                }
                $updates[] = 'config_json = ?';
                $params[] = json_encode($config);
            }
            if ($enabled !== null) {
                $updates[] = 'enabled = ?';
                $params[] = $enabled;
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            $params[] = $sourceId;

            Database::update(
                'UPDATE monitoring_sources SET ' . implode(', ', $updates) . ' WHERE id = ?',
                $params
            );

            EventLogger::info('monitoring_source_updated', "Monitoring source updated: ID $sourceId", null, ['source_id' => $sourceId]);

            jsonResponse(['message' => 'Monitoring source updated successfully']);
            break;

        case 'sync_prometheus':
            // Sync alerts from Prometheus
            Auth::require('operator');

            $sourceId = getParam('source_id', true);

            $result = AlertManager::syncFromPrometheus($sourceId);

            jsonResponse($result);
            break;

        case 'alert_stats':
            // Get alert statistics
            Auth::require();

            $days = min((int)getParam('days', false) ?: 7, 90);

            $stats = Database::selectOne(
                'SELECT
                    COUNT(*) as total_alerts,
                    SUM(CASE WHEN status = "firing" THEN 1 ELSE 0 END) as active_alerts,
                    SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) as resolved_alerts,
                    SUM(CASE WHEN severity = "critical" AND status = "firing" THEN 1 ELSE 0 END) as critical_alerts,
                    SUM(CASE WHEN acknowledged = 1 THEN 1 ELSE 0 END) as acknowledged_alerts
                 FROM alerts
                 WHERE fired_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );

            $byDay = Database::select(
                'SELECT
                    DATE(fired_at) as date,
                    COUNT(*) as count,
                    SUM(CASE WHEN severity = "critical" THEN 1 ELSE 0 END) as critical_count
                 FROM alerts
                 WHERE fired_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(fired_at)
                 ORDER BY date DESC',
                [$days]
            );

            jsonResponse([
                'summary' => $stats,
                'by_day' => $byDay,
            ]);
            break;

        default:
            // Default: Get monitoring overview
            Auth::require();

            $activeAlerts = AlertManager::getActiveAlerts();
            $sources = AlertManager::getMonitoringSources();

            jsonResponse([
                'active_alerts_count' => count($activeAlerts),
                'critical_alerts_count' => count(array_filter($activeAlerts, fn($a) => $a['severity'] === 'critical')),
                'monitoring_sources' => $sources,
                'recent_alerts' => array_slice($activeAlerts, 0, 10),
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
