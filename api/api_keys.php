<?php
/**
 * API Keys Management Endpoint
 *
 * Handles CRUD operations for API keys.
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$action = getParam('action');
$id = getParam('id');

// Route requests
if ($method === 'POST' && $action === 'create') {
    handleCreate();
} elseif ($method === 'GET' && !$id) {
    handleList();
} elseif ($method === 'DELETE' && $id) {
    handleRevoke($id);
} elseif ($method === 'GET' && $action === 'usage') {
    handleUsageStats();
} else {
    sendError('Invalid request', 400);
}

/**
 * Create new API key
 */
function handleCreate()
{
    $data = getJsonInput();
    requireParams(['name'], $data);

    try {
        $permissions = $data['permissions'] ?? [];
        $expiresDays = $data['expires_days'] ?? null;
        $expiresAt = $expiresDays ? new DateTime("+$expiresDays days") : null;

        $result = ApiKeyManager::generate(
            Auth::id(),
            sanitizeInput($data['name']),
            $permissions,
            $expiresAt
        );

        sendJson([
            'success' => true,
            'api_key' => $result['key'],
            'prefix' => $result['prefix'],
            'id' => $result['id'],
            'warning' => 'Save this key now! It will not be shown again.',
        ], 201);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to create API key: ' . $e->getMessage(), Auth::id());
        sendError('Failed to create API key', 500);
    }
}

/**
 * List user's API keys
 */
function handleList()
{
    try {
        $keys = ApiKeyManager::listForUser(Auth::id());

        // Decode permissions for display
        foreach ($keys as &$key) {
            $key['permissions'] = json_decode($key['permissions_json'], true) ?? [];
            unset($key['permissions_json']);
        }

        sendJson(['api_keys' => $keys]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to list API keys: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve API keys', 500);
    }
}

/**
 * Revoke API key
 */
function handleRevoke($id)
{
    try {
        $success = ApiKeyManager::revoke((int) $id, Auth::id());

        if ($success) {
            sendJson(['success' => true]);
        } else {
            sendError('API key not found or access denied', 404);
        }
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to revoke API key: ' . $e->getMessage(), Auth::id());
        sendError('Failed to revoke API key', 500);
    }
}

/**
 * Get usage statistics
 */
function handleUsageStats()
{
    try {
        $days = (int) getParam('days', 30);
        $stats = ApiKeyManager::getUsageStats(Auth::id(), $days);

        sendJson(['usage_stats' => $stats]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get usage stats: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve usage statistics', 500);
    }
}
