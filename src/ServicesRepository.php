<?php
/**
 * ServicesRepository Class
 *
 * Repository for managing services in the CMDB Light system.
 * Handles services, instances, and dependencies.
 */

class ServicesRepository
{
    /**
     * Get all services
     *
     * @param array $filters Optional filters (environment, criticality, tag)
     * @return array Services list
     */
    public static function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['environment'])) {
            $where[] = 'environment = ?';
            $params[] = $filters['environment'];
        }

        if (isset($filters['criticality'])) {
            $where[] = 'criticality = ?';
            $params[] = $filters['criticality'];
        }

        if (isset($filters['tag'])) {
            $where[] = 'JSON_CONTAINS(tags, ?)';
            $params[] = json_encode($filters['tag']);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT * FROM services $whereClause ORDER BY name ASC";

        return Database::select($query, $params);
    }

    /**
     * Get service by ID
     *
     * @param int $id Service ID
     * @return array|null Service data
     */
    public static function getById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM services WHERE id = ?', [$id]);
    }

    /**
     * Get service with full details (instances, dependencies)
     *
     * @param int $id Service ID
     * @return array|null Service data with details
     */
    public static function getDetails(int $id): ?array
    {
        $service = self::getById($id);
        if (!$service) {
            return null;
        }

        // Decode tags
        $service['tags'] = $service['tags'] ? json_decode($service['tags'], true) : [];

        // Get instances
        $service['instances'] = self::getInstances($id);

        // Get dependencies
        $service['dependencies'] = self::getDependencies($id);

        // Get dependent services (services that depend on this one)
        $service['dependents'] = self::getDependents($id);

        return $service;
    }

    /**
     * Create a new service
     *
     * @param array $data Service data
     * @return int New service ID
     */
    public static function create(array $data): int
    {
        $tags = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags']) : null;

        $id = Database::insert(
            'INSERT INTO services (name, description, environment, owner, criticality, tags)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['description'] ?? null,
                $data['environment'] ?? null,
                $data['owner'] ?? null,
                $data['criticality'] ?? 'medium',
                $tags,
            ]
        );

        EventLogger::info('cmdb', "Created service: {$data['name']}", Auth::id(), ['service_id' => $id]);

        return $id;
    }

    /**
     * Update a service
     *
     * @param int $id Service ID
     * @param array $data Updated data
     * @return int Number of affected rows
     */
    public static function update(int $id, array $data): int
    {
        $service = self::getById($id);
        if (!$service) {
            throw new Exception("Service with ID $id not found");
        }

        $fields = [];
        $params = [];

        $allowedFields = ['name', 'description', 'environment', 'owner', 'criticality'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (isset($data['tags']) && is_array($data['tags'])) {
            $fields[] = "tags = ?";
            $params[] = json_encode($data['tags']);
        }

        if (empty($fields)) {
            return 0;
        }

        $params[] = $id;

        $result = Database::update(
            'UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('cmdb', "Updated service: {$service['name']}", Auth::id(), ['service_id' => $id]);

        return $result;
    }

    /**
     * Delete a service
     *
     * @param int $id Service ID
     * @return int Number of affected rows
     */
    public static function delete(int $id): int
    {
        $service = self::getById($id);
        if (!$service) {
            throw new Exception("Service with ID $id not found");
        }

        $result = Database::delete('DELETE FROM services WHERE id = ?', [$id]);

        EventLogger::warning('cmdb', "Deleted service: {$service['name']}", Auth::id(), ['service_id' => $id]);

        return $result;
    }

    /**
     * Get instances for a service
     *
     * @param int $serviceId Service ID
     * @return array Instances list
     */
    public static function getInstances(int $serviceId): array
    {
        $instances = Database::select(
            'SELECT si.*, n.name as node_name, c.name as vm_name, c.status, c.cpu, c.mem, c.maxmem
             FROM service_instances si
             JOIN pve_nodes n ON si.pve_node_id = n.id
             LEFT JOIN pve_resources_cache c ON si.pve_node_id = c.pve_node_id AND si.vmid = c.vmid
             WHERE si.service_id = ?
             ORDER BY si.vmid ASC',
            [$serviceId]
        );

        return $instances;
    }

    /**
     * Add an instance to a service
     *
     * @param int $serviceId Service ID
     * @param int $nodeId Node ID
     * @param int $vmid VM ID
     * @param string $type Type (qemu or lxc)
     * @param string|null $notes Notes
     * @return int New instance ID
     */
    public static function addInstance(int $serviceId, int $nodeId, int $vmid, string $type, ?string $notes = null): int
    {
        // Check if instance already exists for another service
        $existing = Database::selectOne(
            'SELECT * FROM service_instances WHERE pve_node_id = ? AND vmid = ?',
            [$nodeId, $vmid]
        );

        if ($existing) {
            throw new Exception("Instance {$vmid} is already assigned to another service");
        }

        $id = Database::insert(
            'INSERT INTO service_instances (service_id, pve_node_id, vmid, type, notes)
             VALUES (?, ?, ?, ?, ?)',
            [$serviceId, $nodeId, $vmid, $type, $notes]
        );

        $service = self::getById($serviceId);
        EventLogger::info('cmdb', "Added instance {$vmid} to service: {$service['name']}", Auth::id(), [
            'service_id' => $serviceId,
            'instance_id' => $id
        ]);

        return $id;
    }

    /**
     * Remove an instance from a service
     *
     * @param int $instanceId Instance ID
     * @return int Number of affected rows
     */
    public static function removeInstance(int $instanceId): int
    {
        return Database::delete('DELETE FROM service_instances WHERE id = ?', [$instanceId]);
    }

    /**
     * Get dependencies for a service
     *
     * @param int $serviceId Service ID
     * @return array Dependencies list
     */
    public static function getDependencies(int $serviceId): array
    {
        return Database::select(
            'SELECT s.*, sd.id as dependency_id
             FROM service_dependencies sd
             JOIN services s ON sd.depends_on_service_id = s.id
             WHERE sd.service_id = ?',
            [$serviceId]
        );
    }

    /**
     * Get dependents for a service (services that depend on this one)
     *
     * @param int $serviceId Service ID
     * @return array Dependents list
     */
    public static function getDependents(int $serviceId): array
    {
        return Database::select(
            'SELECT s.*, sd.id as dependency_id
             FROM service_dependencies sd
             JOIN services s ON sd.service_id = s.id
             WHERE sd.depends_on_service_id = ?',
            [$serviceId]
        );
    }

    /**
     * Add a dependency
     *
     * @param int $serviceId Service ID
     * @param int $dependsOnServiceId Service ID it depends on
     * @return int New dependency ID
     */
    public static function addDependency(int $serviceId, int $dependsOnServiceId): int
    {
        if ($serviceId === $dependsOnServiceId) {
            throw new Exception("Service cannot depend on itself");
        }

        // Check for circular dependency
        if (self::hasCircularDependency($serviceId, $dependsOnServiceId)) {
            throw new Exception("Adding this dependency would create a circular dependency");
        }

        try {
            $id = Database::insert(
                'INSERT INTO service_dependencies (service_id, depends_on_service_id)
                 VALUES (?, ?)',
                [$serviceId, $dependsOnServiceId]
            );

            $service = self::getById($serviceId);
            $dependsOn = self::getById($dependsOnServiceId);

            EventLogger::info('cmdb', "Added dependency: {$service['name']} depends on {$dependsOn['name']}", Auth::id(), [
                'service_id' => $serviceId,
                'depends_on_service_id' => $dependsOnServiceId
            ]);

            return $id;
        } catch (Exception $e) {
            // Duplicate entry
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                throw new Exception("This dependency already exists");
            }
            throw $e;
        }
    }

    /**
     * Remove a dependency
     *
     * @param int $dependencyId Dependency ID
     * @return int Number of affected rows
     */
    public static function removeDependency(int $dependencyId): int
    {
        return Database::delete('DELETE FROM service_dependencies WHERE id = ?', [$dependencyId]);
    }

    /**
     * Check for circular dependency
     *
     * @param int $serviceId Service ID
     * @param int $dependsOnServiceId Service ID it would depend on
     * @return bool True if circular dependency would be created
     */
    private static function hasCircularDependency(int $serviceId, int $dependsOnServiceId): bool
    {
        // Get all dependencies of the target service recursively
        $visited = [];
        $toCheck = [$dependsOnServiceId];

        while (!empty($toCheck)) {
            $currentId = array_shift($toCheck);

            if ($currentId === $serviceId) {
                return true; // Circular dependency detected
            }

            if (in_array($currentId, $visited)) {
                continue;
            }

            $visited[] = $currentId;

            // Get dependencies of current service
            $dependencies = Database::select(
                'SELECT depends_on_service_id FROM service_dependencies WHERE service_id = ?',
                [$currentId]
            );

            foreach ($dependencies as $dep) {
                $toCheck[] = $dep['depends_on_service_id'];
            }
        }

        return false;
    }

    /**
     * Get service health status
     *
     * @param int $serviceId Service ID
     * @return array Health status
     */
    public static function getHealthStatus(int $serviceId): array
    {
        $instances = self::getInstances($serviceId);

        $health = [
            'total_instances' => count($instances),
            'running_instances' => 0,
            'stopped_instances' => 0,
            'unknown_instances' => 0,
            'overall_status' => 'unknown',
        ];

        foreach ($instances as $instance) {
            if ($instance['status'] === 'running') {
                $health['running_instances']++;
            } elseif ($instance['status'] === 'stopped') {
                $health['stopped_instances']++;
            } else {
                $health['unknown_instances']++;
            }
        }

        // Determine overall status
        if ($health['running_instances'] === $health['total_instances'] && $health['total_instances'] > 0) {
            $health['overall_status'] = 'healthy';
        } elseif ($health['running_instances'] > 0) {
            $health['overall_status'] = 'degraded';
        } elseif ($health['total_instances'] > 0) {
            $health['overall_status'] = 'down';
        }

        return $health;
    }

    /**
     * Get all services with health status
     *
     * @return array Services with health status
     */
    public static function getAllWithHealth(): array
    {
        $services = self::getAll();

        foreach ($services as &$service) {
            $service['health'] = self::getHealthStatus($service['id']);
            $service['tags'] = $service['tags'] ? json_decode($service['tags'], true) : [];
        }

        return $services;
    }
}
