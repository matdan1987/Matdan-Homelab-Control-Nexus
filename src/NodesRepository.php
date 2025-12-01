<?php
/**
 * NodesRepository Class
 *
 * Repository for managing Proxmox VE nodes configuration.
 * Handles CRUD operations and caching for node resources.
 */

class NodesRepository
{
    /**
     * Get all nodes
     *
     * @param bool $enabledOnly Only return enabled nodes
     * @return array Nodes list
     */
    public static function getAll(bool $enabledOnly = false): array
    {
        $query = 'SELECT * FROM pve_nodes';

        if ($enabledOnly) {
            $query .= ' WHERE enabled = 1';
        }

        $query .= ' ORDER BY name ASC';

        return Database::select($query);
    }

    /**
     * Get node by ID
     *
     * @param int $id Node ID
     * @return array|null Node data
     */
    public static function getById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM pve_nodes WHERE id = ?', [$id]);
    }

    /**
     * Get node by name
     *
     * @param string $name Node name
     * @return array|null Node data
     */
    public static function getByName(string $name): ?array
    {
        return Database::selectOne('SELECT * FROM pve_nodes WHERE name = ?', [$name]);
    }

    /**
     * Create a new node
     *
     * @param array $data Node data
     * @return int New node ID
     */
    public static function create(array $data): int
    {
        $id = Database::insert(
            'INSERT INTO pve_nodes (name, api_url, token_id, token_secret, verify_ssl, enabled, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['api_url'],
                $data['token_id'],
                $data['token_secret'],
                $data['verify_ssl'] ?? 0,
                $data['enabled'] ?? 1,
                $data['remarks'] ?? null,
            ]
        );

        EventLogger::info('node_management', "Created Proxmox node: {$data['name']}", Auth::id(), ['node_id' => $id]);

        return $id;
    }

    /**
     * Update a node
     *
     * @param int $id Node ID
     * @param array $data Updated data
     * @return int Number of affected rows
     */
    public static function update(int $id, array $data): int
    {
        $node = self::getById($id);
        if (!$node) {
            throw new Exception("Node with ID $id not found");
        }

        $fields = [];
        $params = [];

        $allowedFields = ['name', 'api_url', 'token_id', 'token_secret', 'verify_ssl', 'enabled', 'remarks'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return 0;
        }

        $params[] = $id;

        $result = Database::update(
            'UPDATE pve_nodes SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('node_management', "Updated Proxmox node: {$node['name']}", Auth::id(), ['node_id' => $id]);

        return $result;
    }

    /**
     * Delete a node
     *
     * @param int $id Node ID
     * @return int Number of affected rows
     */
    public static function delete(int $id): int
    {
        $node = self::getById($id);
        if (!$node) {
            throw new Exception("Node with ID $id not found");
        }

        $result = Database::delete('DELETE FROM pve_nodes WHERE id = ?', [$id]);

        EventLogger::warning('node_management', "Deleted Proxmox node: {$node['name']}", Auth::id(), ['node_id' => $id]);

        return $result;
    }

    /**
     * Test connection to a node
     *
     * @param int $id Node ID
     * @return bool Connection success
     */
    public static function testConnection(int $id): bool
    {
        $node = self::getById($id);
        if (!$node) {
            return false;
        }

        try {
            $client = self::getClient($id);
            $success = $client->testConnection();

            // Update status
            Database::update(
                'UPDATE pve_nodes SET last_check = NOW(), last_status = ? WHERE id = ?',
                [$success ? 'online' : 'offline', $id]
            );

            if ($success) {
                EventLogger::debug('node_check', "Node {$node['name']} is online", null, ['node_id' => $id]);
            } else {
                EventLogger::warning('node_check', "Node {$node['name']} is offline", null, ['node_id' => $id]);
            }

            return $success;
        } catch (Exception $e) {
            Database::update(
                'UPDATE pve_nodes SET last_check = NOW(), last_status = ? WHERE id = ?',
                ['error', $id]
            );

            EventLogger::error('node_check', "Error connecting to node {$node['name']}: " . $e->getMessage(), null, ['node_id' => $id]);

            return false;
        }
    }

    /**
     * Get Proxmox API client for a node
     *
     * @param int $id Node ID
     * @return ProxmoxApiClient
     */
    public static function getClient(int $id): ProxmoxApiClient
    {
        $node = self::getById($id);
        if (!$node) {
            throw new Exception("Node with ID $id not found");
        }

        return new ProxmoxApiClient(
            $node['api_url'],
            $node['token_id'],
            $node['token_secret'],
            (bool) $node['verify_ssl']
        );
    }

    /**
     * Update resource cache for a node
     *
     * @param int $id Node ID
     * @return int Number of resources cached
     */
    public static function updateResourceCache(int $id): int
    {
        $node = self::getById($id);
        if (!$node) {
            throw new Exception("Node with ID $id not found");
        }

        try {
            $client = self::getClient($id);
            $resources = $client->getResources('vm');

            // Clear old cache
            Database::delete('DELETE FROM pve_resources_cache WHERE pve_node_id = ?', [$id]);

            // Insert new cache
            $count = 0;
            foreach ($resources as $resource) {
                if (!isset($resource['vmid'])) {
                    continue;
                }

                Database::insert(
                    'INSERT INTO pve_resources_cache
                     (pve_node_id, node_name, vmid, type, name, status, cpu, maxcpu, mem, maxmem, disk, maxdisk, uptime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        $resource['node'] ?? '',
                        $resource['vmid'],
                        $resource['type'] ?? 'qemu',
                        $resource['name'] ?? null,
                        $resource['status'] ?? null,
                        $resource['cpu'] ?? null,
                        $resource['maxcpu'] ?? null,
                        $resource['mem'] ?? null,
                        $resource['maxmem'] ?? null,
                        $resource['disk'] ?? null,
                        $resource['maxdisk'] ?? null,
                        $resource['uptime'] ?? null,
                    ]
                );
                $count++;
            }

            EventLogger::debug('node_cache', "Cached $count resources for node {$node['name']}", null, ['node_id' => $id]);

            return $count;
        } catch (Exception $e) {
            EventLogger::error('node_cache', "Failed to cache resources for node {$node['name']}: " . $e->getMessage(), null, ['node_id' => $id]);
            throw $e;
        }
    }

    /**
     * Get cached resources for a node
     *
     * @param int $id Node ID
     * @param int $maxAge Maximum cache age in seconds
     * @return array Cached resources
     */
    public static function getCachedResources(int $id, int $maxAge = 300): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAge);

        $resources = Database::select(
            'SELECT * FROM pve_resources_cache
             WHERE pve_node_id = ? AND cached_at >= ?
             ORDER BY vmid ASC',
            [$id, $cutoff]
        );

        // If cache is old or empty, try to refresh
        if (empty($resources)) {
            try {
                self::updateResourceCache($id);
                $resources = Database::select(
                    'SELECT * FROM pve_resources_cache WHERE pve_node_id = ? ORDER BY vmid ASC',
                    [$id]
                );
            } catch (Exception $e) {
                // Return empty if refresh fails
                return [];
            }
        }

        return $resources;
    }

    /**
     * Get all cached resources across all nodes
     *
     * @param int $maxAge Maximum cache age in seconds
     * @return array Cached resources
     */
    public static function getAllCachedResources(int $maxAge = 300): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAge);

        return Database::select(
            'SELECT c.*, n.name as pve_node_name
             FROM pve_resources_cache c
             JOIN pve_nodes n ON c.pve_node_id = n.id
             WHERE c.cached_at >= ? AND n.enabled = 1
             ORDER BY c.vmid ASC',
            [$cutoff]
        );
    }

    /**
     * Get node statistics
     *
     * @param int $id Node ID
     * @return array Statistics
     */
    public static function getStatistics(int $id): array
    {
        $resources = self::getCachedResources($id);

        $stats = [
            'total_vms' => 0,
            'running_vms' => 0,
            'stopped_vms' => 0,
            'qemu_count' => 0,
            'lxc_count' => 0,
            'total_cpu_cores' => 0,
            'total_memory_mb' => 0,
            'total_disk_gb' => 0,
        ];

        foreach ($resources as $resource) {
            $stats['total_vms']++;

            if ($resource['status'] === 'running') {
                $stats['running_vms']++;
            } else {
                $stats['stopped_vms']++;
            }

            if ($resource['type'] === 'qemu') {
                $stats['qemu_count']++;
            } else {
                $stats['lxc_count']++;
            }

            $stats['total_cpu_cores'] += $resource['maxcpu'] ?? 0;
            $stats['total_memory_mb'] += ($resource['maxmem'] ?? 0) / 1048576; // Convert to MB
            $stats['total_disk_gb'] += ($resource['maxdisk'] ?? 0) / 1073741824; // Convert to GB
        }

        return $stats;
    }
}
