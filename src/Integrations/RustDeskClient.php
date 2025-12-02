<?php
/**
 * RustDeskClient
 *
 * Integration client for RustDesk remote desktop platform.
 */

class RustDeskClient
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Get all peers (devices)
     */
    public function getPeers(): array
    {
        return $this->request('GET', '/api/peers');
    }

    /**
     * Get peer by ID
     */
    public function getPeer(string $peerId): array
    {
        return $this->request('GET', "/api/peers/$peerId");
    }

    /**
     * Get peer connection status
     */
    public function getPeerStatus(string $peerId): string
    {
        $peer = $this->getPeer($peerId);
        return $peer['status'] ?? 'offline';
    }

    /**
     * Get online peers
     */
    public function getOnlinePeers(): array
    {
        $peers = $this->getPeers();
        return array_filter($peers, fn($p) => ($p['status'] ?? '') === 'online');
    }

    /**
     * Add peer
     */
    public function addPeer(string $peerId, string $alias, ?string $username = null): array
    {
        return $this->request('POST', '/api/peers', [
            'id' => $peerId,
            'alias' => $alias,
            'username' => $username,
        ]);
    }

    /**
     * Update peer
     */
    public function updatePeer(string $peerId, array $data): array
    {
        return $this->request('PUT', "/api/peers/$peerId", $data);
    }

    /**
     * Delete peer
     */
    public function deletePeer(string $peerId): bool
    {
        $result = $this->request('DELETE', "/api/peers/$peerId");
        return isset($result['success']) && $result['success'];
    }

    /**
     * Get connection logs
     */
    public function getConnectionLogs(?string $peerId = null, ?int $limit = 100): array
    {
        $params = ['limit' => $limit];
        if ($peerId) {
            $params['peer_id'] = $peerId;
        }

        return $this->request('GET', '/api/logs/connections', $params);
    }

    /**
     * Get server statistics
     */
    public function getStatistics(): array
    {
        return $this->request('GET', '/api/statistics');
    }

    /**
     * Generate access token for peer
     */
    public function generateAccessToken(string $peerId, int $expiresIn = 3600): array
    {
        return $this->request('POST', "/api/peers/$peerId/token", [
            'expires_in' => $expiresIn,
        ]);
    }

    /**
     * Make API request
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->apiUrl . $endpoint;

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        if ($method !== 'GET' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("RustDesk API error: HTTP $httpCode");
        }

        return json_decode($response, true) ?? [];
    }
}
