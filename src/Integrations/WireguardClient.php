<?php
/**
 * WireguardClient
 *
 * Integration client for Wireguard VPN management.
 */

class WireguardClient
{
    private string $configPath;
    private ?string $apiUrl;
    private ?string $apiKey;

    public function __construct(string $configPath = '/etc/wireguard', ?string $apiUrl = null, ?string $apiKey = null)
    {
        $this->configPath = rtrim($configPath, '/');
        $this->apiUrl = $apiUrl ? rtrim($apiUrl, '/') : null;
        $this->apiKey = $apiKey;
    }

    /**
     * Get interface status
     */
    public function getInterfaceStatus(string $interface = 'wg0'): array
    {
        exec("wg show $interface 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'interface' => $interface,
                'status' => 'down',
                'peers' => [],
            ];
        }

        return $this->parseWgOutput($output, $interface);
    }

    /**
     * Parse `wg show` output
     */
    private function parseWgOutput(array $output, string $interface): array
    {
        $result = [
            'interface' => $interface,
            'status' => 'up',
            'peers' => [],
        ];

        $currentPeer = null;

        foreach ($output as $line) {
            $line = trim($line);

            if (preg_match('/^peer: (.+)$/', $line, $matches)) {
                if ($currentPeer) {
                    $result['peers'][] = $currentPeer;
                }
                $currentPeer = ['public_key' => $matches[1]];
            } elseif ($currentPeer && preg_match('/^endpoint: (.+)$/', $line, $matches)) {
                $currentPeer['endpoint'] = $matches[1];
            } elseif ($currentPeer && preg_match('/^allowed ips: (.+)$/', $line, $matches)) {
                $currentPeer['allowed_ips'] = explode(', ', $matches[1]);
            } elseif ($currentPeer && preg_match('/^latest handshake: (.+)$/', $line, $matches)) {
                $currentPeer['latest_handshake'] = $matches[1];
            } elseif ($currentPeer && preg_match('/^transfer: (.+) received, (.+) sent$/', $line, $matches)) {
                $currentPeer['transfer'] = [
                    'received' => $matches[1],
                    'sent' => $matches[2],
                ];
            }
        }

        if ($currentPeer) {
            $result['peers'][] = $currentPeer;
        }

        $result['peer_count'] = count($result['peers']);

        return $result;
    }

    /**
     * Get all interfaces
     */
    public function getAllInterfaces(): array
    {
        exec("wg show interfaces 2>&1", $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [];
        }

        $interfaces = explode(' ', trim($output[0]));
        $result = [];

        foreach ($interfaces as $interface) {
            if (!empty($interface)) {
                $result[] = $this->getInterfaceStatus($interface);
            }
        }

        return $result;
    }

    /**
     * Get peer statistics
     */
    public function getPeerStats(): array
    {
        $interfaces = $this->getAllInterfaces();

        $totalPeers = 0;
        $activePeers = 0;

        foreach ($interfaces as $interface) {
            $totalPeers += $interface['peer_count'];

            foreach ($interface['peers'] as $peer) {
                if (isset($peer['latest_handshake']) && $peer['latest_handshake'] !== 'None') {
                    $activePeers++;
                }
            }
        }

        return [
            'total_interfaces' => count($interfaces),
            'total_peers' => $totalPeers,
            'active_peers' => $activePeers,
            'inactive_peers' => $totalPeers - $activePeers,
        ];
    }

    /**
     * Generate new peer configuration
     */
    public function generatePeerConfig(string $interface, string $peerName, string $allowedIps): array
    {
        // Generate private and public keys
        exec('wg genkey', $privateKey, $returnCode);
        if ($returnCode !== 0 || empty($privateKey)) {
            throw new Exception('Failed to generate private key');
        }

        $privateKey = trim($privateKey[0]);

        exec("echo '$privateKey' | wg pubkey", $publicKey, $returnCode);
        if ($returnCode !== 0 || empty($publicKey)) {
            throw new Exception('Failed to generate public key');
        }

        $publicKey = trim($publicKey[0]);

        // Generate preshared key
        exec('wg genpsk', $presharedKey, $returnCode);
        $presharedKey = ($returnCode === 0 && !empty($presharedKey)) ? trim($presharedKey[0]) : null;

        return [
            'peer_name' => $peerName,
            'interface' => $interface,
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'preshared_key' => $presharedKey,
            'allowed_ips' => $allowedIps,
        ];
    }

    /**
     * Add peer to interface
     */
    public function addPeer(string $interface, string $publicKey, string $allowedIps, ?string $presharedKey = null): bool
    {
        $cmd = "wg set $interface peer $publicKey allowed-ips $allowedIps";

        if ($presharedKey) {
            $cmd .= " preshared-key <(echo '$presharedKey')";
        }

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            // Save configuration
            exec("wg-quick save $interface 2>&1");
            return true;
        }

        return false;
    }

    /**
     * Remove peer from interface
     */
    public function removePeer(string $interface, string $publicKey): bool
    {
        exec("wg set $interface peer $publicKey remove 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            // Save configuration
            exec("wg-quick save $interface 2>&1");
            return true;
        }

        return false;
    }

    /**
     * Restart interface
     */
    public function restartInterface(string $interface): bool
    {
        exec("wg-quick down $interface && wg-quick up $interface 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Use API if available
     */
    private function requestApi(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->apiUrl || !$this->apiKey) {
            throw new Exception('API not configured');
        }

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
            throw new Exception("Wireguard API error: HTTP $httpCode");
        }

        return json_decode($response, true) ?? [];
    }
}
