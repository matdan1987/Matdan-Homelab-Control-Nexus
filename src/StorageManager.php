<?php
/**
 * StorageManager
 *
 * Complete storage management system with pool tracking,
 * usage monitoring, and allocation management.
 */

class StorageManager
{
    /**
     * Register storage pool
     */
    public static function registerPool(int $pveNodeId, string $name, string $type, array $options = []): int
    {
        $poolId = Database::insert(
            'INSERT INTO storage_pools (pve_node_id, name, type, path, total_size_gb, used_size_gb, shared, content_types, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $pveNodeId,
                $name,
                $type,
                $options['path'] ?? null,
                $options['total_size_gb'] ?? 0,
                $options['used_size_gb'] ?? 0,
                $options['shared'] ?? 0,
                isset($options['content_types']) ? json_encode($options['content_types']) : json_encode(['images', 'rootdir']),
            ]
        );

        EventLogger::info('storage_pool_registered', "Storage pool registered: $name on node", null, [
            'pool_id' => $poolId,
            'node_id' => $pveNodeId,
        ]);

        return $poolId;
    }

    /**
     * Update storage pool
     */
    public static function updatePool(int $poolId, array $updates): void
    {
        $allowedFields = ['name', 'path', 'total_size_gb', 'used_size_gb', 'shared', 'content_types', 'active'];

        $fields = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                if ($field === 'content_types' && is_array($value)) {
                    $fields[] = "$field = ?";
                    $params[] = json_encode($value);
                } else {
                    $fields[] = "$field = ?";
                    $params[] = $value;
                }
            }
        }

        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }

        $params[] = $poolId;

        Database::update(
            'UPDATE storage_pools SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('storage_pool_updated', "Storage pool updated: ID $poolId", null, ['pool_id' => $poolId]);
    }

    /**
     * Sync storage pools from Proxmox
     */
    public static function syncFromProxmox(int $pveNodeId): int
    {
        $node = Database::selectOne(
            'SELECT * FROM pve_nodes WHERE id = ?',
            [$pveNodeId]
        );

        if (!$node) {
            throw new Exception('Node not found');
        }

        $pve = new ProxmoxApiClient($node['hostname'], $node['api_token']);

        // Get storage list from Proxmox
        $storages = $pve->request('GET', "/nodes/{$node['name']}/storage");

        $syncedCount = 0;

        foreach ($storages as $storage) {
            $storageId = $storage['storage'];
            $type = $storage['type'];

            // Get storage status
            try {
                $status = $pve->request('GET', "/nodes/{$node['name']}/storage/$storageId/status");

                $totalGb = isset($status['total']) ? round($status['total'] / (1024**3), 2) : 0;
                $usedGb = isset($status['used']) ? round($status['used'] / (1024**3), 2) : 0;

                // Check if pool exists
                $existing = Database::selectOne(
                    'SELECT * FROM storage_pools WHERE pve_node_id = ? AND name = ?',
                    [$pveNodeId, $storageId]
                );

                if ($existing) {
                    // Update existing
                    self::updatePool($existing['id'], [
                        'total_size_gb' => $totalGb,
                        'used_size_gb' => $usedGb,
                    ]);
                } else {
                    // Create new
                    self::registerPool($pveNodeId, $storageId, $type, [
                        'total_size_gb' => $totalGb,
                        'used_size_gb' => $usedGb,
                        'shared' => $storage['shared'] ?? 0,
                        'content_types' => isset($storage['content']) ? explode(',', $storage['content']) : ['images'],
                    ]);
                }

                $syncedCount++;

            } catch (Exception $e) {
                // Skip inaccessible storage
                continue;
            }
        }

        EventLogger::info('storage_synced', "Synced $syncedCount storage pools from node {$node['name']}", null, [
            'node_id' => $pveNodeId,
            'count' => $syncedCount,
        ]);

        return $syncedCount;
    }

    /**
     * Get storage pool details
     */
    public static function getPool(int $poolId): array
    {
        $pool = Database::selectOne(
            'SELECT sp.*, pn.name as node_name
             FROM storage_pools sp
             JOIN pve_nodes pn ON pn.id = sp.pve_node_id
             WHERE sp.id = ?',
            [$poolId]
        );

        if (!$pool) {
            throw new Exception('Storage pool not found');
        }

        $pool['content_types'] = $pool['content_types'] ? json_decode($pool['content_types'], true) : [];

        // Calculate usage
        $availableGb = $pool['total_size_gb'] - $pool['used_size_gb'];
        $usagePercent = $pool['total_size_gb'] > 0
            ? round(($pool['used_size_gb'] / $pool['total_size_gb']) * 100, 1)
            : 0;

        $pool['available_size_gb'] = round($availableGb, 2);
        $pool['usage_percent'] = $usagePercent;

        return $pool;
    }

    /**
     * Get all storage pools
     */
    public static function getAllPools(?int $pveNodeId = null, ?bool $activeOnly = true): array
    {
        $where = [];
        $params = [];

        if ($activeOnly) {
            $where[] = 'sp.active = 1';
        }

        if ($pveNodeId) {
            $where[] = 'sp.pve_node_id = ?';
            $params[] = $pveNodeId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $pools = Database::select(
            "SELECT sp.*, pn.name as node_name
             FROM storage_pools sp
             JOIN pve_nodes pn ON pn.id = sp.pve_node_id
             $whereClause
             ORDER BY pn.name, sp.name",
            $params
        );

        foreach ($pools as &$pool) {
            $pool['content_types'] = $pool['content_types'] ? json_decode($pool['content_types'], true) : [];

            $availableGb = $pool['total_size_gb'] - $pool['used_size_gb'];
            $usagePercent = $pool['total_size_gb'] > 0
                ? round(($pool['used_size_gb'] / $pool['total_size_gb']) * 100, 1)
                : 0;

            $pool['available_size_gb'] = round($availableGb, 2);
            $pool['usage_percent'] = $usagePercent;
        }

        return $pools;
    }

    /**
     * Get storage usage statistics
     */
    public static function getStatistics(?int $pveNodeId = null): array
    {
        $where = $pveNodeId ? 'WHERE pve_node_id = ?' : '';
        $params = $pveNodeId ? [$pveNodeId] : [];

        $stats = Database::selectOne(
            "SELECT
                COUNT(*) as total_pools,
                SUM(total_size_gb) as total_capacity_gb,
                SUM(used_size_gb) as total_used_gb,
                SUM(total_size_gb - used_size_gb) as total_available_gb
             FROM storage_pools
             $where",
            $params
        );

        $totalCapacity = (float)$stats['total_capacity_gb'];
        $totalUsed = (float)$stats['total_used_gb'];

        $stats['overall_usage_percent'] = $totalCapacity > 0
            ? round(($totalUsed / $totalCapacity) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * Get storage by node
     */
    public static function getNodeStorage(int $pveNodeId): array
    {
        $pools = self::getAllPools($pveNodeId, true);

        $totalCapacity = 0;
        $totalUsed = 0;

        foreach ($pools as $pool) {
            $totalCapacity += $pool['total_size_gb'];
            $totalUsed += $pool['used_size_gb'];
        }

        return [
            'node_id' => $pveNodeId,
            'pools' => $pools,
            'total_pools' => count($pools),
            'total_capacity_gb' => round($totalCapacity, 2),
            'total_used_gb' => round($totalUsed, 2),
            'total_available_gb' => round($totalCapacity - $totalUsed, 2),
            'usage_percent' => $totalCapacity > 0 ? round(($totalUsed / $totalCapacity) * 100, 1) : 0,
        ];
    }

    /**
     * Get pools with low space
     */
    public static function getLowSpacePools(float $thresholdPercent = 80): array
    {
        $pools = self::getAllPools(null, true);

        $lowSpacePools = array_filter($pools, function($pool) use ($thresholdPercent) {
            return $pool['usage_percent'] >= $thresholdPercent;
        });

        return array_values($lowSpacePools);
    }

    /**
     * Get storage trend
     */
    public static function getUsageTrend(int $poolId, int $days = 30): array
    {
        // This would require historical data collection
        // For now, return current state
        $pool = self::getPool($poolId);

        return [
            'pool_id' => $poolId,
            'current_usage_gb' => $pool['used_size_gb'],
            'current_usage_percent' => $pool['usage_percent'],
            'total_capacity_gb' => $pool['total_size_gb'],
            'message' => 'Historical trend data requires periodic snapshot collection',
        ];
    }

    /**
     * Find best storage pool for allocation
     */
    public static function findBestPool(int $pveNodeId, int $requiredSizeGb, ?string $contentType = null): ?array
    {
        $pools = self::getAllPools($pveNodeId, true);

        $suitablePools = [];

        foreach ($pools as $pool) {
            // Check if pool has enough space
            if ($pool['available_size_gb'] < $requiredSizeGb) {
                continue;
            }

            // Check if pool supports required content type
            if ($contentType && !in_array($contentType, $pool['content_types'])) {
                continue;
            }

            $suitablePools[] = $pool;
        }

        if (empty($suitablePools)) {
            return null;
        }

        // Sort by lowest usage percentage
        usort($suitablePools, function($a, $b) {
            return $a['usage_percent'] <=> $b['usage_percent'];
        });

        return $suitablePools[0];
    }

    /**
     * Delete storage pool
     */
    public static function deletePool(int $poolId): void
    {
        Database::update(
            'UPDATE storage_pools SET active = 0 WHERE id = ?',
            [$poolId]
        );

        EventLogger::info('storage_pool_deleted', "Storage pool deactivated: ID $poolId", null, ['pool_id' => $poolId]);
    }

    /**
     * Get storage health status
     */
    public static function getHealthStatus(): array
    {
        $allPools = self::getAllPools(null, true);

        $healthStatus = [
            'healthy' => [],
            'warning' => [],
            'critical' => [],
        ];

        foreach ($allPools as $pool) {
            $usage = $pool['usage_percent'];

            if ($usage >= 90) {
                $healthStatus['critical'][] = $pool;
            } elseif ($usage >= 75) {
                $healthStatus['warning'][] = $pool;
            } else {
                $healthStatus['healthy'][] = $pool;
            }
        }

        return [
            'summary' => [
                'total_pools' => count($allPools),
                'healthy_count' => count($healthStatus['healthy']),
                'warning_count' => count($healthStatus['warning']),
                'critical_count' => count($healthStatus['critical']),
            ],
            'pools' => $healthStatus,
        ];
    }
}
