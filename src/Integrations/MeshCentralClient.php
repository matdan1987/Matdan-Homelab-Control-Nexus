<?php
/**
 * MeshCentralClient
 *
 * Integration client for MeshCentral remote access platform.
 */

class MeshCentralClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private ?string $loginToken = null;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Authenticate and get login token
     */
    private function authenticate(): void
    {
        if ($this->loginToken) {
            return;
        }

        $response = $this->request('POST', '/control/login', [
            'username' => $this->username,
            'password' => $this->password,
        ], false);

        if (isset($response['token'])) {
            $this->loginToken = $response['token'];
        } else {
            throw new Exception('MeshCentral authentication failed');
        }
    }

    /**
     * Get all devices
     */
    public function getDevices(): array
    {
        $this->authenticate();
        return $this->request('GET', '/control/devices');
    }

    /**
     * Get device by ID
     */
    public function getDevice(string $deviceId): array
    {
        $this->authenticate();
        return $this->request('GET', "/control/devices/$deviceId");
    }

    /**
     * Send command to device
     */
    public function sendCommand(string $deviceId, string $command): array
    {
        $this->authenticate();
        return $this->request('POST', "/control/devices/$deviceId/command", [
            'command' => $command,
        ]);
    }

    /**
     * Get device power state
     */
    public function getPowerState(string $deviceId): string
    {
        $device = $this->getDevice($deviceId);
        return $device['power'] ?? 'unknown';
    }

    /**
     * Power on device (Wake-on-LAN)
     */
    public function powerOn(string $deviceId): bool
    {
        $this->authenticate();
        $result = $this->request('POST', "/control/devices/$deviceId/power", [
            'action' => 'wake',
        ]);

        return isset($result['success']) && $result['success'];
    }

    /**
     * Get device groups
     */
    public function getGroups(): array
    {
        $this->authenticate();
        return $this->request('GET', '/control/groups');
    }

    /**
     * Get online devices count
     */
    public function getOnlineCount(): int
    {
        $devices = $this->getDevices();
        return count(array_filter($devices, fn($d) => ($d['conn'] ?? 0) > 0));
    }

    /**
     * Make API request
     */
    private function request(string $method, string $endpoint, array $data = [], bool $authenticated = true): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = ['Content-Type: application/json'];

        if ($authenticated && $this->loginToken) {
            $headers[] = 'Authorization: Bearer ' . $this->loginToken;
        }

        if (!empty($data)) {
            if ($method === 'GET') {
                $url .= '?' . http_build_query($data);
                curl_setopt($ch, CURLOPT_URL, $url);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("MeshCentral API error: HTTP $httpCode");
        }

        return json_decode($response, true) ?? [];
    }
}
