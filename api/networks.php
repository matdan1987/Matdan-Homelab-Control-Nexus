<?php
/**
 * Networks API Endpoint
 *
 * Handles network management and IP address allocation (IPAM).
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/NetworkManager.php';

// Require authentication
Auth::require();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            // Create network (admin only)
            Auth::require('admin');

            $name = getParam('name', true);
            $subnet = getParam('subnet', true);
            $gateway = getParam('gateway', true);

            $options = [];
            $optionalFields = ['vlan_id', 'dns_servers', 'domain', 'description'];
            foreach ($optionalFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    if ($field === 'dns_servers' && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $options[$field] = $value;
                }
            }

            $networkId = NetworkManager::createNetwork($name, $subnet, $gateway, $options);

            jsonResponse([
                'id' => $networkId,
                'message' => 'Network created successfully'
            ]);
            break;

        case 'update':
            // Update network (admin only)
            Auth::require('admin');

            $networkId = getParam('id', true);

            $updates = [];
            $allowedFields = ['name', 'gateway', 'vlan_id', 'dns_servers', 'domain', 'description', 'active'];

            foreach ($allowedFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    if ($field === 'dns_servers' && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $updates[$field] = $value;
                }
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            NetworkManager::updateNetwork($networkId, $updates);

            jsonResponse(['message' => 'Network updated successfully']);
            break;

        case 'delete':
            // Delete network (admin only)
            Auth::require('admin');

            $networkId = getParam('id', true);

            NetworkManager::deleteNetwork($networkId);

            jsonResponse(['message' => 'Network deleted successfully']);
            break;

        case 'get':
            // Get network details
            $networkId = getParam('id', true);

            $network = NetworkManager::getNetwork($networkId);

            jsonResponse($network);
            break;

        case 'list':
            // List all networks
            $activeOnly = getParam('active_only', false) !== '0';

            $networks = NetworkManager::getAllNetworks($activeOnly);

            jsonResponse($networks);
            break;

        case 'allocate_ip':
            // Allocate IP address (operator or admin)
            Auth::require('operator');

            $networkId = getParam('network_id', true);
            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);
            $requestedIp = getParam('ip_address', false);

            $ipAddress = NetworkManager::allocateIp($networkId, $pveNodeId, $vmid, $requestedIp);

            jsonResponse([
                'ip_address' => $ipAddress,
                'message' => 'IP allocated successfully'
            ]);
            break;

        case 'release_ip':
            // Release IP address (operator or admin)
            Auth::require('operator');

            $networkId = getParam('network_id', true);
            $ipAddress = getParam('ip_address', true);

            NetworkManager::releaseIp($networkId, $ipAddress);

            jsonResponse(['message' => 'IP released successfully']);
            break;

        case 'release_vm_ips':
            // Release all IPs for a VM (operator or admin)
            Auth::require('operator');

            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);

            $count = NetworkManager::releaseVmIps($pveNodeId, $vmid);

            jsonResponse([
                'released_count' => $count,
                'message' => "$count IP(s) released successfully"
            ]);
            break;

        case 'reserve_ip':
            // Reserve IP manually (admin only)
            Auth::require('admin');

            $networkId = getParam('network_id', true);
            $ipAddress = getParam('ip_address', true);
            $description = getParam('description', true);

            NetworkManager::reserveIp($networkId, $ipAddress, $description);

            jsonResponse(['message' => 'IP reserved successfully']);
            break;

        case 'allocations':
            // Get IP allocations for network
            $networkId = getParam('network_id', true);
            $activeOnly = getParam('active_only', false) !== '0';

            $allocations = NetworkManager::getNetworkAllocations($networkId, $activeOnly);

            jsonResponse($allocations);
            break;

        case 'vm_ips':
            // Get IP allocations for VM
            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);

            $allocations = NetworkManager::getVmAllocations($pveNodeId, $vmid);

            jsonResponse($allocations);
            break;

        case 'scan':
            // Scan network for active IPs (admin only)
            Auth::require('admin');

            $networkId = getParam('network_id', true);

            $results = NetworkManager::scanNetwork($networkId);

            jsonResponse($results);
            break;

        case 'statistics':
            // Get network statistics
            $stats = NetworkManager::getStatistics();

            jsonResponse($stats);
            break;

        default:
            // Default: List all networks
            $networks = NetworkManager::getAllNetworks(true);

            jsonResponse([
                'total_networks' => count($networks),
                'networks' => $networks,
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
