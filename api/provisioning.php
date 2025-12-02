<?php
/**
 * Provisioning API Endpoint
 *
 * Handles VM provisioning, deprovisioning, and job management.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/ProvisioningEngine.php';

// Require authentication
Auth::require();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'provision_from_template':
            // Provision VM from template
            Auth::require('operator');

            $templateId = getParam('template_id', true);
            $config = getParam('config', true);
            $teamId = getParam('team_id', false);
            $serviceId = getParam('service_id', false);

            // Decode config if it's a string
            if (is_string($config)) {
                $config = json_decode($config, true);
            }

            $jobId = ProvisioningEngine::provisionFromTemplate($templateId, $config, $teamId, $serviceId);

            jsonResponse([
                'job_id' => $jobId,
                'message' => 'Provisioning job created successfully'
            ]);
            break;

        case 'provision_custom':
            // Provision VM with custom configuration
            Auth::require('operator');

            $pveNodeId = getParam('pve_node_id', true);
            $config = getParam('config', true);
            $teamId = getParam('team_id', false);
            $serviceId = getParam('service_id', false);

            // Decode config if it's a string
            if (is_string($config)) {
                $config = json_decode($config, true);
            }

            $jobId = ProvisioningEngine::provisionCustom($config, $pveNodeId, $teamId, $serviceId);

            jsonResponse([
                'job_id' => $jobId,
                'message' => 'Provisioning job created successfully'
            ]);
            break;

        case 'deprovision':
            // Deprovision (delete) a VM
            Auth::require('operator');

            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);
            $deleteStorage = getParam('delete_storage', false) ?? true;

            ProvisioningEngine::deprovision($pveNodeId, $vmid, $deleteStorage);

            jsonResponse([
                'message' => 'VM deprovisioned successfully'
            ]);
            break;

        case 'job_status':
            // Get provisioning job status
            $jobId = getParam('id', true);

            $job = ProvisioningEngine::getJobStatus($jobId);

            jsonResponse($job);
            break;

        case 'jobs':
            // List provisioning jobs
            $teamId = getParam('team_id', false);
            $status = getParam('status', false);
            $limit = min((int)getParam('limit', false) ?: 50, 100);

            $where = [];
            $params = [];

            if ($teamId) {
                $where[] = 'team_id = ?';
                $params[] = $teamId;
            }

            if ($status) {
                $where[] = 'status = ?';
                $params[] = $status;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $jobs = Database::select(
                "SELECT pj.*, t.name as template_name, pn.name as node_name, tm.name as team_name
                 FROM provisioning_jobs pj
                 LEFT JOIN vm_templates t ON t.id = pj.template_id
                 LEFT JOIN pve_nodes pn ON pn.id = pj.pve_node_id
                 LEFT JOIN teams tm ON tm.id = pj.team_id
                 $whereClause
                 ORDER BY created_at DESC
                 LIMIT ?",
                array_merge($params, [$limit])
            );

            foreach ($jobs as &$job) {
                $job['config'] = json_decode($job['config_json'], true);
                $job['result'] = $job['result_json'] ? json_decode($job['result_json'], true) : null;
                unset($job['config_json'], $job['result_json']);
            }

            jsonResponse($jobs);
            break;

        case 'templates':
            // List VM templates
            $templates = Database::select(
                'SELECT * FROM vm_templates WHERE active = 1 ORDER BY name'
            );

            foreach ($templates as &$template) {
                $template['config'] = json_decode($template['config_json'], true);
                unset($template['config_json']);
            }

            jsonResponse($templates);
            break;

        case 'template':
            // Get single template
            $templateId = getParam('id', true);

            $template = Database::selectOne(
                'SELECT * FROM vm_templates WHERE id = ?',
                [$templateId]
            );

            if (!$template) {
                throw new Exception('Template not found');
            }

            $template['config'] = json_decode($template['config_json'], true);
            unset($template['config_json']);

            jsonResponse($template);
            break;

        case 'create_template':
            // Create VM template (admin only)
            Auth::require('admin');

            $name = getParam('name', true);
            $description = getParam('description', false);
            $category = getParam('category', false);
            $config = getParam('config', true);

            // Decode config if it's a string
            if (is_string($config)) {
                $config = json_decode($config, true);
            }

            $templateId = Database::insert(
                'INSERT INTO vm_templates (name, description, category, config_json, active)
                 VALUES (?, ?, ?, ?, 1)',
                [$name, $description, $category, json_encode($config)]
            );

            EventLogger::info('vm_template_created', "VM template created: $name", null, ['template_id' => $templateId]);

            jsonResponse([
                'id' => $templateId,
                'message' => 'Template created successfully'
            ]);
            break;

        case 'update_template':
            // Update VM template (admin only)
            Auth::require('admin');

            $templateId = getParam('id', true);
            $name = getParam('name', false);
            $description = getParam('description', false);
            $category = getParam('category', false);
            $config = getParam('config', false);
            $active = getParam('active', false);

            $updates = [];
            $params = [];

            if ($name !== null) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            if ($description !== null) {
                $updates[] = 'description = ?';
                $params[] = $description;
            }
            if ($category !== null) {
                $updates[] = 'category = ?';
                $params[] = $category;
            }
            if ($config !== null) {
                if (is_string($config)) {
                    $config = json_decode($config, true);
                }
                $updates[] = 'config_json = ?';
                $params[] = json_encode($config);
            }
            if ($active !== null) {
                $updates[] = 'active = ?';
                $params[] = $active;
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            $params[] = $templateId;

            Database::update(
                'UPDATE vm_templates SET ' . implode(', ', $updates) . ' WHERE id = ?',
                $params
            );

            EventLogger::info('vm_template_updated', "VM template updated: ID $templateId", null, ['template_id' => $templateId]);

            jsonResponse(['message' => 'Template updated successfully']);
            break;

        case 'delete_template':
            // Delete VM template (admin only)
            Auth::require('admin');

            $templateId = getParam('id', true);

            Database::update(
                'UPDATE vm_templates SET active = 0 WHERE id = ?',
                [$templateId]
            );

            EventLogger::info('vm_template_deleted', "VM template deactivated: ID $templateId", null, ['template_id' => $templateId]);

            jsonResponse(['message' => 'Template deleted successfully']);
            break;

        case 'cancel_job':
            // Cancel pending provisioning job
            Auth::require('operator');

            $jobId = getParam('id', true);

            // Only allow canceling pending jobs
            $job = Database::selectOne(
                'SELECT * FROM provisioning_jobs WHERE id = ?',
                [$jobId]
            );

            if (!$job) {
                throw new Exception('Job not found');
            }

            if ($job['status'] !== 'pending') {
                throw new Exception('Can only cancel pending jobs');
            }

            Database::update(
                'UPDATE provisioning_jobs SET status = ?, error_message = ? WHERE id = ?',
                ['cancelled', 'Cancelled by user', $jobId]
            );

            EventLogger::info('provisioning_job_cancelled', "Provisioning job cancelled: ID $jobId", null, ['job_id' => $jobId]);

            jsonResponse(['message' => 'Job cancelled successfully']);
            break;

        case 'retry_job':
            // Retry failed provisioning job
            Auth::require('operator');

            $jobId = getParam('id', true);

            $job = Database::selectOne(
                'SELECT * FROM provisioning_jobs WHERE id = ?',
                [$jobId]
            );

            if (!$job) {
                throw new Exception('Job not found');
            }

            if ($job['status'] !== 'failed') {
                throw new Exception('Can only retry failed jobs');
            }

            Database::update(
                'UPDATE provisioning_jobs SET status = ?, error_message = NULL, started_at = NULL, completed_at = NULL
                 WHERE id = ?',
                ['pending', $jobId]
            );

            // Re-execute provisioning
            $reflection = new ReflectionClass('ProvisioningEngine');
            $method = $reflection->getMethod('executeProvisioning');
            $method->setAccessible(true);
            $method->invoke(null, $jobId);

            EventLogger::info('provisioning_job_retried', "Provisioning job retried: ID $jobId", null, ['job_id' => $jobId]);

            jsonResponse(['message' => 'Job retry initiated']);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
