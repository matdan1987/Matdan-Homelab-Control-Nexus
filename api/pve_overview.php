<?php
/**
 * Proxmox Overview API Endpoint
 *
 * Provides aggregated overview of all Proxmox nodes and resources.
 *
 * Endpoints:
 * - GET /api/pve_overview.php              Overall cluster overview
 * - GET /api/pve_overview.php?node_id=1    Specific node overview
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$nodeId = getParam('node_id');

if ($nodeId) {
    handleNodeOverview((int) $nodeId);
} else {
    handleClusterOverview();
}

/**
 * Get overall cluster overview
 */
function handleClusterOverview()
{
    try {
        $nodes = NodesRepository::getAll(true); // Only enabled nodes
        $allResources = NodesRepository::getAllCachedResources();

        // Aggregate statistics
        $overview = [
            'nodes' => [
                'total' => count($nodes),
                'online' => 0,
                'offline' => 0,
                'error' => 0,
            ],
            'vms' => [
                'total' => 0,
                'running' => 0,
                'stopped' => 0,
                'qemu' => 0,
                'lxc' => 0,
            ],
            'resources' => [
                'total_cpu_cores' => 0,
                'total_memory_gb' => 0,
                'total_disk_gb' => 0,
            ],
            'top_cpu_consumers' => [],
            'top_memory_consumers' => [],
        ];

        // Count node statuses
        foreach ($nodes as $node) {
            if ($node['last_status'] === 'online') {
                $overview['nodes']['online']++;
            } elseif ($node['last_status'] === 'offline') {
                $overview['nodes']['offline']++;
            } elseif ($node['last_status'] === 'error') {
                $overview['nodes']['error']++;
            }
        }

        // Aggregate VM stats
        foreach ($allResources as $resource) {
            $overview['vms']['total']++;

            if ($resource['status'] === 'running') {
                $overview['vms']['running']++;
            } else {
                $overview['vms']['stopped']++;
            }

            if ($resource['type'] === 'qemu') {
                $overview['vms']['qemu']++;
            } else {
                $overview['vms']['lxc']++;
            }

            $overview['resources']['total_cpu_cores'] += $resource['maxcpu'] ?? 0;
            $overview['resources']['total_memory_gb'] += ($resource['maxmem'] ?? 0) / 1073741824;
            $overview['resources']['total_disk_gb'] += ($resource['maxdisk'] ?? 0) / 1073741824;
        }

        // Round resource values
        $overview['resources']['total_memory_gb'] = round($overview['resources']['total_memory_gb'], 2);
        $overview['resources']['total_disk_gb'] = round($overview['resources']['total_disk_gb'], 2);

        // Get top CPU consumers (running VMs only)
        $runningVms = array_filter($allResources, fn($r) => $r['status'] === 'running');
        usort($runningVms, fn($a, $b) => ($b['cpu'] ?? 0) <=> ($a['cpu'] ?? 0));
        $overview['top_cpu_consumers'] = array_slice(array_map(function($r) {
            return [
                'vmid' => $r['vmid'],
                'name' => $r['name'],
                'node' => $r['pve_node_name'],
                'cpu' => round($r['cpu'] ?? 0, 2),
                'maxcpu' => $r['maxcpu'],
            ];
        }, $runningVms), 0, 10);

        // Get top memory consumers (running VMs only)
        usort($runningVms, fn($a, $b) => ($b['mem'] ?? 0) <=> ($a['mem'] ?? 0));
        $overview['top_memory_consumers'] = array_slice(array_map(function($r) {
            return [
                'vmid' => $r['vmid'],
                'name' => $r['name'],
                'node' => $r['pve_node_name'],
                'mem' => round(($r['mem'] ?? 0) / 1073741824, 2),
                'maxmem' => round(($r['maxmem'] ?? 0) / 1073741824, 2),
                'mem_percent' => $r['maxmem'] > 0 ? round(($r['mem'] / $r['maxmem']) * 100, 1) : 0,
            ];
        }, $runningVms), 0, 10);

        sendJson(['overview' => $overview]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get cluster overview: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve cluster overview', 500);
    }
}

/**
 * Get specific node overview
 */
function handleNodeOverview($nodeId)
{
    try {
        $node = NodesRepository::getById($nodeId);

        if (!$node) {
            sendError('Node not found', 404);
        }

        // Get node statistics
        $stats = NodesRepository::getStatistics($nodeId);

        // Get resources
        $resources = NodesRepository::getCachedResources($nodeId);

        // Get running resources for live metrics
        $runningResources = array_filter($resources, fn($r) => $r['status'] === 'running');

        $overview = [
            'node' => [
                'id' => $node['id'],
                'name' => $node['name'],
                'status' => $node['last_status'],
                'last_check' => $node['last_check'],
            ],
            'statistics' => $stats,
            'resources' => [
                'total' => count($resources),
                'running' => count($runningResources),
            ],
            'top_resources' => array_slice($runningResources, 0, 5),
        ];

        sendJson(['overview' => $overview]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get node overview: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve node overview', 500);
    }
}
