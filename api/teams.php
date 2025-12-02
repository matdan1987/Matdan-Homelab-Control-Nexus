<?php
/**
 * Teams API Endpoint
 *
 * Handles team management, members, resources, and quotas.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../src/TeamManager.php';

// Require authentication
Auth::require();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            // Create team (admin only)
            Auth::require('admin');

            $name = getParam('name', true);
            $description = getParam('description', false);
            $quotas = [];

            $quotaFields = ['max_vms', 'max_cpu_cores', 'max_memory_gb', 'max_storage_gb'];
            foreach ($quotaFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    $quotas[$field] = $value;
                }
            }

            $teamId = TeamManager::createTeam($name, $description, $quotas);

            jsonResponse([
                'id' => $teamId,
                'message' => 'Team created successfully'
            ]);
            break;

        case 'update':
            // Update team (admin only)
            Auth::require('admin');

            $teamId = getParam('id', true);

            $updates = [];
            $allowedFields = ['name', 'description', 'max_vms', 'max_cpu_cores', 'max_memory_gb', 'max_storage_gb', 'active'];

            foreach ($allowedFields as $field) {
                $value = getParam($field, false);
                if ($value !== null) {
                    $updates[$field] = $value;
                }
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            TeamManager::updateTeam($teamId, $updates);

            jsonResponse(['message' => 'Team updated successfully']);
            break;

        case 'delete':
            // Delete team (admin only)
            Auth::require('admin');

            $teamId = getParam('id', true);

            TeamManager::deleteTeam($teamId);

            jsonResponse(['message' => 'Team deleted successfully']);
            break;

        case 'get':
            // Get team details
            $teamId = getParam('id', true);

            $team = TeamManager::getTeam($teamId);

            jsonResponse($team);
            break;

        case 'list':
            // List all teams
            $activeOnly = getParam('active_only', false) !== '0';

            $teams = TeamManager::getAllTeams($activeOnly);

            jsonResponse($teams);
            break;

        case 'add_member':
            // Add member to team (admin or team owner)
            $teamId = getParam('team_id', true);
            $userId = getParam('user_id', true);
            $role = getParam('role', false) ?: 'member';

            // Check if user is admin or team owner
            $currentUserId = $_SESSION['user_id'];
            $isAdmin = Auth::hasRole('admin');
            $isOwner = TeamManager::hasAccess($currentUserId, $teamId, 'owner');

            if (!$isAdmin && !$isOwner) {
                throw new Exception('Insufficient permissions');
            }

            TeamManager::addMember($teamId, $userId, $role);

            jsonResponse(['message' => 'Member added successfully']);
            break;

        case 'remove_member':
            // Remove member from team (admin or team owner)
            $teamId = getParam('team_id', true);
            $userId = getParam('user_id', true);

            // Check if user is admin or team owner
            $currentUserId = $_SESSION['user_id'];
            $isAdmin = Auth::hasRole('admin');
            $isOwner = TeamManager::hasAccess($currentUserId, $teamId, 'owner');

            if (!$isAdmin && !$isOwner) {
                throw new Exception('Insufficient permissions');
            }

            TeamManager::removeMember($teamId, $userId);

            jsonResponse(['message' => 'Member removed successfully']);
            break;

        case 'update_member_role':
            // Update member role (admin or team owner)
            $teamId = getParam('team_id', true);
            $userId = getParam('user_id', true);
            $role = getParam('role', true);

            // Check if user is admin or team owner
            $currentUserId = $_SESSION['user_id'];
            $isAdmin = Auth::hasRole('admin');
            $isOwner = TeamManager::hasAccess($currentUserId, $teamId, 'owner');

            if (!$isAdmin && !$isOwner) {
                throw new Exception('Insufficient permissions');
            }

            TeamManager::updateMemberRole($teamId, $userId, $role);

            jsonResponse(['message' => 'Member role updated successfully']);
            break;

        case 'members':
            // Get team members
            $teamId = getParam('team_id', true);

            $members = TeamManager::getMembers($teamId);

            jsonResponse($members);
            break;

        case 'assign_resource':
            // Assign resource to team (admin or team admin)
            $teamId = getParam('team_id', true);
            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);

            // Check if user is admin or team admin
            $currentUserId = $_SESSION['user_id'];
            $isAdmin = Auth::hasRole('admin');
            $isTeamAdmin = TeamManager::hasAccess($currentUserId, $teamId, 'admin');

            if (!$isAdmin && !$isTeamAdmin) {
                throw new Exception('Insufficient permissions');
            }

            TeamManager::assignResource($teamId, $pveNodeId, $vmid);

            jsonResponse(['message' => 'Resource assigned successfully']);
            break;

        case 'unassign_resource':
            // Unassign resource from team (admin or team admin)
            $teamId = getParam('team_id', true);
            $pveNodeId = getParam('pve_node_id', true);
            $vmid = getParam('vmid', true);

            // Check if user is admin or team admin
            $currentUserId = $_SESSION['user_id'];
            $isAdmin = Auth::hasRole('admin');
            $isTeamAdmin = TeamManager::hasAccess($currentUserId, $teamId, 'admin');

            if (!$isAdmin && !$isTeamAdmin) {
                throw new Exception('Insufficient permissions');
            }

            TeamManager::unassignResource($teamId, $pveNodeId, $vmid);

            jsonResponse(['message' => 'Resource unassigned successfully']);
            break;

        case 'resources':
            // Get team resources
            $teamId = getParam('team_id', true);

            $resources = TeamManager::getResources($teamId);

            jsonResponse($resources);
            break;

        case 'usage':
            // Get team resource usage
            $teamId = getParam('team_id', true);

            $usage = TeamManager::getResourceUsage($teamId);

            jsonResponse($usage);
            break;

        case 'quotas':
            // Check team quota compliance
            $teamId = getParam('team_id', true);

            $quotaCheck = TeamManager::checkQuotas($teamId);

            jsonResponse($quotaCheck);
            break;

        case 'utilization':
            // Get quota utilization percentage
            $teamId = getParam('team_id', true);

            $utilization = TeamManager::getQuotaUtilization($teamId);

            jsonResponse($utilization);
            break;

        case 'my_teams':
            // Get teams for current user
            $userId = $_SESSION['user_id'];

            $teams = TeamManager::getUserTeams($userId);

            jsonResponse($teams);
            break;

        case 'check_access':
            // Check if user has access to team
            $teamId = getParam('team_id', true);
            $minimumRole = getParam('minimum_role', false);
            $userId = $_SESSION['user_id'];

            $hasAccess = TeamManager::hasAccess($userId, $teamId, $minimumRole);

            jsonResponse([
                'has_access' => $hasAccess,
                'user_id' => $userId,
                'team_id' => $teamId,
                'minimum_role' => $minimumRole,
            ]);
            break;

        case 'quota_violations':
            // Get all teams with quota violations (admin only)
            Auth::require('admin');

            $teams = TeamManager::getAllTeams(true);
            $violations = [];

            foreach ($teams as $team) {
                $quotaCheck = TeamManager::checkQuotas($team['id']);
                if ($quotaCheck['has_violations']) {
                    $violations[] = $quotaCheck;
                }
            }

            jsonResponse([
                'total_teams' => count($teams),
                'teams_with_violations' => count($violations),
                'violations' => $violations,
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse([
        'error' => $e->getMessage()
    ]);
}
