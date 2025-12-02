<?php
/**
 * ProvisioningEngine
 *
 * Complete VM provisioning system with template support,
 * automation, and resource validation.
 */

class ProvisioningEngine
{
    /**
     * Provision a new VM from template
     *
     * @param int $templateId Template to use
     * @param array $config VM configuration overrides
     * @param int|null $teamId Team to assign VM to
     * @param int|null $serviceId Service to link VM to
     * @return int Provisioning job ID
     */
    public static function provisionFromTemplate(int $templateId, array $config, ?int $teamId = null, ?int $serviceId = null): int
    {
        // Get template
        $template = Database::selectOne(
            'SELECT * FROM vm_templates WHERE id = ? AND active = 1',
            [$templateId]
        );

        if (!$template) {
            throw new Exception('Template not found or inactive');
        }

        $templateConfig = json_decode($template['config_json'], true);

        // Merge template config with overrides
        $finalConfig = array_merge($templateConfig, $config);

        // Validate configuration
        self::validateConfig($finalConfig);

        // Check team quotas if assigned to team
        if ($teamId) {
            self::checkTeamQuota($teamId, $finalConfig);
        }

        // Select best node for provisioning
        $targetNode = self::selectTargetNode($finalConfig);

        if (!$targetNode) {
            throw new Exception('No suitable node found for provisioning');
        }

        // Create provisioning job
        $jobId = Database::insert(
            'INSERT INTO provisioning_jobs (template_id, pve_node_id, team_id, service_id, config_json, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $templateId,
                $targetNode['id'],
                $teamId,
                $serviceId,
                json_encode($finalConfig),
                'pending'
            ]
        );

        EventLogger::info('provisioning_job_created', "Provisioning job created from template: {$template['name']}", null, [
            'job_id' => $jobId,
            'template_id' => $templateId,
            'node_id' => $targetNode['id'],
        ]);

        // Execute provisioning in background
        self::executeProvisioning($jobId);

        return $jobId;
    }

    /**
     * Provision a new VM with custom configuration
     */
    public static function provisionCustom(array $config, int $pveNodeId, ?int $teamId = null, ?int $serviceId = null): int
    {
        // Validate configuration
        self::validateConfig($config);

        // Check team quotas if assigned to team
        if ($teamId) {
            self::checkTeamQuota($teamId, $config);
        }

        // Create provisioning job
        $jobId = Database::insert(
            'INSERT INTO provisioning_jobs (pve_node_id, team_id, service_id, config_json, status)
             VALUES (?, ?, ?, ?, ?)',
            [
                $pveNodeId,
                $teamId,
                $serviceId,
                json_encode($config),
                'pending'
            ]
        );

        EventLogger::info('provisioning_job_created', 'Custom VM provisioning job created', null, [
            'job_id' => $jobId,
            'node_id' => $pveNodeId,
        ]);

        // Execute provisioning in background
        self::executeProvisioning($jobId);

        return $jobId;
    }

    /**
     * Execute provisioning job
     */
    private static function executeProvisioning(int $jobId): void
    {
        // Update job status
        Database::update(
            'UPDATE provisioning_jobs SET status = ?, started_at = NOW() WHERE id = ?',
            ['in_progress', $jobId]
        );

        try {
            // Get job details
            $job = Database::selectOne(
                'SELECT * FROM provisioning_jobs WHERE id = ?',
                [$jobId]
            );

            $config = json_decode($job['config_json'], true);

            // Get node details
            $node = Database::selectOne(
                'SELECT * FROM pve_nodes WHERE id = ?',
                [$job['pve_node_id']]
            );

            // Connect to Proxmox
            $pve = new ProxmoxApiClient($node['hostname'], $node['api_token']);

            // Get next available VMID
            $vmid = self::getNextVmid($pve, $node['name']);

            // Prepare VM configuration
            $vmConfig = [
                'vmid' => $vmid,
                'name' => $config['name'] ?? "vm-$vmid",
                'cores' => $config['cpu_cores'] ?? 2,
                'memory' => ($config['memory_gb'] ?? 4) * 1024, // Convert to MB
                'ostype' => $config['os_type'] ?? 'l26',
                'net0' => 'virtio,bridge=' . ($config['network_bridge'] ?? 'vmbr0'),
            ];

            // Add storage configuration
            if (isset($config['storage_gb'])) {
                $storagePool = $config['storage_pool'] ?? 'local-lvm';
                $vmConfig['scsi0'] = "$storagePool:{$config['storage_gb']}";
            }

            // Add CD/DVD ISO if specified
            if (isset($config['iso_image'])) {
                $vmConfig['ide2'] = $config['iso_image'] . ',media=cdrom';
            }

            // Clone from template if specified
            if (isset($config['clone_from_vmid'])) {
                $result = $pve->request(
                    "POST",
                    "/nodes/{$node['name']}/qemu/{$config['clone_from_vmid']}/clone",
                    [
                        'newid' => $vmid,
                        'name' => $vmConfig['name'],
                        'full' => 1,
                    ]
                );
            } else {
                // Create new VM
                $result = $pve->request(
                    "POST",
                    "/nodes/{$node['name']}/qemu",
                    $vmConfig
                );
            }

            // Apply cloud-init configuration if specified
            if (isset($config['cloud_init'])) {
                self::applyCloudInit($pve, $node['name'], $vmid, $config['cloud_init']);
            }

            // Start VM if auto_start is enabled
            if ($config['auto_start'] ?? false) {
                $pve->startVM($node['name'], $vmid, 'qemu');
            }

            // Register VM in team resources
            if ($job['team_id']) {
                Database::insert(
                    'INSERT INTO team_resources (team_id, pve_node_id, vmid, allocated_at)
                     VALUES (?, ?, ?, NOW())',
                    [$job['team_id'], $job['pve_node_id'], $vmid]
                );
            }

            // Register VM in service instances
            if ($job['service_id']) {
                Database::insert(
                    'INSERT INTO service_instances (service_id, pve_node_id, vmid, role)
                     VALUES (?, ?, ?, ?)',
                    [$job['service_id'], $job['pve_node_id'], $vmid, $config['service_role'] ?? 'worker']
                );
            }

            // Allocate IP if IPAM is configured
            if (isset($config['network_id'])) {
                self::allocateIpAddress($vmid, $job['pve_node_id'], $config['network_id']);
            }

            // Update job as completed
            Database::update(
                'UPDATE provisioning_jobs SET status = ?, vmid = ?, completed_at = NOW(), result_json = ?
                 WHERE id = ?',
                ['completed', $vmid, json_encode(['vmid' => $vmid, 'success' => true]), $jobId]
            );

            EventLogger::info('provisioning_completed', "VM provisioned successfully: VMID $vmid", null, [
                'job_id' => $jobId,
                'vmid' => $vmid,
            ]);

            // Send notification
            NotificationManager::success("VM provisioned successfully: {$vmConfig['name']} (VMID: $vmid)", [
                'job_id' => $jobId,
                'vmid' => $vmid,
                'node' => $node['name'],
            ]);

        } catch (Exception $e) {
            // Update job as failed
            Database::update(
                'UPDATE provisioning_jobs SET status = ?, error_message = ?, completed_at = NOW()
                 WHERE id = ?',
                ['failed', $e->getMessage(), $jobId]
            );

            EventLogger::error('provisioning_failed', "VM provisioning failed: " . $e->getMessage(), null, [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            // Send notification
            NotificationManager::alert('policy_failed', "VM provisioning failed: " . $e->getMessage(), [
                'job_id' => $jobId,
            ]);
        }
    }

    /**
     * Validate VM configuration
     */
    private static function validateConfig(array $config): void
    {
        $required = ['name'];

        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Validate resource limits
        if (isset($config['cpu_cores']) && ($config['cpu_cores'] < 1 || $config['cpu_cores'] > 128)) {
            throw new Exception('Invalid CPU cores count (must be 1-128)');
        }

        if (isset($config['memory_gb']) && ($config['memory_gb'] < 0.5 || $config['memory_gb'] > 1024)) {
            throw new Exception('Invalid memory size (must be 0.5-1024 GB)');
        }

        if (isset($config['storage_gb']) && ($config['storage_gb'] < 1 || $config['storage_gb'] > 10240)) {
            throw new Exception('Invalid storage size (must be 1-10240 GB)');
        }
    }

    /**
     * Check team quota before provisioning
     */
    private static function checkTeamQuota(int $teamId, array $config): void
    {
        $team = Database::selectOne(
            'SELECT * FROM teams WHERE id = ?',
            [$teamId]
        );

        if (!$team) {
            throw new Exception('Team not found');
        }

        // Get current resource usage
        $usage = Database::selectOne(
            'SELECT
                COUNT(*) as vm_count,
                COALESCE(SUM(rus.cpu_cores), 0) as total_cpu,
                COALESCE(SUM(rus.memory_gb), 0) as total_memory,
                COALESCE(SUM(rus.storage_gb), 0) as total_storage
             FROM team_resources tr
             LEFT JOIN resource_usage_snapshots rus ON
                rus.pve_node_id = tr.pve_node_id AND rus.vmid = tr.vmid
                AND rus.id = (
                    SELECT id FROM resource_usage_snapshots rus2
                    WHERE rus2.pve_node_id = tr.pve_node_id AND rus2.vmid = tr.vmid
                    ORDER BY snapshot_at DESC LIMIT 1
                )
             WHERE tr.team_id = ?',
            [$teamId]
        );

        $requestedCpu = $config['cpu_cores'] ?? 0;
        $requestedMemory = $config['memory_gb'] ?? 0;
        $requestedStorage = $config['storage_gb'] ?? 0;

        // Check VM count quota
        if ($team['max_vms'] && ($usage['vm_count'] + 1) > $team['max_vms']) {
            throw new Exception("Team quota exceeded: Maximum VMs ({$team['max_vms']}) reached");
        }

        // Check CPU quota
        if ($team['max_cpu_cores'] && ($usage['total_cpu'] + $requestedCpu) > $team['max_cpu_cores']) {
            throw new Exception("Team quota exceeded: CPU cores limit ({$team['max_cpu_cores']}) would be exceeded");
        }

        // Check memory quota
        if ($team['max_memory_gb'] && ($usage['total_memory'] + $requestedMemory) > $team['max_memory_gb']) {
            throw new Exception("Team quota exceeded: Memory limit ({$team['max_memory_gb']} GB) would be exceeded");
        }

        // Check storage quota
        if ($team['max_storage_gb'] && ($usage['total_storage'] + $requestedStorage) > $team['max_storage_gb']) {
            throw new Exception("Team quota exceeded: Storage limit ({$team['max_storage_gb']} GB) would be exceeded");
        }
    }

    /**
     * Select best node for provisioning based on available resources
     */
    private static function selectTargetNode(array $config): ?array
    {
        $nodes = Database::select(
            'SELECT * FROM pve_nodes WHERE active = 1 AND online = 1 ORDER BY name'
        );

        $requiredCpu = $config['cpu_cores'] ?? 2;
        $requiredMemory = ($config['memory_gb'] ?? 4) * 1073741824; // Convert to bytes
        $requiredStorage = ($config['storage_gb'] ?? 32) * 1073741824; // Convert to bytes

        $bestNode = null;
        $bestScore = -1;

        foreach ($nodes as $node) {
            $resources = Database::selectOne(
                'SELECT
                    SUM(maxcpu) as used_cpu,
                    SUM(maxmem) as used_mem,
                    SUM(maxdisk) as used_disk
                 FROM pve_resources_cache
                 WHERE pve_node_id = ? AND status = "running"',
                [$node['id']]
            );

            // Simple availability check (would need actual node capacity from Proxmox API)
            $availableCpu = 64 - ($resources['used_cpu'] ?? 0); // Assuming 64 cores
            $availableMemory = 536870912000 - ($resources['used_mem'] ?? 0); // Assuming 500GB RAM
            $availableStorage = 10737418240000 - ($resources['used_disk'] ?? 0); // Assuming 10TB storage

            // Check if node can accommodate the request
            if ($availableCpu >= $requiredCpu &&
                $availableMemory >= $requiredMemory &&
                $availableStorage >= $requiredStorage) {

                // Calculate score (prefer nodes with more available resources)
                $score = $availableCpu + ($availableMemory / 1073741824) + ($availableStorage / 1073741824);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestNode = $node;
                }
            }
        }

        return $bestNode;
    }

    /**
     * Get next available VMID
     */
    private static function getNextVmid(ProxmoxApiClient $pve, string $nodeName): int
    {
        $vms = $pve->getVMs($nodeName);

        $usedVmids = array_column($vms, 'vmid');
        $maxVmid = !empty($usedVmids) ? max($usedVmids) : 100;

        // Find next available VMID starting from max+1
        $nextVmid = $maxVmid + 1;

        // Make sure it's not in use
        while (in_array($nextVmid, $usedVmids)) {
            $nextVmid++;
        }

        return $nextVmid;
    }

    /**
     * Apply cloud-init configuration to VM
     */
    private static function applyCloudInit(ProxmoxApiClient $pve, string $nodeName, int $vmid, array $cloudInit): void
    {
        $config = [];

        if (isset($cloudInit['user'])) {
            $config['ciuser'] = $cloudInit['user'];
        }

        if (isset($cloudInit['password'])) {
            $config['cipassword'] = $cloudInit['password'];
        }

        if (isset($cloudInit['ssh_keys'])) {
            $config['sshkeys'] = urlencode($cloudInit['ssh_keys']);
        }

        if (isset($cloudInit['ip_config'])) {
            $config['ipconfig0'] = $cloudInit['ip_config'];
        }

        if (!empty($config)) {
            $pve->updateVMConfig($nodeName, $vmid, 'qemu', $config);
        }
    }

    /**
     * Allocate IP address from network pool
     */
    private static function allocateIpAddress(int $vmid, int $pveNodeId, int $networkId): ?string
    {
        // Get network
        $network = Database::selectOne(
            'SELECT * FROM networks WHERE id = ?',
            [$networkId]
        );

        if (!$network) {
            return null;
        }

        // Find next available IP
        $startIp = ip2long($network['ip_range_start']);
        $endIp = ip2long($network['ip_range_end']);

        $allocatedIps = Database::select(
            'SELECT ip_address FROM ip_allocations WHERE network_id = ? AND released_at IS NULL',
            [$networkId]
        );

        $allocatedIpNumbers = array_map(fn($row) => ip2long($row['ip_address']), $allocatedIps);

        // Find first available IP
        for ($ip = $startIp; $ip <= $endIp; $ip++) {
            if (!in_array($ip, $allocatedIpNumbers)) {
                $ipAddress = long2ip($ip);

                // Allocate IP
                Database::insert(
                    'INSERT INTO ip_allocations (network_id, ip_address, pve_node_id, vmid, allocated_at)
                     VALUES (?, ?, ?, ?, NOW())',
                    [$networkId, $ipAddress, $pveNodeId, $vmid]
                );

                EventLogger::info('ip_allocated', "IP allocated: $ipAddress for VMID $vmid", null, [
                    'ip' => $ipAddress,
                    'vmid' => $vmid,
                    'network_id' => $networkId,
                ]);

                return $ipAddress;
            }
        }

        throw new Exception('No available IP addresses in network');
    }

    /**
     * Get provisioning job status
     */
    public static function getJobStatus(int $jobId): array
    {
        $job = Database::selectOne(
            'SELECT pj.*, t.name as template_name, pn.name as node_name, tm.name as team_name
             FROM provisioning_jobs pj
             LEFT JOIN vm_templates t ON t.id = pj.template_id
             LEFT JOIN pve_nodes pn ON pn.id = pj.pve_node_id
             LEFT JOIN teams tm ON tm.id = pj.team_id
             WHERE pj.id = ?',
            [$jobId]
        );

        if (!$job) {
            throw new Exception('Provisioning job not found');
        }

        $job['config'] = json_decode($job['config_json'], true);
        $job['result'] = $job['result_json'] ? json_decode($job['result_json'], true) : null;

        unset($job['config_json'], $job['result_json']);

        return $job;
    }

    /**
     * Deprovision (delete) a VM
     */
    public static function deprovision(int $pveNodeId, int $vmid, bool $deleteStorage = true): bool
    {
        // Get node details
        $node = Database::selectOne(
            'SELECT * FROM pve_nodes WHERE id = ?',
            [$pveNodeId]
        );

        if (!$node) {
            throw new Exception('Node not found');
        }

        // Connect to Proxmox
        $pve = new ProxmoxApiClient($node['hostname'], $node['api_token']);

        // Get VM type
        $vmType = 'qemu'; // Default to qemu

        // Stop VM if running
        try {
            $pve->stopVM($node['name'], $vmid, $vmType);
            sleep(5); // Wait for shutdown
        } catch (Exception $e) {
            // VM might already be stopped
        }

        // Delete VM
        $purge = $deleteStorage ? 1 : 0;
        $pve->request("DELETE", "/nodes/{$node['name']}/$vmType/$vmid", ['purge' => $purge]);

        // Release IP allocation
        Database::update(
            'UPDATE ip_allocations SET released_at = NOW()
             WHERE pve_node_id = ? AND vmid = ? AND released_at IS NULL',
            [$pveNodeId, $vmid]
        );

        // Remove from team resources
        Database::delete(
            'DELETE FROM team_resources WHERE pve_node_id = ? AND vmid = ?',
            [$pveNodeId, $vmid]
        );

        // Remove from service instances
        Database::delete(
            'DELETE FROM service_instances WHERE pve_node_id = ? AND vmid = ?',
            [$pveNodeId, $vmid]
        );

        EventLogger::info('vm_deprovisioned', "VM deprovisioned: VMID $vmid on node {$node['name']}", null, [
            'vmid' => $vmid,
            'node_id' => $pveNodeId,
        ]);

        return true;
    }
}
