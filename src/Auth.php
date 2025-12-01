<?php
/**
 * Auth Class
 *
 * Handles user authentication, session management, and role-based access control.
 */

class Auth
{
    private static ?array $currentUser = null;
    private static array $config = [];

    /**
     * Initialize authentication system
     *
     * @param array $config Session and security configuration
     */
    public static function init(array $config): void
    {
        self::$config = $config;

        // Configure session
        if (!session_id()) {
            ini_set('session.cookie_httponly', $config['cookie_httponly'] ?? 1);
            ini_set('session.cookie_samesite', $config['cookie_samesite'] ?? 'Strict');

            if ($config['cookie_secure'] ?? false) {
                ini_set('session.cookie_secure', 1);
            }

            session_name($config['name'] ?? 'MCN_SESSION');
            session_start();
        }
    }

    /**
     * Attempt to log in a user
     *
     * @param string $username Username
     * @param string $password Password (plain text)
     * @return bool Success status
     */
    public static function login(string $username, string $password): bool
    {
        // Fetch user from database
        $user = Database::selectOne(
            'SELECT * FROM users WHERE username = ? AND enabled = 1',
            [$username]
        );

        if (!$user) {
            return false;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Create session
        self::createSession($user);

        // Update last login
        Database::update(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$user['id']]
        );

        // Log event
        EventLogger::log('user_action', 'info', "User {$username} logged in", $user['id']);

        return true;
    }

    /**
     * Create a user session
     *
     * @param array $user User data
     */
    private static function createSession(array $user): void
    {
        // Regenerate session ID for security
        session_regenerate_id(true);

        // Store user data in session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in_at'] = time();
        $_SESSION['last_activity'] = time();

        // Store session in database
        $sessionId = session_id();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        Database::query("DELETE FROM sessions WHERE id = '$sessionId'");
        Database::insert(
            'INSERT INTO sessions (id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)',
            [$sessionId, $user['id'], $ipAddress, $userAgent]
        );

        self::$currentUser = $user;
    }

    /**
     * Log out the current user
     *
     * @return bool Success status
     */
    public static function logout(): bool
    {
        if (!self::check()) {
            return false;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = session_id();

        // Remove session from database
        Database::delete('DELETE FROM sessions WHERE id = ?', [$sessionId]);

        // Log event
        if ($userId) {
            EventLogger::log('user_action', 'info', "User logged out", $userId);
        }

        // Destroy session
        $_SESSION = [];
        session_destroy();
        self::$currentUser = null;

        return true;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool True if authenticated
     */
    public static function check(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Check session timeout
        $timeout = self::$config['lifetime'] ?? 3600;
        $lastActivity = $_SESSION['last_activity'] ?? 0;

        if (time() - $lastActivity > $timeout) {
            self::logout();
            return false;
        }

        // Update last activity
        $_SESSION['last_activity'] = time();

        return true;
    }

    /**
     * Get current user data
     *
     * @return array|null User data or null if not authenticated
     */
    public static function user(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        if (!self::check()) {
            return null;
        }

        $userId = $_SESSION['user_id'];
        $user = Database::selectOne(
            'SELECT id, username, role, email FROM users WHERE id = ? AND enabled = 1',
            [$userId]
        );

        if (!$user) {
            self::logout();
            return null;
        }

        self::$currentUser = $user;
        return $user;
    }

    /**
     * Get current user ID
     *
     * @return int|null User ID or null if not authenticated
     */
    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * Get current user role
     *
     * @return string|null User role or null if not authenticated
     */
    public static function role(): ?string
    {
        $user = self::user();
        return $user['role'] ?? null;
    }

    /**
     * Check if user has a specific role
     *
     * @param string $role Role name
     * @return bool
     */
    public static function hasRole(string $role): bool
    {
        return self::role() === $role;
    }

    /**
     * Check if user is admin
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    /**
     * Check if user is operator or admin
     *
     * @return bool
     */
    public static function isOperator(): bool
    {
        $role = self::role();
        return in_array($role, ['admin', 'operator']);
    }

    /**
     * Require authentication or exit
     *
     * @param string|null $minimumRole Minimum required role (viewer, operator, admin)
     */
    public static function require(?string $minimumRole = null): void
    {
        if (!self::check()) {
            http_response_code(401);
            die(json_encode(['error' => 'Authentication required']));
        }

        if ($minimumRole !== null && !self::hasMinimumRole($minimumRole)) {
            http_response_code(403);
            die(json_encode(['error' => 'Insufficient permissions']));
        }
    }

    /**
     * Check if user has at least the specified role
     *
     * @param string $minimumRole Minimum required role
     * @return bool
     */
    public static function hasMinimumRole(string $minimumRole): bool
    {
        $role = self::role();

        $roleHierarchy = [
            'viewer' => 1,
            'operator' => 2,
            'admin' => 3,
        ];

        $userLevel = $roleHierarchy[$role] ?? 0;
        $requiredLevel = $roleHierarchy[$minimumRole] ?? 999;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Generate CSRF token
     *
     * @return string CSRF token
     */
    public static function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     *
     * @param string $token Token to verify
     * @return bool Valid status
     */
    public static function verifyCsrfToken(string $token): bool
    {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Hash a password
     *
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public static function hashPassword(string $password): string
    {
        $algorithm = self::$config['password_algorithm'] ?? PASSWORD_BCRYPT;
        $options = self::$config['password_options'] ?? ['cost' => 10];
        return password_hash($password, $algorithm, $options);
    }

    /**
     * Create a new user
     *
     * @param string $username Username
     * @param string $password Plain text password
     * @param string $role User role
     * @param string|null $email Email address
     * @return int New user ID
     */
    public static function createUser(string $username, string $password, string $role = 'viewer', ?string $email = null): int
    {
        $passwordHash = self::hashPassword($password);

        return Database::insert(
            'INSERT INTO users (username, password_hash, role, email) VALUES (?, ?, ?, ?)',
            [$username, $passwordHash, $role, $email]
        );
    }

    /**
     * Clean up old sessions
     *
     * @param int $maxAge Maximum age in seconds (default: 24 hours)
     */
    public static function cleanupOldSessions(int $maxAge = 86400): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAge);
        Database::delete('DELETE FROM sessions WHERE last_activity < ?', [$cutoff]);
    }
}
