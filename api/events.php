<?php
/**
 * Events API Endpoint
 *
 * Handles event logging and retrieval.
 *
 * Endpoints:
 * - GET /api/events.php              List recent events
 * - GET /api/events.php?id=1         Get specific event
 * - GET /api/events.php?action=stats Get event statistics
 */

require_once __DIR__ . '/init.php';

// Require authentication
Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$id = getParam('id');
$action = getParam('action');

if ($action === 'stats') {
    handleGetStatistics();
} elseif ($method === 'GET' && $id) {
    handleGetEvent($id);
} elseif ($method === 'GET') {
    handleListEvents();
} else {
    sendError('Invalid request', 400);
}

/**
 * List events
 */
function handleListEvents()
{
    try {
        $limit = (int) getParam('limit', 100);
        $limit = min($limit, 1000); // Max 1000 events

        $filters = [];

        if ($type = getParam('type')) {
            $filters['type'] = $type;
        }

        if ($severity = getParam('severity')) {
            $filters['severity'] = $severity;
        }

        if ($userId = getParam('user_id')) {
            $filters['user_id'] = $userId;
        }

        $events = EventLogger::getRecentEvents($limit, $filters);

        sendJson(['events' => $events]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to list events: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve events', 500);
    }
}

/**
 * Get specific event
 */
function handleGetEvent($id)
{
    try {
        $event = Database::selectOne(
            'SELECT e.*, u.username
             FROM events e
             LEFT JOIN users u ON e.user_id = u.id
             WHERE e.id = ?',
            [(int) $id]
        );

        if (!$event) {
            sendError('Event not found', 404);
        }

        // Decode context JSON
        if ($event['context_json']) {
            $event['context'] = json_decode($event['context_json'], true);
        }

        sendJson($event);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get event: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve event', 500);
    }
}

/**
 * Get event statistics
 */
function handleGetStatistics()
{
    try {
        $timeframe = getParam('timeframe', 'day');

        $stats = EventLogger::getStatistics($timeframe);

        sendJson(['statistics' => $stats]);
    } catch (Exception $e) {
        EventLogger::error('api_error', 'Failed to get statistics: ' . $e->getMessage(), Auth::id());
        sendError('Failed to retrieve statistics', 500);
    }
}
