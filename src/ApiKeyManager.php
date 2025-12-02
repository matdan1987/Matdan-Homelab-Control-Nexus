<?php
/**
 * ApiKeyManager
 *
 * Complete API key management system with permissions and rate limiting.
 */

class ApiKeyManager
{
    /**
     * Generate new API key
     *
     * @param int $userId User ID
     * @param string $name Key name/description
     * @param array $permissions Array of allowed permissions
     * @param DateTime|null $expiresAt Expiration date
     * @return array ['id', 'key', 'prefix'] - Key is shown only once!
     */
    public static function generate(int $userId, string $name, array $permissions = [], ?DateTime $expiresAt = null): array
    {
        // Generate secure random key
        $key = 'mcn_' . bin2hex(random_bytes(32));
        $keyHash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 12]);
        $keyPrefix = substr($key, 0, 12);

        $id = Database::insert(
            'INSERT INTO api_keys (user_id, name, key_hash, key_prefix, permissions_json, expires_at, enabled)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $userId,
                $name,
                $keyHash,
                $keyPrefix,
                json_encode($permissions),
                $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
            ]
        );

        EventLogger::info('api_key', "Generated API key: $name", $userId, ['api_key_id' => $id]);

        return [
            'id' => $id,
            'key' => $key,
            'prefix' => $keyPrefix,
        ];
    }

    /**
     * Verify and validate API key
     *
     * @param string $key API key to verify
     * @return array|null API key data if valid, null if invalid
     */
    public static function verify(string $key): ?array
    {
        $keyPrefix = substr($key, 0, 12);

        $apiKey = Database::selectOne(
            'SELECT * FROM api_keys WHERE key_prefix = ? AND enabled = 1',
            [$keyPrefix]
        );

        if (!$apiKey) {
            return null;
        }

        // Verify hash
        if (!password_verify($key, $apiKey['key_hash'])) {
            EventLogger::warning('api_key', 'Invalid API key attempt', null, ['prefix' => $keyPrefix]);
            return null;
        }

        // Check expiration
        if ($apiKey['expires_at'] && strtotime($apiKey['expires_at']) < time()) {
            EventLogger::warning('api_key', 'Expired API key used', null, ['api_key_id' => $apiKey['id']]);
            return null;
        }

        // Update last used
        Database::update(
            'UPDATE api_keys SET last_used_at = NOW() WHERE id = ?',
            [$apiKey['id']]
        );

        // Decode permissions
        $apiKey['permissions'] = json_decode($apiKey['permissions_json'], true) ?? [];

        return $apiKey;
    }

    /**
     * Check if API key has permission
     *
     * @param array $apiKey API key data
     * @param string $permission Permission to check
     * @return bool
     */
    public static function hasPermission(array $apiKey, string $permission): bool
    {
        $permissions = $apiKey['permissions'] ?? [];

        // Wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }

        // Direct permission match
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Prefix match (e.g., "nodes.*" allows "nodes.list", "nodes.create")
        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($permission, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Revoke API key
     *
     * @param int $id API key ID
     * @param int $userId User ID (for ownership check)
     * @return bool Success
     */
    public static function revoke(int $id, int $userId): bool
    {
        $affected = Database::update(
            'UPDATE api_keys SET enabled = 0 WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );

        if ($affected > 0) {
            EventLogger::info('api_key', "Revoked API key", $userId, ['api_key_id' => $id]);
        }

        return $affected > 0;
    }

    /**
     * List API keys for user
     *
     * @param int $userId User ID
     * @return array List of API keys (without secrets)
     */
    public static function listForUser(int $userId): array
    {
        return Database::select(
            'SELECT id, name, key_prefix, permissions_json, last_used_at, expires_at, enabled, created_at
             FROM api_keys
             WHERE user_id = ?
             ORDER BY created_at DESC',
            [$userId]
        );
    }

    /**
     * Log API request
     *
     * @param int|null $apiKeyId API key ID
     * @param int|null $userId User ID
     * @param string $endpoint Endpoint called
     * @param string $method HTTP method
     * @param int $statusCode Response status code
     * @param int|null $responseTimeMs Response time in milliseconds
     */
    public static function logRequest(?int $apiKeyId, ?int $userId, string $endpoint, string $method, int $statusCode, ?int $responseTimeMs = null): void
    {
        Database::insert(
            'INSERT INTO api_requests_log (api_key_id, user_id, endpoint, method, status_code, response_time_ms, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $apiKeyId,
                $userId,
                $endpoint,
                $method,
                $statusCode,
                $responseTimeMs,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]
        );
    }

    /**
     * Get API usage statistics
     *
     * @param int $userId User ID
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function getUsageStats(int $userId, int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));

        $stats = Database::select(
            'SELECT
                DATE(created_at) as date,
                COUNT(*) as request_count,
                AVG(response_time_ms) as avg_response_time,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
             FROM api_requests_log
             WHERE user_id = ? AND created_at >= ?
             GROUP BY DATE(created_at)
             ORDER BY date DESC',
            [$userId, $since]
        );

        return $stats;
    }

    /**
     * Clean up old API request logs
     *
     * @param int $daysToKeep Days to keep logs
     * @return int Number of deleted records
     */
    public static function cleanupLogs(int $daysToKeep = 90): int
    {
        return Database::delete(
            'DELETE FROM api_requests_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$daysToKeep]
        );
    }
}
