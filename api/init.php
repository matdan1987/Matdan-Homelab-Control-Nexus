<?php
/**
 * API Initialization File
 *
 * This file bootstraps the application for API endpoints.
 * Include this file at the beginning of each API endpoint.
 */

// Prevent direct access to config
define('MCN_CONFIG', true);

// Start output buffering
ob_start();

// Set error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Set CORS headers if needed (adjust for your environment)
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
// header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoloader for classes
spl_autoload_register(function ($className) use ($config) {
    $srcDir = $config['paths']['src'];
    $classFile = $srcDir . '/' . $className . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }

    // Check in Integrations subdirectory
    $integrationsFile = $srcDir . '/Integrations/' . $className . '.php';
    if (file_exists($integrationsFile)) {
        require_once $integrationsFile;
    }
});

// Initialize Database
try {
    Database::init($config['database']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Initialize Authentication
Auth::init($config['session']);

// Initialize Event Logger
EventLogger::init($config['logging']);

// Error handler for JSON responses
set_exception_handler(function ($exception) use ($config) {
    // Log the error
    EventLogger::error('system_error', $exception->getMessage(), Auth::id(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);

    // Send JSON error response
    http_response_code(500);

    $response = ['error' => 'Internal server error'];

    // Include details in debug mode
    if ($config['app']['debug']) {
        $response['debug'] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    echo json_encode($response);
    exit;
});

// Helper function to send JSON response
function sendJson($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to send error response
function sendError($message, $statusCode = 400, $details = null)
{
    $response = ['error' => $message];
    if ($details !== null) {
        $response['details'] = $details;
    }
    sendJson($response, $statusCode);
}

// Helper function to get JSON input
function getJsonInput()
{
    $input = file_get_contents('php://input');
    $decoded = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON input');
    }

    return $decoded ?? [];
}

// Helper function to get request parameter (from GET, POST, or JSON body)
function getParam($key, $default = null)
{
    // Check GET
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    // Check POST
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    // Check JSON body
    static $jsonInput = null;
    if ($jsonInput === null) {
        $input = file_get_contents('php://input');
        $jsonInput = json_decode($input, true) ?? [];
    }

    return $jsonInput[$key] ?? $default;
}

// Helper function to validate required parameters
function requireParams(array $params, array $data)
{
    $missing = [];

    foreach ($params as $param) {
        if (!isset($data[$param]) || $data[$param] === '' || $data[$param] === null) {
            $missing[] = $param;
        }
    }

    if (!empty($missing)) {
        sendError('Missing required parameters: ' . implode(', ', $missing), 400);
    }
}

// Helper function to sanitize input
function sanitizeInput($input)
{
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }

    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
