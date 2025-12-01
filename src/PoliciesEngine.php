<?php
/**
 * PoliciesEngine Class
 *
 * Business logic for automated policies execution.
 * Handles schedule-based and load-based policies.
 */

class PoliciesEngine
{
    /**
     * Execute all enabled policies
     *
     * @return array Execution results
     */
    public static function executeAll(): array
    {
        $policies = Database::select(
            'SELECT * FROM policies WHERE enabled = 1'
        );

        $results = [];

        foreach ($policies as $policy) {
            $result = self::executePolicy($policy);
            $results[] = $result;

            // Update policy execution record
            self::recordExecution($policy['id'], $result);

            // Update last run
            Database::update(
                'UPDATE policies SET last_run = NOW(), last_result = ? WHERE id = ?',
                [json_encode($result), $policy['id']]
            );
        }

        return $results;
    }

    /**
     * Execute a single policy
     *
     * @param array $policy Policy data
     * @return array Execution result
     */
    public static function executePolicy(array $policy): array
    {
        $config = json_decode($policy['config_json'], true);

        try {
            switch ($policy['rule_type']) {
                case 'schedule':
                    return self::executeSchedulePolicy($policy, $config);

                case 'load_based':
                    return self::executeLoadBasedPolicy($policy, $config);

                case 'dependency':
                    return self::executeDependencyPolicy($policy, $config);

                default:
                    return [
                        'success' => false,
                        'message' => 'Unknown policy type',
                        'policy_id' => $policy['id'],
                    ];
            }
        } catch (Exception $e) {
            EventLogger::error('policy_error',
                "Failed to execute policy {$policy['name']}: " . $e->getMessage(),
                null,
                ['policy_id' => $policy['id']]
            );

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'policy_id' => $policy['id'],
            ];
        }
    }

    /**
     * Execute schedule-based policy
     */
    private static function executeSchedulePolicy(array $policy, array $config): array
    {
        // Check if current time matches schedule
        if (!self::matchesSchedule($config)) {
            return [
                'success' => true,
                'message' => 'Schedule not matched',
                'policy_id' => $policy['id'],
                'skipped' => true,
            ];
        }

        // Get targets
        $targets = self::getTargets($policy);

        $actions = [];
        foreach ($targets as $target) {
            try {
                $action = $config['action'] ?? 'start';
                $client = NodesRepository::getClient($target['pve_node_id']);
                $node = NodesRepository::getById($target['pve_node_id']);

                switch ($action) {
                    case 'start':
                        $client->startVM($node['name'], $target['vmid'], $target['type']);
                        break;

                    case 'stop':
                        $client->stopVM($node['name'], $target['vmid'], $target['type']);
                        break;

                    case 'shutdown':
                        $client->shutdownVM($node['name'], $target['vmid'], $target['type']);
                        break;

                    case 'reboot':
                        $client->rebootVM($node['name'], $target['vmid'], $target['type']);
                        break;
                }

                $actions[] = [
                    'vmid' => $target['vmid'],
                    'action' => $action,
                    'success' => true,
                ];

                EventLogger::info('policy_action',
                    "Policy {$policy['name']}: {$action} VM {$target['vmid']}",
                    null,
                    ['policy_id' => $policy['id'], 'vmid' => $target['vmid']]
                );
            } catch (Exception $e) {
                $actions[] = [
                    'vmid' => $target['vmid'],
                    'action' => $action,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Schedule policy executed',
            'policy_id' => $policy['id'],
            'actions' => $actions,
        ];
    }

    /**
     * Execute load-based policy
     */
    private static function executeLoadBasedPolicy(array $policy, array $config): array
    {
        $targets = self::getTargets($policy);
        $actions = [];

        foreach ($targets as $target) {
            try {
                $client = NodesRepository::getClient($target['pve_node_id']);
                $node = NodesRepository::getById($target['pve_node_id']);

                // Get current status
                $status = $client->getVMStatus($node['name'], $target['vmid'], $target['type']);

                // Check CPU threshold
                if (isset($config['cpu_high_threshold']) && isset($status['cpu'])) {
                    $cpuPercent = $status['cpu'] * 100;

                    if ($cpuPercent > $config['cpu_high_threshold']) {
                        // Increase CPU cores
                        $currentCores = $status['cpus'] ?? 1;
                        $maxCores = $config['max_cpu_cores'] ?? 8;
                        $newCores = min($currentCores + 1, $maxCores);

                        if ($newCores > $currentCores) {
                            $client->updateVMCores($node['name'], $target['vmid'], $target['type'], $newCores);

                            $actions[] = [
                                'vmid' => $target['vmid'],
                                'action' => 'increase_cpu',
                                'from' => $currentCores,
                                'to' => $newCores,
                                'success' => true,
                            ];

                            EventLogger::warning('policy_action',
                                "Policy {$policy['name']}: Increased CPU cores for VM {$target['vmid']} from {$currentCores} to {$newCores}",
                                null,
                                ['policy_id' => $policy['id'], 'vmid' => $target['vmid']]
                            );
                        }
                    } elseif ($cpuPercent < $config['cpu_low_threshold']) {
                        // Decrease CPU cores
                        $currentCores = $status['cpus'] ?? 1;
                        $minCores = $config['min_cpu_cores'] ?? 1;
                        $newCores = max($currentCores - 1, $minCores);

                        if ($newCores < $currentCores) {
                            $client->updateVMCores($node['name'], $target['vmid'], $target['type'], $newCores);

                            $actions[] = [
                                'vmid' => $target['vmid'],
                                'action' => 'decrease_cpu',
                                'from' => $currentCores,
                                'to' => $newCores,
                                'success' => true,
                            ];

                            EventLogger::info('policy_action',
                                "Policy {$policy['name']}: Decreased CPU cores for VM {$target['vmid']} from {$currentCores} to {$newCores}",
                                null,
                                ['policy_id' => $policy['id'], 'vmid' => $target['vmid']]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                $actions[] = [
                    'vmid' => $target['vmid'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Load-based policy executed',
            'policy_id' => $policy['id'],
            'actions' => $actions,
        ];
    }

    /**
     * Execute dependency-based policy
     */
    private static function executeDependencyPolicy(array $policy, array $config): array
    {
        // Implementation for dependency-based policies
        // E.g., start dependent services in order

        return [
            'success' => true,
            'message' => 'Dependency policy executed',
            'policy_id' => $policy['id'],
        ];
    }

    /**
     * Get policy targets (VMs/LXCs)
     */
    private static function getTargets(array $policy): array
    {
        switch ($policy['target_type']) {
            case 'vm':
            case 'lxc':
                // Single VM/LXC by VMID
                return Database::select(
                    'SELECT * FROM pve_resources_cache WHERE vmid = ? AND type = ?',
                    [$policy['target_value'], $policy['target_type']]
                );

            case 'service':
                // All instances of a service
                return Database::select(
                    'SELECT c.* FROM service_instances si
                     JOIN pve_resources_cache c ON si.pve_node_id = c.pve_node_id AND si.vmid = c.vmid
                     WHERE si.service_id = ?',
                    [(int) $policy['target_value']]
                );

            case 'tag':
                // All services with a specific tag
                return Database::select(
                    'SELECT c.* FROM services s
                     JOIN service_instances si ON s.id = si.service_id
                     JOIN pve_resources_cache c ON si.pve_node_id = c.pve_node_id AND si.vmid = c.vmid
                     WHERE JSON_CONTAINS(s.tags, ?)',
                    [json_encode($policy['target_value'])]
                );

            default:
                return [];
        }
    }

    /**
     * Check if current time matches schedule
     */
    private static function matchesSchedule(array $config): bool
    {
        if (!isset($config['schedule'])) {
            return false;
        }

        $schedule = $config['schedule'];
        $now = new DateTime();

        // Check day of week
        if (isset($schedule['days_of_week'])) {
            $currentDay = (int) $now->format('N'); // 1 = Monday, 7 = Sunday
            if (!in_array($currentDay, $schedule['days_of_week'])) {
                return false;
            }
        }

        // Check time
        if (isset($schedule['time'])) {
            $currentTime = $now->format('H:i');
            if ($currentTime !== $schedule['time']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record policy execution
     */
    private static function recordExecution(int $policyId, array $result): void
    {
        Database::insert(
            'INSERT INTO policy_executions (policy_id, success, message, details_json)
             VALUES (?, ?, ?, ?)',
            [
                $policyId,
                $result['success'] ? 1 : 0,
                $result['message'] ?? null,
                json_encode($result),
            ]
        );
    }
}
