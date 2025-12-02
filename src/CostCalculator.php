<?php
/**
 * CostCalculator
 *
 * Complete cost tracking and calculation system.
 * Calculates costs based on resource usage and cost models.
 */

class CostCalculator
{
    /**
     * Calculate costs for a specific period
     *
     * @param DateTime $start Period start
     * @param DateTime $end Period end
     * @param int|null $teamId Optional team filter
     * @param int|null $serviceId Optional service filter
     * @return array Cost breakdown
     */
    public static function calculateForPeriod(DateTime $start, DateTime $end, ?int $teamId = null, ?int $serviceId = null): array
    {
        // Get active cost model
        $costModel = self::getActiveCostModel($start);

        if (!$costModel) {
            throw new Exception('No active cost model found for the specified period');
        }

        // Build query conditions
        $where = ['snapshot_at BETWEEN ? AND ?'];
        $params = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        if ($teamId) {
            $where[] = 'EXISTS (SELECT 1 FROM team_resources tr WHERE tr.pve_node_id = resource_usage_snapshots.pve_node_id AND tr.vmid = resource_usage_snapshots.vmid AND tr.team_id = ?)';
            $params[] = $teamId;
        }

        if ($serviceId) {
            $where[] = 'EXISTS (SELECT 1 FROM service_instances si WHERE si.pve_node_id = resource_usage_snapshots.pve_node_id AND si.vmid = resource_usage_snapshots.vmid AND si.service_id = ?)';
            $params[] = $serviceId;
        }

        $whereClause = implode(' AND ', $where);

        // Get snapshots
        $snapshots = Database::select(
            "SELECT * FROM resource_usage_snapshots WHERE $whereClause ORDER BY snapshot_at ASC",
            $params
        );

        // Calculate costs
        $totalCost = 0;
        $breakdown = [
            'by_vm' => [],
            'by_resource_type' => [
                'cpu' => 0,
                'memory' => 0,
                'storage' => 0,
            ],
            'by_date' => [],
        ];

        $previousSnapshot = [];

        foreach ($snapshots as $snapshot) {
            $vmKey = "{$snapshot['pve_node_id']}_{$snapshot['vmid']}";

            // Calculate hours since last snapshot (default 1 hour)
            $hours = 1;
            if (isset($previousSnapshot[$vmKey])) {
                $prevTime = strtotime($previousSnapshot[$vmKey]['snapshot_at']);
                $currTime = strtotime($snapshot['snapshot_at']);
                $hours = max(($currTime - $prevTime) / 3600, 0.1); // At least 6 minutes
            }

            // Only count running VMs
            if ($snapshot['status'] === 'running') {
                $cpuCost = $snapshot['cpu_cores'] * $costModel['cpu_core_hourly'] * $hours;
                $memCost = $snapshot['memory_gb'] * $costModel['memory_gb_hourly'] * $hours;

                $vmCost = $cpuCost + $memCost;
                $totalCost += $vmCost;

                // Breakdown by VM
                if (!isset($breakdown['by_vm'][$vmKey])) {
                    $breakdown['by_vm'][$vmKey] = [
                        'pve_node_id' => $snapshot['pve_node_id'],
                        'vmid' => $snapshot['vmid'],
                        'total_cost' => 0,
                        'cpu_cost' => 0,
                        'memory_cost' => 0,
                        'hours_running' => 0,
                    ];
                }

                $breakdown['by_vm'][$vmKey]['total_cost'] += $vmCost;
                $breakdown['by_vm'][$vmKey]['cpu_cost'] += $cpuCost;
                $breakdown['by_vm'][$vmKey]['memory_cost'] += $memCost;
                $breakdown['by_vm'][$vmKey]['hours_running'] += $hours;

                // Breakdown by resource type
                $breakdown['by_resource_type']['cpu'] += $cpuCost;
                $breakdown['by_resource_type']['memory'] += $memCost;

                // Breakdown by date
                $date = date('Y-m-d', strtotime($snapshot['snapshot_at']));
                if (!isset($breakdown['by_date'][$date])) {
                    $breakdown['by_date'][$date] = 0;
                }
                $breakdown['by_date'][$date] += $vmCost;
            }

            $previousSnapshot[$vmKey] = $snapshot;
        }

        // Storage costs (monthly, not hourly)
        $storageCost = self::calculateStorageCosts($start, $end, $costModel, $teamId, $serviceId);
        $totalCost += $storageCost;
        $breakdown['by_resource_type']['storage'] = $storageCost;

        // Round all values
        $totalCost = round($totalCost, 2);
        foreach ($breakdown['by_vm'] as &$vm) {
            $vm['total_cost'] = round($vm['total_cost'], 2);
            $vm['cpu_cost'] = round($vm['cpu_cost'], 2);
            $vm['memory_cost'] = round($vm['memory_cost'], 2);
            $vm['hours_running'] = round($vm['hours_running'], 1);
        }

        foreach ($breakdown['by_resource_type'] as $type => &$cost) {
            $cost = round($cost, 2);
        }

        foreach ($breakdown['by_date'] as $date => &$cost) {
            $cost = round($cost, 2);
        }

        return [
            'total_cost' => $totalCost,
            'currency' => 'USD',
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'cost_model' => [
                'id' => $costModel['id'],
                'name' => $costModel['name'],
            ],
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Calculate storage costs for period
     */
    private static function calculateStorageCosts(DateTime $start, DateTime $end, array $costModel, ?int $teamId, ?int $serviceId): float
    {
        // Get average storage usage for the period
        $where = ['snapshot_at BETWEEN ? AND ?'];
        $params = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        if ($teamId) {
            $where[] = 'EXISTS (SELECT 1 FROM team_resources tr WHERE tr.pve_node_id = resource_usage_snapshots.pve_node_id AND tr.vmid = resource_usage_snapshots.vmid AND tr.team_id = ?)';
            $params[] = $teamId;
        }

        if ($serviceId) {
            $where[] = 'EXISTS (SELECT 1 FROM service_instances si WHERE si.pve_node_id = resource_usage_snapshots.pve_node_id AND si.vmid = resource_usage_snapshots.vmid AND si.service_id = ?)';
            $params[] = $serviceId;
        }

        $whereClause = implode(' AND ', $where);

        $result = Database::selectOne(
            "SELECT AVG(storage_gb) as avg_storage_gb FROM resource_usage_snapshots WHERE $whereClause",
            $params
        );

        $avgStorageGb = $result['avg_storage_gb'] ?? 0;

        // Calculate days in period
        $days = max(1, $start->diff($end)->days);
        $monthFraction = $days / 30;

        return $avgStorageGb * $costModel['storage_gb_monthly'] * $monthFraction;
    }

    /**
     * Get active cost model for date
     */
    private static function getActiveCostModel(DateTime $date): ?array
    {
        return Database::selectOne(
            'SELECT * FROM cost_models
             WHERE active = 1 AND effective_from <= ?
             ORDER BY effective_from DESC
             LIMIT 1',
            [$date->format('Y-m-d')]
        );
    }

    /**
     * Generate and save cost report
     *
     * @param DateTime $start Period start
     * @param DateTime $end Period end
     * @param int|null $teamId Optional team filter
     * @param int|null $serviceId Optional service filter
     * @return int Report ID
     */
    public static function generateReport(DateTime $start, DateTime $end, ?int $teamId = null, ?int $serviceId = null): int
    {
        $costs = self::calculateForPeriod($start, $end, $teamId, $serviceId);

        $reportId = Database::insert(
            'INSERT INTO cost_reports (period_start, period_end, team_id, service_id, total_cost, breakdown_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $teamId,
                $serviceId,
                $costs['total_cost'],
                json_encode($costs['breakdown']),
            ]
        );

        EventLogger::info('cost_report', "Generated cost report for period {$start->format('Y-m-d')} to {$end->format('Y-m-d')}", null, [
            'report_id' => $reportId,
            'total_cost' => $costs['total_cost'],
        ]);

        return $reportId;
    }

    /**
     * Capture current resource usage snapshot
     *
     * @return int Number of snapshots created
     */
    public static function captureSnapshot(): int
    {
        $resources = Database::select('SELECT * FROM pve_resources_cache');

        $count = 0;
        foreach ($resources as $resource) {
            Database::insert(
                'INSERT INTO resource_usage_snapshots (pve_node_id, vmid, cpu_cores, memory_gb, storage_gb, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $resource['pve_node_id'],
                    $resource['vmid'],
                    $resource['maxcpu'] ?? 0,
                    ($resource['maxmem'] ?? 0) / 1073741824, // Convert to GB
                    ($resource['maxdisk'] ?? 0) / 1073741824, // Convert to GB
                    $resource['status'] ?? 'unknown',
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Get cost forecast for next month
     *
     * @param int|null $teamId Optional team filter
     * @return array Forecast data
     */
    public static function getForecast(?int $teamId = null): array
    {
        // Calculate costs for last 30 days
        $end = new DateTime();
        $start = (clone $end)->modify('-30 days');

        $historicalCosts = self::calculateForPeriod($start, $end, $teamId);

        // Simple forecast: average daily cost * 30
        $avgDailyCost = $historicalCosts['total_cost'] / 30;
        $forecastedCost = $avgDailyCost * 30;

        return [
            'forecasted_monthly_cost' => round($forecastedCost, 2),
            'based_on_period' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'actual_cost' => $historicalCosts['total_cost'],
            ],
            'average_daily_cost' => round($avgDailyCost, 2),
        ];
    }
}
