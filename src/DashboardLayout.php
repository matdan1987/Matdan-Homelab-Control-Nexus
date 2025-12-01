<?php
/**
 * DashboardLayout Class
 *
 * Manages dashboard widget layouts and user preferences.
 */

class DashboardLayout
{
    /**
     * Get user's dashboard layout
     *
     * @param int $userId User ID
     * @return array|null Layout configuration
     */
    public static function getLayout(int $userId): ?array
    {
        $layout = Database::selectOne(
            'SELECT layout_json FROM dashboard_layouts WHERE user_id = ?',
            [$userId]
        );

        if (!$layout) {
            return null;
        }

        return json_decode($layout['layout_json'], true);
    }

    /**
     * Save user's dashboard layout
     *
     * @param int $userId User ID
     * @param array $layout Layout configuration
     * @return bool Success status
     */
    public static function saveLayout(int $userId, array $layout): bool
    {
        $layoutJson = json_encode($layout);

        // Check if layout exists
        $existing = Database::selectOne(
            'SELECT id FROM dashboard_layouts WHERE user_id = ?',
            [$userId]
        );

        if ($existing) {
            Database::update(
                'UPDATE dashboard_layouts SET layout_json = ?, updated_at = NOW() WHERE user_id = ?',
                [$layoutJson, $userId]
            );
        } else {
            Database::insert(
                'INSERT INTO dashboard_layouts (user_id, layout_json) VALUES (?, ?)',
                [$userId, $layoutJson]
            );
        }

        return true;
    }

    /**
     * Get default dashboard layout
     *
     * @return array Default layout
     */
    public static function getDefaultLayout(): array
    {
        // This can be configured via config or database
        return [
            [
                'type' => 'nodes_overview',
                'position' => 0,
                'size' => 'large',
            ],
            [
                'type' => 'critical_services',
                'position' => 1,
                'size' => 'medium',
            ],
            [
                'type' => 'recent_events',
                'position' => 2,
                'size' => 'medium',
            ],
            [
                'type' => 'top_cpu_consumers',
                'position' => 3,
                'size' => 'medium',
            ],
        ];
    }

    /**
     * Reset user's dashboard to default
     *
     * @param int $userId User ID
     * @return bool Success status
     */
    public static function resetToDefault(int $userId): bool
    {
        $defaultLayout = self::getDefaultLayout();
        return self::saveLayout($userId, $defaultLayout);
    }

    /**
     * Get user preference
     *
     * @param int $userId User ID
     * @param string $key Preference key
     * @param mixed $default Default value if not found
     * @return mixed Preference value
     */
    public static function getPreference(int $userId, string $key, $default = null)
    {
        $pref = Database::selectOne(
            'SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?',
            [$userId, $key]
        );

        return $pref ? $pref['preference_value'] : $default;
    }

    /**
     * Set user preference
     *
     * @param int $userId User ID
     * @param string $key Preference key
     * @param mixed $value Preference value
     * @return bool Success status
     */
    public static function setPreference(int $userId, string $key, $value): bool
    {
        $existing = Database::selectOne(
            'SELECT id FROM user_preferences WHERE user_id = ? AND preference_key = ?',
            [$userId, $key]
        );

        if ($existing) {
            Database::update(
                'UPDATE user_preferences SET preference_value = ?, updated_at = NOW() WHERE user_id = ? AND preference_key = ?',
                [$value, $userId, $key]
            );
        } else {
            Database::insert(
                'INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)',
                [$userId, $key, $value]
            );
        }

        return true;
    }
}
