<?php
/**
 * Dashboard API Endpoint
 *
 * Handles dashboard layout and widget data.
 *
 * Endpoints:
 * - GET  /api/dashboard.php?action=layout          Get user's dashboard layout
 * - POST /api/dashboard.php?action=layout          Save user's dashboard layout
 * - GET  /api/dashboard.php?action=widget_data     Get data for dashboard widgets
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$action = getParam('action', 'layout');

switch ($action) {
    case 'layout':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            handleGetLayout();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handleSaveLayout();
        } else {
            sendError('Method not allowed', 405);
        }
        break;

    case 'widget_data':
        handleGetWidgetData();
        break;

    case 'reset_layout':
        handleResetLayout();
        break;

    default:
        sendError('Invalid action', 400);
}

/**
 * Get user's dashboard layout
 */
function handleGetLayout()
{
    try {
        $userId = Auth::id();
        $layout = DashboardLayout::getLayout($userId);

        if (!$layout) {
            $layout = DashboardLayout::getDefaultLayout();
        }

        sendJson(['layout' => $layout]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get layout: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve layout', 500);
    }
}

/**
 * Save user's dashboard layout
 */
function handleSaveLayout()
{
    try {
        $data = getJsonInput();
        requireParams(['layout'], $data);

        $userId = Auth::id();
        DashboardLayout::saveLayout($userId, $data['layout']);

        sendJson(['success' => true]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to save layout: ' . $e->getMessage(), Auth::id());
        sendError('Failed to save layout', 500);
    }
}

/**
 * Reset layout to default
 */
function handleResetLayout()
{
    try {
        $userId = Auth::id();
        DashboardLayout::resetToDefault($userId);

        $layout = DashboardLayout::getLayout($userId);

        sendJson([
            'success' => true,
            'layout' => $layout,
        ]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to reset layout: ' . $e->getMessage(), Auth::id());
        sendError('Failed to reset layout', 500);
    }
}

/**
 * Get widget data
 */
function handleGetWidgetData()
{
    try {
        $widgetType = getParam('widget_type');

        if (!$widgetType) {
            sendError('Missing widget_type parameter', 400);
        }

        $data = null;

        switch ($widgetType) {
            case 'nodes_overview':
                $data = getNodesOverviewData();
                break;

            case 'critical_services':
                $data = getCriticalServicesData();
                break;

            case 'recent_events':
                $data = getRecentEventsData();
                break;

            case 'top_cpu_consumers':
                $data = getTopCpuConsumersData();
                break;

            case 'top_memory_consumers':
                $data = getTopMemoryConsumersData();
                break;

            case 'maintenance_windows':
                $data = getMaintenanceWindowsData();
                break;

            case 'backup_status':
                $data = getBackupStatusData();
                break;

            default:
                sendError('Unknown widget type', 400);
        }

        sendJson(['data' => $data]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get widget data: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve widget data', 500);
    }
}

/**
 * Widget data functions
 */

function getNodesOverviewData()
{
    $nodes = NodesRepository::getAll(true);
    $allResources = NodesRepository::getAllCachedResources();

    $overview = [
        'total_nodes' => count($nodes),
        'online_nodes' => count(array_filter($nodes, fn($n) => $n['last_status'] === 'online')),
        'total_vms' => count($allResources),
        'running_vms' => count(array_filter($allResources, fn($r) => $r['status'] === 'running')),
    ];

    return $overview;
}

function getCriticalServicesData()
{
    $services = ServicesRepository::getAll(['criticality' => 'critical']);
    $servicesWithHealth = [];

    foreach ($services as $service) {
        $service['health'] = ServicesRepository::getHealthStatus($service['id']);
        $service['tags'] = $service['tags'] ? json_decode($service['tags'], true) : [];
        $servicesWithHealth[] = $service;
    }

    return ['services' => $servicesWithHealth];
}

function getRecentEventsData()
{
    return ['events' => EventLogger::getRecentEvents(10)];
}

function getTopCpuConsumersData()
{
    $resources = NodesRepository::getAllCachedResources();
    $running = array_filter($resources, fn($r) => $r['status'] === 'running');

    usort($running, fn($a, $b) => ($b['cpu'] ?? 0) <=> ($a['cpu'] ?? 0));

    return ['consumers' => array_slice($running, 0, 10)];
}

function getTopMemoryConsumersData()
{
    $resources = NodesRepository::getAllCachedResources();
    $running = array_filter($resources, fn($r) => $r['status'] === 'running');

    usort($running, fn($a, $b) => ($b['mem'] ?? 0) <=> ($a['mem'] ?? 0));

    $consumers = array_map(function($r) {
        return [
            'vmid' => $r['vmid'],
            'name' => $r['name'],
            'node' => $r['pve_node_name'],
            'mem_gb' => round(($r['mem'] ?? 0) / 1073741824, 2),
            'maxmem_gb' => round(($r['maxmem'] ?? 0) / 1073741824, 2),
            'mem_percent' => $r['maxmem'] > 0 ? round(($r['mem'] / $r['maxmem']) * 100, 1) : 0,
        ];
    }, array_slice($running, 0, 10));

    return ['consumers' => $consumers];
}

function getMaintenanceWindowsData()
{
    $windows = Database::select(
        'SELECT * FROM maintenance_windows WHERE enabled = 1 ORDER BY next_occurrence ASC LIMIT 5'
    );

    return ['windows' => $windows];
}

function getBackupStatusData()
{
    $recentBackups = Database::select(
        'SELECT * FROM backup_executions ORDER BY executed_at DESC LIMIT 10'
    );

    $successCount = count(array_filter($recentBackups, fn($b) => $b['success']));
    $failCount = count($recentBackups) - $successCount;

    return [
        'recent_backups' => $recentBackups,
        'success_count' => $successCount,
        'fail_count' => $failCount,
    ];
}
