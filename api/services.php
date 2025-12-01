<?php
/**
 * Services API Endpoint (CMDB Light)
 *
 * Handles services, instances, and dependencies.
 *
 * Endpoints:
 * - GET    /api/services.php                           List all services
 * - GET    /api/services.php?id=1                      Get service details
 * - POST   /api/services.php                           Create service
 * - PUT    /api/services.php?id=1                      Update service
 * - DELETE /api/services.php?id=1                      Delete service
 * - POST   /api/services.php?id=1&action=add_instance      Add instance
 * - DELETE /api/services.php?action=remove_instance&instance_id=1  Remove instance
 * - POST   /api/services.php?id=1&action=add_dependency    Add dependency
 * - DELETE /api/services.php?action=remove_dependency&dependency_id=1  Remove dependency
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$id = getParam('id');
$action = getParam('action');

// Route based on action
if ($action === 'add_instance' && $id) {
    handleAddInstance($id);
} elseif ($action === 'remove_instance') {
    handleRemoveInstance();
} elseif ($action === 'add_dependency' && $id) {
    handleAddDependency($id);
} elseif ($action === 'remove_dependency') {
    handleRemoveDependency();
} elseif ($method === 'GET' && $id) {
    handleGetService($id);
} elseif ($method === 'GET') {
    handleListServices();
} elseif ($method === 'POST') {
    handleCreateService();
} elseif ($method === 'PUT' && $id) {
    handleUpdateService($id);
} elseif ($method === 'DELETE' && $id) {
    handleDeleteService($id);
} else {
    sendError('Invalid request', 400);
}

/**
 * List all services
 */
function handleListServices()
{
    try {
        $filters = [];

        if ($env = getParam('environment')) {
            $filters['environment'] = $env;
        }

        if ($criticality = getParam('criticality')) {
            $filters['criticality'] = $criticality;
        }

        if ($tag = getParam('tag')) {
            $filters['tag'] = $tag;
        }

        $withHealth = getParam('with_health', false);

        if ($withHealth) {
            $services = ServicesRepository::getAllWithHealth();
        } else {
            $services = ServicesRepository::getAll($filters);
            foreach ($services as &$service) {
                $service['tags'] = $service['tags'] ? json_decode($service['tags'], true) : [];
            }
        }

        sendJson(['services' => $services]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to list services: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve services', 500);
    }
}

/**
 * Get service details
 */
function handleGetService($id)
{
    try {
        $service = ServicesRepository::getDetails((int) $id);

        if (!$service) {
            sendError('Service not found', 404);
        }

        // Add health status
        $service['health'] = ServicesRepository::getHealthStatus((int) $id);

        sendJson($service);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get service: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve service', 500);
    }
}

/**
 * Create service
 */
function handleCreateService()
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['name'], $data);

    try {
        $serviceData = [
            'name' => sanitizeInput($data['name']),
            'description' => isset($data['description']) ? sanitizeInput($data['description']) : null,
            'environment' => isset($data['environment']) ? sanitizeInput($data['environment']) : null,
            'owner' => isset($data['owner']) ? sanitizeInput($data['owner']) : null,
            'criticality' => isset($data['criticality']) ? sanitizeInput($data['criticality']) : 'medium',
            'tags' => isset($data['tags']) ? $data['tags'] : [],
        ];

        $id = ServicesRepository::create($serviceData);

        $service = ServicesRepository::getById($id);

        sendJson([
            'success' => true,
            'id' => $id,
            'service' => $service,
        ], 201);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to create service: ' . $e->getMessage(), Auth::id());
        sendError('Failed to create service: ' . $e->getMessage(), 500);
    }
}

/**
 * Update service
 */
function handleUpdateService($id)
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();

    try {
        $updateData = [];

        $allowedFields = ['name', 'description', 'environment', 'owner', 'criticality', 'tags'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'tags') {
                    $updateData[$field] = $data[$field];
                } else {
                    $updateData[$field] = sanitizeInput($data[$field]);
                }
            }
        }

        ServicesRepository::update((int) $id, $updateData);

        $service = ServicesRepository::getDetails((int) $id);

        sendJson([
            'success' => true,
            'service' => $service,
        ]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to update service: ' . $e->getMessage(), Auth::id());
        sendError('Failed to update service: ' . $e->getMessage(), 500);
    }
}

/**
 * Delete service
 */
function handleDeleteService($id)
{
    // Require operator role
    Auth::require('operator');

    try {
        ServicesRepository::delete((int) $id);

        sendJson(['success' => true]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to delete service: ' . $e->getMessage(), Auth::id());
        sendError('Failed to delete service: ' . $e->getMessage(), 500);
    }
}

/**
 * Add instance to service
 */
function handleAddInstance($serviceId)
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['node_id', 'vmid', 'type'], $data);

    try {
        $instanceId = ServicesRepository::addInstance(
            (int) $serviceId,
            (int) $data['node_id'],
            (int) $data['vmid'],
            $data['type'],
            isset($data['notes']) ? sanitizeInput($data['notes']) : null
        );

        sendJson([
            'success' => true,
            'instance_id' => $instanceId,
        ], 201);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to add instance: ' . $e->getMessage(), Auth::id());
        sendError('Failed to add instance: ' . $e->getMessage(), 500);
    }
}

/**
 * Remove instance from service
 */
function handleRemoveInstance()
{
    // Require operator role
    Auth::require('operator');

    $instanceId = getParam('instance_id');
    if (!$instanceId) {
        sendError('Missing instance_id parameter', 400);
    }

    try {
        ServicesRepository::removeInstance((int) $instanceId);

        sendJson(['success' => true]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to remove instance: ' . $e->getMessage(), Auth::id());
        sendError('Failed to remove instance: ' . $e->getMessage(), 500);
    }
}

/**
 * Add dependency
 */
function handleAddDependency($serviceId)
{
    // Require operator role
    Auth::require('operator');

    $data = getJsonInput();
    requireParams(['depends_on_service_id'], $data);

    try {
        $dependencyId = ServicesRepository::addDependency(
            (int) $serviceId,
            (int) $data['depends_on_service_id']
        );

        sendJson([
            'success' => true,
            'dependency_id' => $dependencyId,
        ], 201);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to add dependency: ' . $e->getMessage(), Auth::id());
        sendError('Failed to add dependency: ' . $e->getMessage(), 500);
    }
}

/**
 * Remove dependency
 */
function handleRemoveDependency()
{
    // Require operator role
    Auth::require('operator');

    $dependencyId = getParam('dependency_id');
    if (!$dependencyId) {
        sendError('Missing dependency_id parameter', 400);
    }

    try {
        ServicesRepository::removeDependency((int) $dependencyId);

        sendJson(['success' => true]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to remove dependency: ' . $e->getMessage(), Auth::id());
        sendError('Failed to remove dependency: ' . $e->getMessage(), 500);
    }
}
