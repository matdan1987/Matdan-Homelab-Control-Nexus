<?php
/**
 * Costs API Endpoint
 *
 * Handles cost calculation, reporting, and forecasting.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/CostCalculator.php';

// Require authentication
Auth::require();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'calculate':
            // Calculate costs for a period
            $startDate = getParam('start_date', true);
            $endDate = getParam('end_date', true);
            $teamId = getParam('team_id', false);
            $serviceId = getParam('service_id', false);

            $start = new DateTime($startDate);
            $end = new DateTime($endDate);

            $costs = CostCalculator::calculateForPeriod($start, $end, $teamId, $serviceId);

            jsonResponse($costs);
            break;

        case 'generate_report':
            // Generate and save cost report
            Auth::require('admin'); // Only admins can generate reports

            $startDate = getParam('start_date', true);
            $endDate = getParam('end_date', true);
            $teamId = getParam('team_id', false);
            $serviceId = getParam('service_id', false);

            $start = new DateTime($startDate);
            $end = new DateTime($endDate);

            $reportId = CostCalculator::generateReport($start, $end, $teamId, $serviceId);

            jsonResponse([
                'report_id' => $reportId,
                'message' => 'Cost report generated successfully'
            ]);
            break;

        case 'reports':
            // List cost reports
            $teamId = getParam('team_id', false);
            $limit = min((int)getParam('limit', false) ?: 50, 100);

            $where = [];
            $params = [];

            if ($teamId) {
                $where[] = 'team_id = ?';
                $params[] = $teamId;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $reports = Database::select(
                "SELECT * FROM cost_reports
                 $whereClause
                 ORDER BY created_at DESC
                 LIMIT ?",
                array_merge($params, [$limit])
            );

            foreach ($reports as &$report) {
                $report['breakdown'] = json_decode($report['breakdown_json'], true);
                unset($report['breakdown_json']);
            }

            jsonResponse($reports);
            break;

        case 'report':
            // Get single report by ID
            $reportId = getParam('id', true);

            $report = Database::selectOne(
                'SELECT * FROM cost_reports WHERE id = ?',
                [$reportId]
            );

            if (!$report) {
                throw new Exception('Report not found');
            }

            $report['breakdown'] = json_decode($report['breakdown_json'], true);
            unset($report['breakdown_json']);

            jsonResponse($report);
            break;

        case 'forecast':
            // Get cost forecast
            $teamId = getParam('team_id', false);

            $forecast = CostCalculator::getForecast($teamId);

            jsonResponse($forecast);
            break;

        case 'snapshot':
            // Capture resource usage snapshot (admin only)
            Auth::require('admin');

            $count = CostCalculator::captureSnapshot();

            jsonResponse([
                'snapshots_created' => $count,
                'message' => "Captured $count resource snapshots"
            ]);
            break;

        case 'models':
            // List cost models
            $models = Database::select(
                'SELECT * FROM cost_models ORDER BY effective_from DESC'
            );

            jsonResponse($models);
            break;

        case 'model':
            // Get single cost model
            $modelId = getParam('id', true);

            $model = Database::selectOne(
                'SELECT * FROM cost_models WHERE id = ?',
                [$modelId]
            );

            if (!$model) {
                throw new Exception('Cost model not found');
            }

            jsonResponse($model);
            break;

        case 'create_model':
            // Create new cost model (admin only)
            Auth::require('admin');

            $name = getParam('name', true);
            $cpuCoreHourly = getParam('cpu_core_hourly', true);
            $memoryGbHourly = getParam('memory_gb_hourly', true);
            $storageGbMonthly = getParam('storage_gb_monthly', true);
            $effectiveFrom = getParam('effective_from', true);
            $active = getParam('active', false) ?: 0;

            $modelId = Database::insert(
                'INSERT INTO cost_models (name, cpu_core_hourly, memory_gb_hourly, storage_gb_monthly, effective_from, active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $cpuCoreHourly, $memoryGbHourly, $storageGbMonthly, $effectiveFrom, $active]
            );

            EventLogger::info('cost_model_created', "Cost model created: $name", null, ['model_id' => $modelId]);

            jsonResponse([
                'id' => $modelId,
                'message' => 'Cost model created successfully'
            ]);
            break;

        case 'update_model':
            // Update cost model (admin only)
            Auth::require('admin');

            $modelId = getParam('id', true);
            $name = getParam('name', false);
            $cpuCoreHourly = getParam('cpu_core_hourly', false);
            $memoryGbHourly = getParam('memory_gb_hourly', false);
            $storageGbMonthly = getParam('storage_gb_monthly', false);
            $active = getParam('active', false);

            $updates = [];
            $params = [];

            if ($name !== null) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            if ($cpuCoreHourly !== null) {
                $updates[] = 'cpu_core_hourly = ?';
                $params[] = $cpuCoreHourly;
            }
            if ($memoryGbHourly !== null) {
                $updates[] = 'memory_gb_hourly = ?';
                $params[] = $memoryGbHourly;
            }
            if ($storageGbMonthly !== null) {
                $updates[] = 'storage_gb_monthly = ?';
                $params[] = $storageGbMonthly;
            }
            if ($active !== null) {
                $updates[] = 'active = ?';
                $params[] = $active;
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            $params[] = $modelId;

            Database::update(
                'UPDATE cost_models SET ' . implode(', ', $updates) . ' WHERE id = ?',
                $params
            );

            EventLogger::info('cost_model_updated', "Cost model updated: ID $modelId", null, ['model_id' => $modelId]);

            jsonResponse(['message' => 'Cost model updated successfully']);
            break;

        case 'usage_by_team':
            // Get resource usage breakdown by team
            $startDate = getParam('start_date', true);
            $endDate = getParam('end_date', true);

            $teams = Database::select('SELECT id, name FROM teams WHERE active = 1');

            $teamUsage = [];
            foreach ($teams as $team) {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);

                $costs = CostCalculator::calculateForPeriod($start, $end, $team['id']);

                $teamUsage[] = [
                    'team_id' => $team['id'],
                    'team_name' => $team['name'],
                    'total_cost' => $costs['total_cost'],
                    'breakdown' => $costs['breakdown']['by_resource_type'],
                ];
            }

            jsonResponse($teamUsage);
            break;

        case 'usage_by_service':
            // Get resource usage breakdown by service
            $startDate = getParam('start_date', true);
            $endDate = getParam('end_date', true);

            $services = Database::select('SELECT id, name FROM services WHERE active = 1');

            $serviceUsage = [];
            foreach ($services as $service) {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);

                $costs = CostCalculator::calculateForPeriod($start, $end, null, $service['id']);

                $serviceUsage[] = [
                    'service_id' => $service['id'],
                    'service_name' => $service['name'],
                    'total_cost' => $costs['total_cost'],
                    'breakdown' => $costs['breakdown']['by_resource_type'],
                ];
            }

            jsonResponse($serviceUsage);
            break;

        case 'trend':
            // Get cost trend over time
            $days = min((int)getParam('days', false) ?: 30, 365);
            $teamId = getParam('team_id', false);
            $serviceId = getParam('service_id', false);

            $trend = [];
            $end = new DateTime();

            for ($i = $days; $i >= 0; $i--) {
                $date = (clone $end)->modify("-$i days");
                $start = (clone $date)->setTime(0, 0);
                $dayEnd = (clone $date)->setTime(23, 59, 59);

                $costs = CostCalculator::calculateForPeriod($start, $dayEnd, $teamId, $serviceId);

                $trend[] = [
                    'date' => $date->format('Y-m-d'),
                    'total_cost' => $costs['total_cost'],
                    'cpu_cost' => $costs['breakdown']['by_resource_type']['cpu'],
                    'memory_cost' => $costs['breakdown']['by_resource_type']['memory'],
                    'storage_cost' => $costs['breakdown']['by_resource_type']['storage'],
                ];
            }

            jsonResponse($trend);
            break;

        default:
            // Default: Get current month costs summary
            $start = new DateTime('first day of this month 00:00:00');
            $end = new DateTime('last day of this month 23:59:59');

            $costs = CostCalculator::calculateForPeriod($start, $end);

            jsonResponse($costs);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
