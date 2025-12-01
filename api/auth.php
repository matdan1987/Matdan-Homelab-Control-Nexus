<?php
/**
 * Authentication API Endpoint
 *
 * Handles login, logout, and session checking.
 *
 * Endpoints:
 * - POST /api/auth.php?action=login
 * - POST /api/auth.php?action=logout
 * - GET  /api/auth.php?action=check
 * - GET  /api/auth.php?action=user
 */

require_once __DIR__ . '/init.php';

$action = getParam('action', 'check');

switch ($action) {
    case 'login':
        handleLogin();
        break;

    case 'logout':
        handleLogout();
        break;

    case 'check':
        handleCheck();
        break;

    case 'user':
        handleGetUser();
        break;

    default:
        sendError('Invalid action', 400);
}

/**
 * Handle login request
 */
function handleLogin()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }

    $data = getJsonInput();
    requireParams(['username', 'password'], $data);

    $username = sanitizeInput($data['username']);
    $password = $data['password']; // Don't sanitize passwords

    try {
        $success = Auth::login($username, $password);

        if ($success) {
            $user = Auth::user();
            sendJson([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'email' => $user['email'],
                ],
            ]);
        } else {
            sendError('Invalid username or password', 401);
        }
    } catch (Exception $e) {
        EventLogger::error('auth_error', 'Login failed: ' . $e->getMessage(), null, [
            'username' => $username
        ]);
        sendError('Login failed', 500);
    }
}

/**
 * Handle logout request
 */
function handleLogout()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }

    try {
        Auth::logout();
        sendJson(['success' => true]);
    } catch (Exception $e) {
        sendError('Logout failed', 500);
    }
}

/**
 * Handle session check request
 */
function handleCheck()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed', 405);
    }

    $authenticated = Auth::check();

    sendJson([
        'authenticated' => $authenticated,
        'user' => $authenticated ? Auth::user() : null,
    ]);
}

/**
 * Handle get current user request
 */
function handleGetUser()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed', 405);
    }

    Auth::require();

    $user = Auth::user();

    sendJson([
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'email' => $user['email'],
    ]);
}
