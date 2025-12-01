<?php
/**
 * NginxProxyManagerClient
 *
 * Client for Nginx Proxy Manager API integration.
 * Manages proxy hosts, SSL certificates, and custom locations.
 */

class NginxProxyManagerClient
{
    private string $apiUrl;
    private string $apiToken;
    private int $timeout;

    /**
     * Constructor
     *
     * @param string $apiUrl Base API URL (e.g., http://npm.local/api)
     * @param string $email Email for authentication
     * @param string $password Password for authentication
     */
    public function __construct(string $apiUrl, string $email = '', string $password = '')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->timeout = 10;

        // Authenticate and get token
        if ($email && $password) {
            $this->apiToken = $this->authenticate($email, $password);
        }
    }

    /**
     * Set API token directly
     */
    public function setToken(string $token): void
    {
        $this->apiToken = $token;
    }

    /**
     * Authenticate and get access token
     */
    private function authenticate(string $email, string $password): string
    {
        $response = $this->request('POST', '/tokens', [
            'identity' => $email,
            'secret' => $password,
        ], false);

        return $response['token'] ?? '';
    }

    /**
     * Make API request
     */
    private function request(string $method, string $endpoint, array $data = [], bool $useAuth = true): array
    {
        $url = $this->apiUrl . $endpoint;

        $headers = ['Content-Type: application/json'];

        if ($useAuth && $this->apiToken) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('NPM API request failed');
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception('NPM API error: ' . ($decoded['message'] ?? "HTTP $httpCode"));
        }

        return $decoded ?? [];
    }

    /**
     * Get all proxy hosts
     */
    public function getProxyHosts(): array
    {
        return $this->request('GET', '/nginx/proxy-hosts');
    }

    /**
     * Get proxy host by ID
     */
    public function getProxyHost(int $id): array
    {
        return $this->request('GET', "/nginx/proxy-hosts/$id");
    }

    /**
     * Create proxy host
     */
    public function createProxyHost(array $data): array
    {
        return $this->request('POST', '/nginx/proxy-hosts', $data);
    }

    /**
     * Update proxy host
     */
    public function updateProxyHost(int $id, array $data): array
    {
        return $this->request('PUT', "/nginx/proxy-hosts/$id", $data);
    }

    /**
     * Delete proxy host
     */
    public function deleteProxyHost(int $id): array
    {
        return $this->request('DELETE', "/nginx/proxy-hosts/$id");
    }

    /**
     * Get SSL certificates
     */
    public function getCertificates(): array
    {
        return $this->request('GET', '/nginx/certificates');
    }

    /**
     * Get access lists
     */
    public function getAccessLists(): array
    {
        return $this->request('GET', '/nginx/access-lists');
    }

    /**
     * Find proxy hosts by IP address
     */
    public function findHostsByIP(string $ip): array
    {
        $allHosts = $this->getProxyHosts();

        return array_filter($allHosts, function($host) use ($ip) {
            return isset($host['forward_host']) && $host['forward_host'] === $ip;
        });
    }

    /**
     * Map VMs to proxy hosts
     */
    public static function mapVMsToProxyHosts(int $integrationId): array
    {
        $integration = Database::selectOne(
            'SELECT * FROM integrations WHERE id = ? AND type = ?',
            [$integrationId, 'nginx_proxy_manager']
        );

        if (!$integration) {
            return [];
        }

        $config = json_decode($integration['config_json'], true);
        $client = new self(
            $integration['api_url'],
            $config['email'] ?? '',
            $integration['api_secret'] ?? ''
        );

        try {
            $proxyHosts = $client->getProxyHosts();
            $vms = Database::select('SELECT * FROM pve_resources_cache');

            $mappings = [];

            foreach ($vms as $vm) {
                // Try to match by IP or name
                foreach ($proxyHosts as $host) {
                    // This would need actual IP lookup from Proxmox
                    // For now, we'll store the relationship
                    if (isset($host['forward_host'])) {
                        $mappings[] = [
                            'integration_id' => $integrationId,
                            'pve_node_id' => $vm['pve_node_id'],
                            'vmid' => $vm['vmid'],
                            'resource_type' => 'proxy_host',
                            'resource_id' => $host['id'],
                            'resource_data' => json_encode($host),
                        ];
                    }
                }
            }

            return $mappings;
        } catch (Exception $e) {
            EventLogger::error('integration_error',
                'Failed to sync NPM: ' . $e->getMessage(),
                null,
                ['integration_id' => $integrationId]
            );
            return [];
        }
    }
}
