<?php
/**
 * Proxmox VMs/LXCs API Endpoint
 *
 * Handles VM and LXC operations.
 *
 * Endpoints:
 * - GET    /api/pve_vms.php?node_id=1                    List VMs on node
 * - GET    /api/pve_vms.php?node_id=1&vmid=100          Get VM details
 * - POST   /api/pve_vms.php?action=start                Start VM
 * - POST   /api/pve_vms.php?action=stop                 Stop VM
 * - POST   /api/pve_vms.php?action=shutdown             Shutdown VM
 * - POST   /api/pve_vms.php?action=reboot               Reboot VM
 * - POST   /api/pve_vms.php?action=update_config        Update VM config
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$action = getParam('action');
$nodeId = getParam('node_id');
$vmid = getParam('vmid');

// Route based on action
if ($action === 'start') {
    handleStart();
} elseif ($action === 'stop') {
    handleStop();
} elseif ($action === 'shutdown') {
    handleShutdown();
} elseif ($action === 'reboot') {
    handleReboot();
} elseif ($action === 'update_config') {
    handleUpdateConfig();
} elseif ($method === 'GET' && $nodeId && $vmid) {
    handleGetVM($nodeId, $vmid);
} elseif ($method === 'GET' && $nodeId) {
    handleListVMs($nodeId);
} else {
    sendError('Invalid request', 400);
}

/**
 * List VMs on a node
 */
function handleListVMs($nodeId)
{
    try {
        $vms = NodesRepository::getCachedResources((int) $nodeId);

        sendJson(['vms' => $vms]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to list VMs: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve VMs', 500);
    }
}

/**
 * Get VM details
 */
function handleGetVM($nodeId, $vmid)
{
    try {
        $vm = Database::selectOne(
            'SELECT * FROM pve_resources_cache WHERE pve_node_id = ? AND vmid = ?',
            [(int) $nodeId, (int) $vmid]
        );

        if (!$vm) {
            // Try to fetch from API directly
            $node = NodesRepository::getById((int) $nodeId);
            if (!$node) {
                sendError('Node not found', 404);
            }

            $client = NodesRepository::getClient((int) $nodeId);

            // Determine type (try qemu first, then lxc)
            try {
                $status = $client->getVMStatus($node['name'], (int) $vmid, 'qemu');
                $config = $client->getVMConfig($node['name'], (int) $vmid, 'qemu');
                $vm = array_merge($status, $config, ['type' => 'qemu']);
            } catch (Exception $e) {
                try {
                    $status = $client->getVMStatus($node['name'], (int) $vmid, 'lxc');
                    $config = $client->getVMConfig($node['name'], (int) $vmid, 'lxc');
                    $vm = array_merge($status, $config, ['type' => 'lxc']);
                } catch (Exception $e2) {
                    sendError('VM not found', 404);
                }
            }
        }

        sendJson(['vm' => $vm]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get VM: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve VM', 500);
    }
}

/**
 * Start VM
 */
function handleStart()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type'], $data);

    try {
        $node = NodesRepository::getById((int) $data['node_id']);
        if (!$node) {
            sendError('Node not found', 404);
        }

        $client = NodesRepository::getClient((int) $data['node_id']);
        $result = $client->startVM($node['name'], (int) $data['vmid'], $data['type']);

        EventLogger::info('vm_action', "Started VM {$data['vmid']} on node {$node['name']}", Auth::id(), [
            'node_id' => $data['node_id'],
            'vmid' => $data['vmid'],
            'type' => $data['type']
        ]);

        sendJson([
            'success' => true,
            'result' => $result,
        ]);
    } catch (Exception $e) {
        EventLogger::error('vm_action', 'Failed to start VM: ' . $e->getMessage(), Auth::id(), [
            'node_id' => $data['node_id'] ?? null,
            'vmid' => $data['vmid'] ?? null
        ]);
        sendError('Failed to start VM: ' . $e->getMessage(), 500);
    }
}

/**
 * Stop VM
 */
function handleStop()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type'], $data);

    try {
        $node = NodesRepository::getById((int) $data['node_id']);
        if (!$node) {
            sendError('Node not found', 404);
        }

        $client = NodesRepository::getClient((int) $data['node_id']);
        $result = $client->stopVM($node['name'], (int) $data['vmid'], $data['type']);

        EventLogger::warning('vm_action', "Stopped VM {$data['vmid']} on node {$node['name']}", Auth::id(), [
            'node_id' => $data['node_id'],
            'vmid' => $data['vmid'],
            'type' => $data['type']
        ]);

        sendJson([
            'success' => true,
            'result' => $result,
        ]);
    } catch (Exception $e) {
        EventLogger::error('vm_action', 'Failed to stop VM: ' . $e->getMessage(), Auth::id());
        sendError('Failed to stop VM: ' . $e->getMessage(), 500);
    }
}

/**
 * Shutdown VM gracefully
 */
function handleShutdown()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type'], $data);

    try {
        $node = NodesRepository::getById((int) $data['node_id']);
        if (!$node) {
            sendError('Node not found', 404);
        }

        $client = NodesRepository::getClient((int) $data['node_id']);
        $result = $client->shutdownVM($node['name'], (int) $data['vmid'], $data['type']);

        EventLogger::info('vm_action', "Shutdown VM {$data['vmid']} on node {$node['name']}", Auth::id(), [
            'node_id' => $data['node_id'],
            'vmid' => $data['vmid'],
            'type' => $data['type']
        ]);

        sendJson([
            'success' => true,
            'result' => $result,
        ]);
    } catch (Exception $e) {
        EventLogger::error('vm_action', 'Failed to shutdown VM: ' . $e->getMessage(), Auth::id());
        sendError('Failed to shutdown VM: ' . $e->getMessage(), 500);
    }
}

/**
 * Reboot VM
 */
function handleReboot()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type'], $data);

    try {
        $node = NodesRepository::getById((int) $data['node_id']);
        if (!$node) {
            sendError('Node not found', 404);
        }

        $client = NodesRepository::getClient((int) $data['node_id']);
        $result = $client->rebootVM($node['name'], (int) $data['vmid'], $data['type']);

        EventLogger::info('vm_action', "Rebooted VM {$data['vmid']} on node {$node['name']}", Auth::id(), [
            'node_id' => $data['node_id'],
            'vmid' => $data['vmid'],
            'type' => $data['type']
        ]);

        sendJson([
            'success' => true,
            'result' => $result,
        ]);
    } catch (Exception $e) {
        EventLogger::error('vm_action', 'Failed to reboot VM: ' . $e->getMessage(), Auth::id());
        sendError('Failed to reboot VM: ' . $e->getMessage(), 500);
    }
}

/**
 * Update VM configuration
 */
function handleUpdateConfig()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type', 'config'], $data);

    try {
        $node = NodesRepository::getById((int) $data['node_id']);
        if (!$node) {
            sendError('Node not found', 404);
        }

        $client = NodesRepository::getClient((int) $data['node_id']);
        $result = $client->updateVMConfig(
            $node['name'],
            (int) $data['vmid'],
            $data['type'],
            $data['config']
        );

        EventLogger::info('vm_action', "Updated config for VM {$data['vmid']} on node {$node['name']}", Auth::id(), [
            'node_id' => $data['node_id'],
            'vmid' => $data['vmid'],
            'type' => $data['type'],
            'config' => $data['config']
        ]);

        sendJson([
            'success' => true,
            'result' => $result,
        ]);
    } catch (Exception $e) {
        EventLogger::error('vm_action', 'Failed to update VM config: ' . $e->getMessage(), Auth::id());
        sendError('Failed to update VM config: ' . $e->getMessage(), 500);
    }
}
