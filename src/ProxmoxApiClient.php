<?php
/**
 * ProxmoxApiClient Class
 *
 * Generic client for Proxmox VE API communication.
 * Supports authentication via API tokens and provides methods for common operations.
 *
 * API Documentation: https://pve.proxmox.com/pve-docs/api-viewer/
 */

class ProxmoxApiClient
{
    private string $apiUrl;
    private string $tokenId;
    private string $tokenSecret;
    private bool $verifySsl;
    private int $timeout;

    /**
     * Constructor
     *
     * @param string $apiUrl Base API URL (e.g., https://pve.homelab.local:8006/api2/json)
     * @param string $tokenId API token ID (e.g., root@pam!api-token)
     * @param string $tokenSecret API token secret
     * @param bool $verifySsl Whether to verify SSL certificates
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(
        string $apiUrl,
        string $tokenId,
        string $tokenSecret,
        bool $verifySsl = false,
        int $timeout = 10
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->tokenId = $tokenId;
        $this->tokenSecret = $tokenSecret;
        $this->verifySsl = $verifySsl;
        $this->timeout = $timeout;
    }

    /**
     * Make an API request
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (e.g., /nodes)
     * @param array $data Request data
     * @return array Response data
     * @throws Exception on error
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');

        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
                break;

            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;

            case 'GET':
            default:
                if (!empty($data)) {
                    $url .= '?' . http_build_query($data);
                    curl_setopt($ch, CURLOPT_URL, $url);
                }
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL error: $error");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $decoded['errors'] ?? $decoded['message'] ?? "HTTP $httpCode error";
            throw new Exception("Proxmox API error: $errorMessage");
        }

        return $decoded['data'] ?? [];
    }

    /**
     * GET request
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * POST request
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * PUT request
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * DELETE request
     */
    public function delete(string $endpoint, array $data = []): array
    {
        return $this->request('DELETE', $endpoint, $data);
    }

    /**
     * Test connection to Proxmox API
     *
     * @return bool Success status
     */
    public function testConnection(): bool
    {
        try {
            $this->get('/version');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get Proxmox version information
     *
     * @return array Version data
     */
    public function getVersion(): array
    {
        return $this->get('/version');
    }

    /**
     * List all nodes in the cluster
     *
     * @return array Nodes list
     */
    public function getNodes(): array
    {
        return $this->get('/nodes');
    }

    /**
     * Get node status
     *
     * @param string $node Node name
     * @return array Node status
     */
    public function getNodeStatus(string $node): array
    {
        return $this->get("/nodes/$node/status");
    }

    /**
     * Get all resources (VMs, LXCs, storage, etc.)
     *
     * @param string|null $type Filter by type (vm, storage, node, etc.)
     * @return array Resources list
     */
    public function getResources(?string $type = null): array
    {
        $params = $type ? ['type' => $type] : [];
        return $this->get('/cluster/resources', $params);
    }

    /**
     * List all VMs and LXCs on a node
     *
     * @param string $node Node name
     * @return array List of VMs/LXCs
     */
    public function getVMs(string $node): array
    {
        $qemu = $this->get("/nodes/$node/qemu");
        $lxc = $this->get("/nodes/$node/lxc");

        // Merge and add type
        $vms = array_merge(
            array_map(fn($vm) => array_merge($vm, ['type' => 'qemu']), $qemu),
            array_map(fn($ct) => array_merge($ct, ['type' => 'lxc']), $lxc)
        );

        return $vms;
    }

    /**
     * Get VM/LXC status
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Status data
     */
    public function getVMStatus(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->get("/nodes/$node/$type/$vmid/status/current");
    }

    /**
     * Get VM/LXC configuration
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Configuration data
     */
    public function getVMConfig(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->get("/nodes/$node/$type/$vmid/config");
    }

    /**
     * Start a VM/LXC
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Response data
     */
    public function startVM(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->post("/nodes/$node/$type/$vmid/status/start");
    }

    /**
     * Stop a VM/LXC
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Response data
     */
    public function stopVM(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->post("/nodes/$node/$type/$vmid/status/stop");
    }

    /**
     * Shutdown a VM/LXC gracefully
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Response data
     */
    public function shutdownVM(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->post("/nodes/$node/$type/$vmid/status/shutdown");
    }

    /**
     * Reboot a VM/LXC
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Response data
     */
    public function rebootVM(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->post("/nodes/$node/$type/$vmid/status/reboot");
    }

    /**
     * Update VM/LXC configuration
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param array $config Configuration parameters
     * @return array Response data
     */
    public function updateVMConfig(string $node, int $vmid, string $type, array $config): array
    {
        return $this->put("/nodes/$node/$type/$vmid/config", $config);
    }

    /**
     * Update VM/LXC CPU cores
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param int $cores Number of cores
     * @return array Response data
     */
    public function updateVMCores(string $node, int $vmid, string $type, int $cores): array
    {
        return $this->updateVMConfig($node, $vmid, $type, ['cores' => $cores]);
    }

    /**
     * Update VM/LXC memory
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param int $memory Memory in MB
     * @return array Response data
     */
    public function updateVMMemory(string $node, int $vmid, string $type, int $memory): array
    {
        return $this->updateVMConfig($node, $vmid, $type, ['memory' => $memory]);
    }

    /**
     * Create a snapshot
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param string $snapname Snapshot name
     * @param string|null $description Description
     * @return array Response data
     */
    public function createSnapshot(string $node, int $vmid, string $type, string $snapname, ?string $description = null): array
    {
        $data = ['snapname' => $snapname];
        if ($description) {
            $data['description'] = $description;
        }
        return $this->post("/nodes/$node/$type/$vmid/snapshot", $data);
    }

    /**
     * List snapshots
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @return array Snapshots list
     */
    public function listSnapshots(string $node, int $vmid, string $type = 'qemu'): array
    {
        return $this->get("/nodes/$node/$type/$vmid/snapshot");
    }

    /**
     * Delete a snapshot
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param string $snapname Snapshot name
     * @return array Response data
     */
    public function deleteSnapshot(string $node, int $vmid, string $type, string $snapname): array
    {
        return $this->delete("/nodes/$node/$type/$vmid/snapshot/$snapname");
    }

    /**
     * Create a backup
     *
     * @param string $node Node name
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param array $options Backup options
     * @return array Response data
     */
    public function createBackup(string $node, int $vmid, string $type, array $options = []): array
    {
        $data = array_merge(['vmid' => $vmid], $options);
        return $this->post("/nodes/$node/vzdump", $data);
    }

    /**
     * Get node tasks
     *
     * @param string $node Node name
     * @param array $filters Optional filters
     * @return array Tasks list
     */
    public function getNodeTasks(string $node, array $filters = []): array
    {
        return $this->get("/nodes/$node/tasks", $filters);
    }

    /**
     * Get task status
     *
     * @param string $node Node name
     * @param string $upid Task UPID
     * @return array Task status
     */
    public function getTaskStatus(string $node, string $upid): array
    {
        return $this->get("/nodes/$node/tasks/$upid/status");
    }
}
