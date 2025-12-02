<?php
/**
 * NetworkManager
 *
 * Complete IP Address Management (IPAM) system with network
 * management, IP allocation, and tracking.
 */

class NetworkManager
{
    /**
     * Create a new network
     */
    public static function createNetwork(string $name, string $subnet, string $gateway, array $options = []): int
    {
        // Parse subnet to get IP range
        list($networkIp, $cidr) = explode('/', $subnet);

        // Calculate network details
        $networkDetails = self::calculateNetworkDetails($networkIp, $cidr);

        $networkId = Database::insert(
            'INSERT INTO networks (name, subnet, gateway, ip_range_start, ip_range_end, vlan_id, dns_servers, domain, description, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $name,
                $subnet,
                $gateway,
                $networkDetails['range_start'],
                $networkDetails['range_end'],
                $options['vlan_id'] ?? null,
                isset($options['dns_servers']) ? json_encode($options['dns_servers']) : null,
                $options['domain'] ?? null,
                $options['description'] ?? null,
            ]
        );

        EventLogger::info('network_created', "Network created: $name ($subnet)", null, ['network_id' => $networkId]);

        return $networkId;
    }

    /**
     * Calculate network details from CIDR
     */
    private static function calculateNetworkDetails(string $networkIp, int $cidr): array
    {
        $ipLong = ip2long($networkIp);
        $mask = -1 << (32 - $cidr);
        $networkLong = $ipLong & $mask;
        $broadcastLong = $networkLong | ~$mask;

        return [
            'network' => long2ip($networkLong),
            'broadcast' => long2ip($broadcastLong),
            'range_start' => long2ip($networkLong + 1),
            'range_end' => long2ip($broadcastLong - 1),
            'total_ips' => ($broadcastLong - $networkLong - 1),
        ];
    }

    /**
     * Allocate IP address from network
     */
    public static function allocateIp(int $networkId, int $pveNodeId, int $vmid, ?string $requestedIp = null): string
    {
        $network = Database::selectOne(
            'SELECT * FROM networks WHERE id = ? AND active = 1',
            [$networkId]
        );

        if (!$network) {
            throw new Exception('Network not found or inactive');
        }

        // Check if VM already has an IP in this network
        $existing = Database::selectOne(
            'SELECT * FROM ip_allocations WHERE network_id = ? AND pve_node_id = ? AND vmid = ? AND released_at IS NULL',
            [$networkId, $pveNodeId, $vmid]
        );

        if ($existing) {
            return $existing['ip_address'];
        }

        if ($requestedIp) {
            // Validate requested IP is in range
            if (!self::isIpInRange($requestedIp, $network['ip_range_start'], $network['ip_range_end'])) {
                throw new Exception('Requested IP is not in network range');
            }

            // Check if IP is already allocated
            $allocated = Database::selectOne(
                'SELECT * FROM ip_allocations WHERE network_id = ? AND ip_address = ? AND released_at IS NULL',
                [$networkId, $requestedIp]
            );

            if ($allocated) {
                throw new Exception('Requested IP is already allocated');
            }

            $ipAddress = $requestedIp;
        } else {
            // Find next available IP
            $ipAddress = self::findNextAvailableIp($networkId, $network['ip_range_start'], $network['ip_range_end']);
        }

        // Allocate IP
        Database::insert(
            'INSERT INTO ip_allocations (network_id, ip_address, pve_node_id, vmid, allocated_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$networkId, $ipAddress, $pveNodeId, $vmid]
        );

        EventLogger::info('ip_allocated', "IP allocated: $ipAddress for VMID $vmid", null, [
            'network_id' => $networkId,
            'ip' => $ipAddress,
            'vmid' => $vmid,
        ]);

        return $ipAddress;
    }

    /**
     * Check if IP is in range
     */
    private static function isIpInRange(string $ip, string $rangeStart, string $rangeEnd): bool
    {
        $ipLong = ip2long($ip);
        $startLong = ip2long($rangeStart);
        $endLong = ip2long($rangeEnd);

        return $ipLong >= $startLong && $ipLong <= $endLong;
    }

    /**
     * Find next available IP in network
     */
    private static function findNextAvailableIp(int $networkId, string $rangeStart, string $rangeEnd): string
    {
        $startLong = ip2long($rangeStart);
        $endLong = ip2long($rangeEnd);

        // Get all allocated IPs
        $allocated = Database::select(
            'SELECT ip_address FROM ip_allocations WHERE network_id = ? AND released_at IS NULL',
            [$networkId]
        );

        $allocatedIps = array_map(fn($row) => ip2long($row['ip_address']), $allocated);

        // Find first available
        for ($ip = $startLong; $ip <= $endLong; $ip++) {
            if (!in_array($ip, $allocatedIps)) {
                return long2ip($ip);
            }
        }

        throw new Exception('No available IP addresses in network');
    }

    /**
     * Release IP allocation
     */
    public static function releaseIp(int $networkId, string $ipAddress): void
    {
        $updated = Database::update(
            'UPDATE ip_allocations SET released_at = NOW()
             WHERE network_id = ? AND ip_address = ? AND released_at IS NULL',
            [$networkId, $ipAddress]
        );

        if ($updated === 0) {
            throw new Exception('IP allocation not found or already released');
        }

        EventLogger::info('ip_released', "IP released: $ipAddress", null, [
            'network_id' => $networkId,
            'ip' => $ipAddress,
        ]);
    }

    /**
     * Release all IPs for a VM
     */
    public static function releaseVmIps(int $pveNodeId, int $vmid): int
    {
        $count = Database::update(
            'UPDATE ip_allocations SET released_at = NOW()
             WHERE pve_node_id = ? AND vmid = ? AND released_at IS NULL',
            [$pveNodeId, $vmid]
        );

        EventLogger::info('vm_ips_released', "Released $count IPs for VMID $vmid", null, [
            'pve_node_id' => $pveNodeId,
            'vmid' => $vmid,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Get network details with usage statistics
     */
    public static function getNetwork(int $networkId): array
    {
        $network = Database::selectOne(
            'SELECT * FROM networks WHERE id = ?',
            [$networkId]
        );

        if (!$network) {
            throw new Exception('Network not found');
        }

        // Calculate total IPs in range
        $startLong = ip2long($network['ip_range_start']);
        $endLong = ip2long($network['ip_range_end']);
        $totalIps = $endLong - $startLong + 1;

        // Count allocated IPs
        $allocated = Database::selectOne(
            'SELECT COUNT(*) as count FROM ip_allocations
             WHERE network_id = ? AND released_at IS NULL',
            [$networkId]
        );

        $allocatedCount = (int)$allocated['count'];
        $availableCount = $totalIps - $allocatedCount;

        $network['dns_servers'] = $network['dns_servers'] ? json_decode($network['dns_servers'], true) : null;
        $network['usage'] = [
            'total_ips' => $totalIps,
            'allocated' => $allocatedCount,
            'available' => $availableCount,
            'utilization_percent' => $totalIps > 0 ? round(($allocatedCount / $totalIps) * 100, 1) : 0,
        ];

        return $network;
    }

    /**
     * Get all networks
     */
    public static function getAllNetworks(?bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';

        $networks = Database::select(
            "SELECT * FROM networks $where ORDER BY name"
        );

        foreach ($networks as &$network) {
            $network['dns_servers'] = $network['dns_servers'] ? json_decode($network['dns_servers'], true) : null;

            // Calculate usage
            $startLong = ip2long($network['ip_range_start']);
            $endLong = ip2long($network['ip_range_end']);
            $totalIps = $endLong - $startLong + 1;

            $allocated = Database::selectOne(
                'SELECT COUNT(*) as count FROM ip_allocations
                 WHERE network_id = ? AND released_at IS NULL',
                [$network['id']]
            );

            $allocatedCount = (int)$allocated['count'];

            $network['usage'] = [
                'total_ips' => $totalIps,
                'allocated' => $allocatedCount,
                'available' => $totalIps - $allocatedCount,
                'utilization_percent' => $totalIps > 0 ? round(($allocatedCount / $totalIps) * 100, 1) : 0,
            ];
        }

        return $networks;
    }

    /**
     * Get IP allocations for network
     */
    public static function getNetworkAllocations(int $networkId, ?bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'AND ia.released_at IS NULL' : '';

        $allocations = Database::select(
            "SELECT ia.*, pn.name as node_name, prc.name as vm_name, prc.type, prc.status
             FROM ip_allocations ia
             JOIN pve_nodes pn ON pn.id = ia.pve_node_id
             LEFT JOIN pve_resources_cache prc ON prc.pve_node_id = ia.pve_node_id AND prc.vmid = ia.vmid
             WHERE ia.network_id = ? $where
             ORDER BY INET_ATON(ia.ip_address)",
            [$networkId]
        );

        return $allocations;
    }

    /**
     * Get IP allocations for VM
     */
    public static function getVmAllocations(int $pveNodeId, int $vmid): array
    {
        $allocations = Database::select(
            'SELECT ia.*, n.name as network_name, n.subnet
             FROM ip_allocations ia
             JOIN networks n ON n.id = ia.network_id
             WHERE ia.pve_node_id = ? AND ia.vmid = ? AND ia.released_at IS NULL
             ORDER BY n.name',
            [$pveNodeId, $vmid]
        );

        return $allocations;
    }

    /**
     * Update network
     */
    public static function updateNetwork(int $networkId, array $updates): void
    {
        $allowedFields = ['name', 'gateway', 'vlan_id', 'dns_servers', 'domain', 'description', 'active'];

        $fields = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                if ($field === 'dns_servers' && is_array($value)) {
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

        $params[] = $networkId;

        Database::update(
            'UPDATE networks SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('network_updated', "Network updated: ID $networkId", null, ['network_id' => $networkId]);
    }

    /**
     * Delete network
     */
    public static function deleteNetwork(int $networkId): void
    {
        // Check for active allocations
        $allocated = Database::selectOne(
            'SELECT COUNT(*) as count FROM ip_allocations
             WHERE network_id = ? AND released_at IS NULL',
            [$networkId]
        );

        if ((int)$allocated['count'] > 0) {
            throw new Exception('Cannot delete network with active IP allocations');
        }

        Database::update(
            'UPDATE networks SET active = 0 WHERE id = ?',
            [$networkId]
        );

        EventLogger::info('network_deleted', "Network deactivated: ID $networkId", null, ['network_id' => $networkId]);
    }

    /**
     * Reserve IP address (manual reservation)
     */
    public static function reserveIp(int $networkId, string $ipAddress, string $description): void
    {
        $network = Database::selectOne(
            'SELECT * FROM networks WHERE id = ? AND active = 1',
            [$networkId]
        );

        if (!$network) {
            throw new Exception('Network not found or inactive');
        }

        // Validate IP is in range
        if (!self::isIpInRange($ipAddress, $network['ip_range_start'], $network['ip_range_end'])) {
            throw new Exception('IP is not in network range');
        }

        // Check if already allocated
        $allocated = Database::selectOne(
            'SELECT * FROM ip_allocations WHERE network_id = ? AND ip_address = ? AND released_at IS NULL',
            [$networkId, $ipAddress]
        );

        if ($allocated) {
            throw new Exception('IP is already allocated');
        }

        // Reserve (using vmid = 0 for manual reservations)
        Database::insert(
            'INSERT INTO ip_allocations (network_id, ip_address, pve_node_id, vmid, hostname, allocated_at)
             VALUES (?, ?, 0, 0, ?, NOW())',
            [$networkId, $ipAddress, $description]
        );

        EventLogger::info('ip_reserved', "IP reserved: $ipAddress - $description", null, [
            'network_id' => $networkId,
            'ip' => $ipAddress,
        ]);
    }

    /**
     * Scan network for used IPs (ping sweep)
     */
    public static function scanNetwork(int $networkId): array
    {
        $network = Database::selectOne(
            'SELECT * FROM networks WHERE id = ?',
            [$networkId]
        );

        if (!$network) {
            throw new Exception('Network not found');
        }

        $startLong = ip2long($network['ip_range_start']);
        $endLong = ip2long($network['ip_range_end']);

        $results = [
            'responding' => [],
            'not_responding' => [],
        ];

        // Limit scan to reasonable size
        $totalIps = $endLong - $startLong + 1;
        if ($totalIps > 254) {
            throw new Exception('Network too large for scan (max 254 IPs)');
        }

        for ($ipLong = $startLong; $ipLong <= $endLong; $ipLong++) {
            $ip = long2ip($ipLong);

            // Ping test (timeout 1 second)
            exec("ping -c 1 -W 1 $ip > /dev/null 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                $results['responding'][] = $ip;
            } else {
                $results['not_responding'][] = $ip;
            }
        }

        EventLogger::info('network_scanned', "Network scan completed: {$network['name']}", null, [
            'network_id' => $networkId,
            'responding_count' => count($results['responding']),
        ]);

        return $results;
    }

    /**
     * Get network statistics
     */
    public static function getStatistics(): array
    {
        $stats = Database::selectOne(
            'SELECT
                COUNT(*) as total_networks,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_networks
             FROM networks'
        );

        $allocations = Database::selectOne(
            'SELECT
                COUNT(*) as total_allocations,
                COUNT(DISTINCT network_id) as networks_with_allocations
             FROM ip_allocations
             WHERE released_at IS NULL'
        );

        return array_merge($stats, $allocations);
    }
}
