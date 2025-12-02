<?php
/**
 * TeamManager
 *
 * Complete multi-tenancy system with team management,
 * resource quotas, and usage tracking.
 */

class TeamManager
{
    /**
     * Create a new team
     */
    public static function createTeam(string $name, ?string $description = null, array $quotas = []): int
    {
        $teamId = Database::insert(
            'INSERT INTO teams (name, description, max_vms, max_cpu_cores, max_memory_gb, max_storage_gb, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $name,
                $description,
                $quotas['max_vms'] ?? null,
                $quotas['max_cpu_cores'] ?? null,
                $quotas['max_memory_gb'] ?? null,
                $quotas['max_storage_gb'] ?? null,
            ]
        );

        EventLogger::info('team_created', "Team created: $name", null, ['team_id' => $teamId]);

        return $teamId;
    }

    /**
     * Update team details
     */
    public static function updateTeam(int $teamId, array $updates): void
    {
        $allowedFields = ['name', 'description', 'max_vms', 'max_cpu_cores', 'max_memory_gb', 'max_storage_gb', 'active'];

        $fields = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $fields[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }

        $params[] = $teamId;

        Database::update(
            'UPDATE teams SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );

        EventLogger::info('team_updated', "Team updated: ID $teamId", null, ['team_id' => $teamId]);
    }

    /**
     * Add user to team
     */
    public static function addMember(int $teamId, int $userId, string $role = 'member'): void
    {
        $validRoles = ['owner', 'admin', 'member', 'viewer'];
        if (!in_array($role, $validRoles)) {
            throw new Exception('Invalid role');
        }

        // Check if already a member
        $existing = Database::selectOne(
            'SELECT * FROM team_members WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId]
        );

        if ($existing) {
            throw new Exception('User is already a member of this team');
        }

        Database::insert(
            'INSERT INTO team_members (team_id, user_id, role, joined_at)
             VALUES (?, ?, ?, NOW())',
            [$teamId, $userId, $role]
        );

        $user = Database::selectOne('SELECT username FROM users WHERE id = ?', [$userId]);
        EventLogger::info('team_member_added', "User {$user['username']} added to team", null, [
            'team_id' => $teamId,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    /**
     * Remove user from team
     */
    public static function removeMember(int $teamId, int $userId): void
    {
        $deleted = Database::delete(
            'DELETE FROM team_members WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId]
        );

        if ($deleted === 0) {
            throw new Exception('User is not a member of this team');
        }

        EventLogger::info('team_member_removed', "User removed from team", null, [
            'team_id' => $teamId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Update member role
     */
    public static function updateMemberRole(int $teamId, int $userId, string $role): void
    {
        $validRoles = ['owner', 'admin', 'member', 'viewer'];
        if (!in_array($role, $validRoles)) {
            throw new Exception('Invalid role');
        }

        Database::update(
            'UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ?',
            [$role, $teamId, $userId]
        );

        EventLogger::info('team_member_role_updated', "Team member role updated", null, [
            'team_id' => $teamId,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    /**
     * Get team members
     */
    public static function getMembers(int $teamId): array
    {
        return Database::select(
            'SELECT tm.*, u.username, u.email, u.role as user_system_role
             FROM team_members tm
             JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ?
             ORDER BY tm.role, u.username',
            [$teamId]
        );
    }

    /**
     * Get team details
     */
    public static function getTeam(int $teamId): array
    {
        $team = Database::selectOne(
            'SELECT * FROM teams WHERE id = ?',
            [$teamId]
        );

        if (!$team) {
            throw new Exception('Team not found');
        }

        // Get current resource usage
        $team['current_usage'] = self::getResourceUsage($teamId);
        $team['members_count'] = self::getMembersCount($teamId);
        $team['resources_count'] = self::getResourcesCount($teamId);

        return $team;
    }

    /**
     * Get all teams
     */
    public static function getAllTeams(?bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';

        $teams = Database::select(
            "SELECT * FROM teams $where ORDER BY name"
        );

        foreach ($teams as &$team) {
            $team['members_count'] = self::getMembersCount($team['id']);
            $team['resources_count'] = self::getResourcesCount($team['id']);
            $team['current_usage'] = self::getResourceUsage($team['id']);
        }

        return $teams;
    }

    /**
     * Get current resource usage for team
     */
    public static function getResourceUsage(int $teamId): array
    {
        // Get latest resource usage snapshots
        $usage = Database::selectOne(
            'SELECT
                COUNT(DISTINCT tr.vmid) as vm_count,
                COALESCE(SUM(rus.cpu_cores), 0) as total_cpu,
                COALESCE(SUM(rus.memory_gb), 0) as total_memory,
                COALESCE(SUM(rus.storage_gb), 0) as total_storage
             FROM team_resources tr
             LEFT JOIN (
                SELECT pve_node_id, vmid, cpu_cores, memory_gb, storage_gb
                FROM resource_usage_snapshots rus1
                WHERE id = (
                    SELECT id FROM resource_usage_snapshots rus2
                    WHERE rus2.pve_node_id = rus1.pve_node_id AND rus2.vmid = rus1.vmid
                    ORDER BY snapshot_at DESC LIMIT 1
                )
             ) rus ON rus.pve_node_id = tr.pve_node_id AND rus.vmid = tr.vmid
             WHERE tr.team_id = ?',
            [$teamId]
        );

        return [
            'vm_count' => (int)$usage['vm_count'],
            'cpu_cores' => round((float)$usage['total_cpu'], 2),
            'memory_gb' => round((float)$usage['total_memory'], 2),
            'storage_gb' => round((float)$usage['total_storage'], 2),
        ];
    }

    /**
     * Check if team has exceeded quotas
     */
    public static function checkQuotas(int $teamId): array
    {
        $team = Database::selectOne(
            'SELECT * FROM teams WHERE id = ?',
            [$teamId]
        );

        if (!$team) {
            throw new Exception('Team not found');
        }

        $usage = self::getResourceUsage($teamId);

        $violations = [];

        if ($team['max_vms'] && $usage['vm_count'] > $team['max_vms']) {
            $violations[] = [
                'type' => 'vm_count',
                'limit' => $team['max_vms'],
                'current' => $usage['vm_count'],
                'exceeded_by' => $usage['vm_count'] - $team['max_vms'],
            ];
        }

        if ($team['max_cpu_cores'] && $usage['cpu_cores'] > $team['max_cpu_cores']) {
            $violations[] = [
                'type' => 'cpu_cores',
                'limit' => $team['max_cpu_cores'],
                'current' => $usage['cpu_cores'],
                'exceeded_by' => $usage['cpu_cores'] - $team['max_cpu_cores'],
            ];
        }

        if ($team['max_memory_gb'] && $usage['memory_gb'] > $team['max_memory_gb']) {
            $violations[] = [
                'type' => 'memory_gb',
                'limit' => $team['max_memory_gb'],
                'current' => $usage['memory_gb'],
                'exceeded_by' => $usage['memory_gb'] - $team['max_memory_gb'],
            ];
        }

        if ($team['max_storage_gb'] && $usage['storage_gb'] > $team['max_storage_gb']) {
            $violations[] = [
                'type' => 'storage_gb',
                'limit' => $team['max_storage_gb'],
                'current' => $usage['storage_gb'],
                'exceeded_by' => $usage['storage_gb'] - $team['max_storage_gb'],
            ];
        }

        return [
            'team_id' => $teamId,
            'team_name' => $team['name'],
            'has_violations' => !empty($violations),
            'violations' => $violations,
            'usage' => $usage,
            'quotas' => [
                'max_vms' => $team['max_vms'],
                'max_cpu_cores' => $team['max_cpu_cores'],
                'max_memory_gb' => $team['max_memory_gb'],
                'max_storage_gb' => $team['max_storage_gb'],
            ],
        ];
    }

    /**
     * Assign resource (VM) to team
     */
    public static function assignResource(int $teamId, int $pveNodeId, int $vmid): void
    {
        // Check if already assigned
        $existing = Database::selectOne(
            'SELECT * FROM team_resources WHERE team_id = ? AND pve_node_id = ? AND vmid = ?',
            [$teamId, $pveNodeId, $vmid]
        );

        if ($existing) {
            throw new Exception('Resource already assigned to this team');
        }

        Database::insert(
            'INSERT INTO team_resources (team_id, pve_node_id, vmid, allocated_at)
             VALUES (?, ?, ?, NOW())',
            [$teamId, $pveNodeId, $vmid]
        );

        EventLogger::info('team_resource_assigned', "Resource assigned to team", null, [
            'team_id' => $teamId,
            'pve_node_id' => $pveNodeId,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Unassign resource from team
     */
    public static function unassignResource(int $teamId, int $pveNodeId, int $vmid): void
    {
        $deleted = Database::delete(
            'DELETE FROM team_resources WHERE team_id = ? AND pve_node_id = ? AND vmid = ?',
            [$teamId, $pveNodeId, $vmid]
        );

        if ($deleted === 0) {
            throw new Exception('Resource not assigned to this team');
        }

        EventLogger::info('team_resource_unassigned', "Resource unassigned from team", null, [
            'team_id' => $teamId,
            'pve_node_id' => $pveNodeId,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Get team resources
     */
    public static function getResources(int $teamId): array
    {
        return Database::select(
            'SELECT tr.*, pn.name as node_name, prc.name as vm_name, prc.type, prc.status
             FROM team_resources tr
             JOIN pve_nodes pn ON pn.id = tr.pve_node_id
             LEFT JOIN pve_resources_cache prc ON prc.pve_node_id = tr.pve_node_id AND prc.vmid = tr.vmid
             WHERE tr.team_id = ?
             ORDER BY pn.name, tr.vmid',
            [$teamId]
        );
    }

    /**
     * Get teams for user
     */
    public static function getUserTeams(int $userId): array
    {
        return Database::select(
            'SELECT t.*, tm.role as user_role
             FROM teams t
             JOIN team_members tm ON tm.team_id = t.id
             WHERE tm.user_id = ? AND t.active = 1
             ORDER BY t.name',
            [$userId]
        );
    }

    /**
     * Check if user has access to team
     */
    public static function hasAccess(int $userId, int $teamId, ?string $minimumRole = null): bool
    {
        $member = Database::selectOne(
            'SELECT * FROM team_members WHERE user_id = ? AND team_id = ?',
            [$userId, $teamId]
        );

        if (!$member) {
            return false;
        }

        if (!$minimumRole) {
            return true;
        }

        $roleHierarchy = [
            'viewer' => 1,
            'member' => 2,
            'admin' => 3,
            'owner' => 4,
        ];

        return ($roleHierarchy[$member['role']] ?? 0) >= ($roleHierarchy[$minimumRole] ?? 0);
    }

    /**
     * Get members count
     */
    private static function getMembersCount(int $teamId): int
    {
        $result = Database::selectOne(
            'SELECT COUNT(*) as count FROM team_members WHERE team_id = ?',
            [$teamId]
        );

        return (int)$result['count'];
    }

    /**
     * Get resources count
     */
    private static function getResourcesCount(int $teamId): int
    {
        $result = Database::selectOne(
            'SELECT COUNT(*) as count FROM team_resources WHERE team_id = ?',
            [$teamId]
        );

        return (int)$result['count'];
    }

    /**
     * Get quota utilization percentage
     */
    public static function getQuotaUtilization(int $teamId): array
    {
        $team = Database::selectOne(
            'SELECT * FROM teams WHERE id = ?',
            [$teamId]
        );

        if (!$team) {
            throw new Exception('Team not found');
        }

        $usage = self::getResourceUsage($teamId);

        $utilization = [];

        if ($team['max_vms']) {
            $utilization['vms'] = round(($usage['vm_count'] / $team['max_vms']) * 100, 1);
        }

        if ($team['max_cpu_cores']) {
            $utilization['cpu'] = round(($usage['cpu_cores'] / $team['max_cpu_cores']) * 100, 1);
        }

        if ($team['max_memory_gb']) {
            $utilization['memory'] = round(($usage['memory_gb'] / $team['max_memory_gb']) * 100, 1);
        }

        if ($team['max_storage_gb']) {
            $utilization['storage'] = round(($usage['storage_gb'] / $team['max_storage_gb']) * 100, 1);
        }

        return $utilization;
    }

    /**
     * Delete team (soft delete)
     */
    public static function deleteTeam(int $teamId): void
    {
        // Check if team has resources
        $resourceCount = self::getResourcesCount($teamId);

        if ($resourceCount > 0) {
            throw new Exception("Cannot delete team with assigned resources. Unassign $resourceCount resources first.");
        }

        // Deactivate team
        Database::update(
            'UPDATE teams SET active = 0 WHERE id = ?',
            [$teamId]
        );

        EventLogger::info('team_deleted', "Team deactivated: ID $teamId", null, ['team_id' => $teamId]);
    }
}
