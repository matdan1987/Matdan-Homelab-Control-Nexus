<?php
/**
 * AdGuardHomeClient
 *
 * Client for AdGuard Home API integration.
 * Manages DNS rewrites, blocklists, and query statistics.
 */

class AdGuardHomeClient
{
    private string $apiUrl;
    private string $username;
    private string $password;
    private int $timeout;

    /**
     * Constructor
     */
    public function __construct(string $apiUrl, string $username, string $password)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout = 10;
    }

    /**
     * Make API request with Basic Auth
     */
    private function request(string $method, string $endpoint, array $data = null): array
    {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('AdGuard API request failed');
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception('AdGuard API error: HTTP ' . $httpCode);
        }

        return $decoded ?? [];
    }

    /**
     * Get status
     */
    public function getStatus(): array
    {
        return $this->request('GET', '/control/status');
    }

    /**
     * Get DNS rewrites
     */
    public function getRewriteList(): array
    {
        return $this->request('GET', '/control/rewrite/list');
    }

    /**
     * Add DNS rewrite
     */
    public function addRewrite(string $domain, string $answer): array
    {
        return $this->request('POST', '/control/rewrite/add', [
            'domain' => $domain,
            'answer' => $answer,
        ]);
    }

    /**
     * Delete DNS rewrite
     */
    public function deleteRewrite(string $domain, string $answer): array
    {
        return $this->request('POST', '/control/rewrite/delete', [
            'domain' => $domain,
            'answer' => $answer,
        ]);
    }

    /**
     * Get query statistics
     */
    public function getStats(): array
    {
        return $this->request('GET', '/control/stats');
    }

    /**
     * Get top queried domains
     */
    public function getTopQueriedDomains(): array
    {
        $stats = $this->getStats();
        return $stats['top_queried_domains'] ?? [];
    }

    /**
     * Get top blocked domains
     */
    public function getTopBlockedDomains(): array
    {
        $stats = $this->getStats();
        return $stats['top_blocked_domains'] ?? [];
    }

    /**
     * Get filtering status
     */
    public function getFilteringStatus(): array
    {
        return $this->request('GET', '/control/filtering/status');
    }

    /**
     * Get clients
     */
    public function getClients(): array
    {
        $status = $this->getStatus();
        return $status['clients'] ?? [];
    }

    /**
     * Sync DNS records for services
     */
    public static function syncDNSRecordsForServices(int $integrationId): array
    {
        $integration = Database::selectOne(
            'SELECT * FROM integrations WHERE id = ? AND type = ?',
            [$integrationId, 'adguard_home']
        );

        if (!$integration) {
            return [];
        }

        $config = json_decode($integration['config_json'], true);
        $client = new self(
            $integration['api_url'],
            $config['username'] ?? '',
            $integration['api_secret'] ?? ''
        );

        try {
            $rewrites = $client->getRewriteList();

            // Store rewrites in mappings
            $mappings = [];
            foreach ($rewrites as $rewrite) {
                $mappings[] = [
                    'integration_id' => $integrationId,
                    'resource_type' => 'dns_rewrite',
                    'resource_id' => $rewrite['domain'],
                    'resource_data' => json_encode($rewrite),
                ];
            }

            EventLogger::info('integration_sync',
                'Synced ' . count($rewrites) . ' DNS rewrites from AdGuard Home',
                null,
                ['integration_id' => $integrationId]
            );

            return $mappings;
        } catch (Exception $e) {
            EventLogger::error('integration_error',
                'Failed to sync AdGuard: ' . $e->getMessage(),
                null,
                ['integration_id' => $integrationId]
            );
            return [];
        }
    }

    /**
     * Auto-create DNS records for services
     */
    public function createDNSRecordsForService(int $serviceId, string $domain): bool
    {
        try {
            $service = ServicesRepository::getDetails($serviceId);

            foreach ($service['instances'] as $instance) {
                // Would need IP address from Proxmox
                // For now, just create a placeholder
                $this->addRewrite(
                    $domain,
                    '192.168.1.100' // This should be actual VM IP
                );
            }

            return true;
        } catch (Exception $e) {
            EventLogger::error('integration_error',
                'Failed to create DNS records: ' . $e->getMessage(),
                null,
                ['service_id' => $serviceId]
            );
            return false;
        }
    }
}
