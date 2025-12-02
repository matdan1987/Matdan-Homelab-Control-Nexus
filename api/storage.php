<?php
/**
 * Storage API Endpoint
 *
 * Handles storage pool management and monitoring.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/StorageManager.php';

// Require authentication
Auth::require();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'register':
            // Register storage pool (admin only)
            Auth::require('admin');

            $pveNodeId = getParam('pve_node_id', true);
            $name = getParam('name', true);
            $type = getParam('type', true);

            $options = [];
            $optionalFields = ['path', 'total_size_gb', 'used_size_gb', 'shared', 'content_types'];
            foreach ($optionalFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    if ($field === 'content_types' && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $options[$field] = $value;
                }
            }

            $poolId = StorageManager::registerPool($pveNodeId, $name, $type, $options);

            jsonResponse([
                'id' => $poolId,
                'message' => 'Storage pool registered successfully'
            ]);
            break;

        case 'update':
            // Update storage pool (admin only)
            Auth::require('admin');

            $poolId = getParam('id', true);

            $updates = [];
            $allowedFields = ['name', 'path', 'total_size_gb', 'used_size_gb', 'shared', 'content_types', 'active'];

            foreach ($allowedFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    if ($field === 'content_types' && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $updates[$field] = $value;
                }
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            StorageManager::updatePool($poolId, $updates);

            jsonResponse(['message' => 'Storage pool updated successfully']);
            break;

        case 'delete':
            // Delete storage pool (admin only)
            Auth::require('admin');

            $poolId = getParam('id', true);

            StorageManager::deletePool($poolId);

            jsonResponse(['message' => 'Storage pool deleted successfully']);
            break;

        case 'get':
            // Get storage pool details
            $poolId = getParam('id', true);

            $pool = StorageManager::getPool($poolId);

            jsonResponse($pool);
            break;

        case 'list':
            // List all storage pools
            $pveNodeId = getParam('pve_node_id', false);
            $activeOnly = getParam('active_only', false) !== '0';

            $pools = StorageManager::getAllPools($pveNodeId, $activeOnly);

            jsonResponse($pools);
            break;

        case 'sync':
            // Sync storage pools from Proxmox (admin only)
            Auth::require('admin');

            $pveNodeId = getParam('pve_node_id', true);

            $count = StorageManager::syncFromProxmox($pveNodeId);

            jsonResponse([
                'synced_count' => $count,
                'message' => "$count storage pool(s) synced successfully"
            ]);
            break;

        case 'sync_all':
            // Sync all nodes (admin only)
            Auth::require('admin');

            $nodes = Database::select('SELECT id, name FROM pve_nodes WHERE active = 1');

            $totalSynced = 0;
            $results = [];

            foreach ($nodes as $node) {
                try {
                    $count = StorageManager::syncFromProxmox($node['id']);
                    $totalSynced += $count;
                    $results[] = [
                        'node_id' => $node['id'],
                        'node_name' => $node['name'],
                        'synced_count' => $count,
                        'status' => 'success',
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'node_id' => $node['id'],
                        'node_name' => $node['name'],
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            jsonResponse([
                'total_synced' => $totalSynced,
                'results' => $results,
            ]);
            break;

        case 'node_storage':
            // Get storage summary for node
            $pveNodeId = getParam('pve_node_id', true);

            $storage = StorageManager::getNodeStorage($pveNodeId);

            jsonResponse($storage);
            break;

        case 'statistics':
            // Get storage statistics
            $pveNodeId = getParam('pve_node_id', false);

            $stats = StorageManager::getStatistics($pveNodeId);

            jsonResponse($stats);
            break;

        case 'low_space':
            // Get pools with low space
            $threshold = getParam('threshold', false) ?: 80;

            $pools = StorageManager::getLowSpacePools($threshold);

            jsonResponse([
                'threshold_percent' => $threshold,
                'low_space_pools' => $pools,
                'count' => count($pools),
            ]);
            break;

        case 'health':
            // Get storage health status
            $health = StorageManager::getHealthStatus();

            jsonResponse($health);
            break;

        case 'find_best':
            // Find best storage pool for allocation
            $pveNodeId = getParam('pve_node_id', true);
            $requiredSizeGb = getParam('required_size_gb', true);
            $contentType = getParam('content_type', false);

            $bestPool = StorageManager::findBestPool($pveNodeId, $requiredSizeGb, $contentType);

            if (!$bestPool) {
                throw new Exception('No suitable storage pool found');
            }

            jsonResponse($bestPool);
            break;

        case 'usage_trend':
            // Get usage trend for pool
            $poolId = getParam('pool_id', true);
            $days = min((int)getParam('days', false) ?: 30, 365);

            $trend = StorageManager::getUsageTrend($poolId, $days);

            jsonResponse($trend);
            break;

        default:
            // Default: Get storage overview
            $stats = StorageManager::getStatistics();
            $health = StorageManager::getHealthStatus();

            jsonResponse([
                'statistics' => $stats,
                'health' => $health['summary'],
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
