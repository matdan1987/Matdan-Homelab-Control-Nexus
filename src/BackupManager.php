<?php
/**
 * BackupManager Class
 *
 * Manages backup policies and execution.
 */

class BackupManager
{
    /**
     * Execute all due backup policies
     *
     * @return array Execution results
     */
    public static function executeAll(): array
    {
        $policies = Database::select(
            'SELECT * FROM backup_policies
             WHERE enabled = 1
             AND (next_run IS NULL OR next_run <= NOW())'
        );

        $results = [];

        foreach ($policies as $policy) {
            $result = self::executePolicy($policy);
            $results[] = $result;

            // Update next run time
            self::updateNextRun($policy['id'], $policy['schedule_cron']);
        }

        return $results;
    }

    /**
     * Execute a backup policy
     *
     * @param array $policy Backup policy data
     * @return array Execution result
     */
    public static function executePolicy(array $policy): array
    {
        try {
            // Get targets based on scope
            $targets = self::getTargets($policy);

            $backups = [];
            foreach ($targets as $target) {
                $backupResult = self::executeBackup($policy, $target);
                $backups[] = $backupResult;

                // Record execution
                self::recordExecution($policy['id'], $target, $backupResult);
            }

            // Update last run
            Database::update(
                'UPDATE backup_policies SET last_run = NOW() WHERE id = ?',
                [$policy['id']]
            );

            EventLogger::info('backup',
                "Backup policy {$policy['name']}: {$backupResult['success']} successful, " . count(array_filter($backups, fn($b) => !$b['success'])) . " failed",
                null,
                ['policy_id' => $policy['id']]
            );

            return [
                'success' => true,
                'policy_id' => $policy['id'],
                'backups' => $backups,
            ];
        } catch (Exception $e) {
            EventLogger::error('backup_error',
                "Failed to execute backup policy {$policy['name']}: " . $e->getMessage(),
                null,
                ['policy_id' => $policy['id']]
            );

            return [
                'success' => false,
                'policy_id' => $policy['id'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute backup for a single target
     *
     * @param array $policy Backup policy
     * @param array $target Target VM/LXC
     * @return array Backup result
     */
    private static function executeBackup(array $policy, array $target): array
    {
        $startTime = microtime(true);

        try {
            $node = NodesRepository::getById($target['pve_node_id']);
            $client = NodesRepository::getClient($target['pve_node_id']);

            // Prepare backup options
            $options = [
                'vmid' => $target['vmid'],
                'mode' => $policy['backup_mode'] ?? 'snapshot',
                'compress' => $policy['compression'] ?? 'zstd',
                'remove' => 0, // Don't remove old backups automatically (we manage retention)
            ];

            // Execute backup
            $result = $client->createBackup($node['name'], $target['vmid'], $target['type'], $options);

            $duration = round(microtime(true) - $startTime);

            EventLogger::info('backup',
                "Backup created for VM {$target['vmid']} on node {$node['name']}",
                null,
                [
                    'policy_id' => $policy['id'],
                    'vmid' => $target['vmid'],
                    'duration' => $duration
                ]
            );

            // Cleanup old backups based on retention
            self::cleanupOldBackups($policy, $target);

            return [
                'success' => true,
                'vmid' => $target['vmid'],
                'node_id' => $target['pve_node_id'],
                'duration' => $duration,
                'result' => $result,
            ];
        } catch (Exception $e) {
            $duration = round(microtime(true) - $startTime);

            EventLogger::error('backup_error',
                "Backup failed for VM {$target['vmid']}: " . $e->getMessage(),
                null,
                ['policy_id' => $policy['id'], 'vmid' => $target['vmid']]
            );

            return [
                'success' => false,
                'vmid' => $target['vmid'],
                'node_id' => $target['pve_node_id'],
                'duration' => $duration,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get backup targets based on scope
     */
    private static function getTargets(array $policy): array
    {
        switch ($policy['scope_type']) {
            case 'vm':
            case 'lxc':
                // Single VM/LXC
                if ($policy['scope_value']) {
                    return Database::select(
                        'SELECT * FROM pve_resources_cache WHERE vmid = ? AND type = ?',
                        [$policy['scope_value'], $policy['scope_type']]
                    );
                }
                return [];

            case 'service':
                // All instances of a service
                return Database::select(
                    'SELECT c.* FROM service_instances si
                     JOIN pve_resources_cache c ON si.pve_node_id = c.pve_node_id AND si.vmid = c.vmid
                     WHERE si.service_id = ?',
                    [(int) $policy['scope_value']]
                );

            case 'tag':
                // All services with a specific tag
                return Database::select(
                    'SELECT c.* FROM services s
                     JOIN service_instances si ON s.id = si.service_id
                     JOIN pve_resources_cache c ON si.pve_node_id = c.pve_node_id AND si.vmid = c.vmid
                     WHERE JSON_CONTAINS(s.tags, ?)',
                    [json_encode($policy['scope_value'])]
                );

            case 'all':
                // All VMs and LXCs
                return Database::select('SELECT * FROM pve_resources_cache');

            default:
                return [];
        }
    }

    /**
     * Cleanup old backups based on retention policy
     */
    private static function cleanupOldBackups(array $policy, array $target): void
    {
        $retention = $policy['retention_count'] ?? 7;

        // Get old backup executions
        $oldBackups = Database::select(
            'SELECT * FROM backup_executions
             WHERE backup_policy_id = ? AND pve_node_id = ? AND vmid = ? AND success = 1
             ORDER BY executed_at DESC
             LIMIT 999 OFFSET ?',
            [$policy['id'], $target['pve_node_id'], $target['vmid'], $retention]
        );

        // Note: Actual backup file deletion would need to be implemented via Proxmox API
        // For now, we just log the cleanup intent
        if (!empty($oldBackups)) {
            EventLogger::debug('backup',
                "Would cleanup " . count($oldBackups) . " old backups for VM {$target['vmid']}",
                null,
                ['policy_id' => $policy['id'], 'vmid' => $target['vmid']]
            );
        }
    }

    /**
     * Record backup execution
     */
    private static function recordExecution(int $policyId, array $target, array $result): void
    {
        Database::insert(
            'INSERT INTO backup_executions
             (backup_policy_id, pve_node_id, vmid, success, backup_file, size_bytes, duration_seconds, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $policyId,
                $target['pve_node_id'],
                $target['vmid'],
                $result['success'] ? 1 : 0,
                $result['backup_file'] ?? null,
                $result['size_bytes'] ?? null,
                $result['duration'] ?? null,
                $result['error'] ?? 'Backup successful',
            ]
        );
    }

    /**
     * Update next run time based on cron expression
     */
    private static function updateNextRun(int $policyId, string $cronExpression): void
    {
        // Simple cron parsing - in production, use a proper cron library
        // For now, just set next run to 24 hours from now
        Database::update(
            'UPDATE backup_policies SET next_run = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?',
            [$policyId]
        );
    }

    /**
     * Create a manual backup
     *
     * @param int $nodeId Node ID
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param array $options Backup options
     * @return array Backup result
     */
    public static function createManualBackup(int $nodeId, int $vmid, string $type, array $options = []): array
    {
        try {
            $node = NodesRepository::getById($nodeId);
            $client = NodesRepository::getClient($nodeId);

            $defaultOptions = [
                'vmid' => $vmid,
                'mode' => 'snapshot',
                'compress' => 'zstd',
            ];

            $backupOptions = array_merge($defaultOptions, $options);

            $result = $client->createBackup($node['name'], $vmid, $type, $backupOptions);

            EventLogger::info('backup',
                "Manual backup created for VM {$vmid} on node {$node['name']}",
                Auth::id(),
                ['node_id' => $nodeId, 'vmid' => $vmid]
            );

            return [
                'success' => true,
                'result' => $result,
            ];
        } catch (Exception $e) {
            EventLogger::error('backup_error',
                "Manual backup failed for VM {$vmid}: " . $e->getMessage(),
                Auth::id(),
                ['node_id' => $nodeId, 'vmid' => $vmid]
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
