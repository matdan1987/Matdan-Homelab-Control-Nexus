<?php
/**
 * Nodes API Endpoint
 *
 * Handles Proxmox VE node management.
 *
 * Endpoints:
 * - GET    /api/nodes.php              List all nodes
 * - GET    /api/nodes.php?id=1         Get specific node
 * - POST   /api/nodes.php              Create new node
 * - PUT    /api/nodes.php?id=1         Update node
 * - DELETE /api/nodes.php?id=1         Delete node
 * - POST   /api/nodes.php?id=1&action=test    Test node connection
 * - POST   /api/nodes.php?id=1&action=refresh Refresh resource cache
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$id = getParam('id');
$action = getParam('action');

// Route based on method and action
if ($action === 'test' && $id) {
    handleTestConnection($id);
} elseif ($action === 'refresh' && $id) {
    handleRefreshCache($id);
} elseif ($method === 'GET' && $id) {
    handleGetNode($id);
} elseif ($method === 'GET') {
    handleListNodes();
} elseif ($method === 'POST') {
    handleCreateNode();
} elseif ($method === 'PUT' && $id) {
    handleUpdateNode($id);
} elseif ($method === 'DELETE' && $id) {
    handleDeleteNode($id);
} else {
    sendError('Invalid request', 400);
}

/**
 * List all nodes
 */
function handleListNodes()
{
    try {
        $nodes = NodesRepository::getAll();

        // Add statistics for each node
        foreach ($nodes as &$node) {
            $node['statistics'] = NodesRepository::getStatistics($node['id']);
        }

        sendJson(['nodes' => $nodes]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to list nodes: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve nodes', 500);
    }
}

/**
 * Get single node
 */
function handleGetNode($id)
{
    try {
        $node = NodesRepository::getById((int) $id);

        if (!$node) {
            sendError('Node not found', 404);
        }

        // Add statistics and cached resources
        $node['statistics'] = NodesRepository::getStatistics($node['id']);
        $node['resources'] = NodesRepository::getCachedResources($node['id']);

        sendJson($node);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get node: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve node', 500);
    }
}

/**
 * Create new node
 */
function handleCreateNode()
{
    // Require admin role
    Auth::require('admin');

    $data = getJsonInput();
    requireParams(['name', 'api_url', 'token_id', 'token_secret'], $data);

    try {
        $nodeData = [
            'name' => sanitizeInput($data['name']),
            'api_url' => sanitizeInput($data['api_url']),
            'token_id' => sanitizeInput($data['token_id']),
            'token_secret' => $data['token_secret'], // Don't sanitize secrets
            'verify_ssl' => isset($data['verify_ssl']) ? (int) $data['verify_ssl'] : 0,
            'enabled' => isset($data['enabled']) ? (int) $data['enabled'] : 1,
            'remarks' => isset($data['remarks']) ? sanitizeInput($data['remarks']) : null,
        ];

        $id = NodesRepository::create($nodeData);

        // Test connection
        $connectionOk = NodesRepository::testConnection($id);

        $node = NodesRepository::getById($id);
        $node['connection_test'] = $connectionOk;

        sendJson([
            'success' => true,
            'id' => $id,
            'node' => $node,
        ], 201);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to create node: ' . $e->getMessage(), Auth::id());
        sendError('Failed to create node: ' . $e->getMessage(), 500);
    }
}

/**
 * Update node
 */
function handleUpdateNode($id)
{
    // Require admin role
    Auth::require('admin');

    $data = getJsonInput();

    try {
        $updateData = [];

        $allowedFields = ['name', 'api_url', 'token_id', 'token_secret', 'verify_ssl', 'enabled', 'remarks'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['name', 'api_url', 'token_id', 'remarks'])) {
                    $updateData[$field] = sanitizeInput($data[$field]);
                } else {
                    $updateData[$field] = $data[$field];
                }
            }
        }

        NodesRepository::update((int) $id, $updateData);

        $node = NodesRepository::getById((int) $id);

        sendJson([
            'success' => true,
            'node' => $node,
        ]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to update node: ' . $e->getMessage(), Auth::id());
        sendError('Failed to update node: ' . $e->getMessage(), 500);
    }
}

/**
 * Delete node
 */
function handleDeleteNode($id)
{
    // Require admin role
    Auth::require('admin');

    try {
        NodesRepository::delete((int) $id);

        sendJson(['success' => true]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to delete node: ' . $e->getMessage(), Auth::id());
        sendError('Failed to delete node: ' . $e->getMessage(), 500);
    }
}

/**
 * Test node connection
 */
function handleTestConnection($id)
{
    // Require operator role
    Auth::require('operator');

    try {
        $success = NodesRepository::testConnection((int) $id);

        $node = NodesRepository::getById((int) $id);

        sendJson([
            'success' => $success,
            'status' => $node['last_status'],
            'last_check' => $node['last_check'],
        ]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to test connection: ' . $e->getMessage(), Auth::id());
        sendError('Failed to test connection: ' . $e->getMessage(), 500);
    }
}

/**
 * Refresh resource cache
 */
function handleRefreshCache($id)
{
    // Require operator role
    Auth::require('operator');

    try {
        $count = NodesRepository::updateResourceCache((int) $id);

        sendJson([
            'success' => true,
            'resources_cached' => $count,
        ]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to refresh cache: ' . $e->getMessage(), Auth::id());
        sendError('Failed to refresh cache: ' . $e->getMessage(), 500);
    }
}
