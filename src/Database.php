<?php
/**
 * Database Class
 *
 * PDO-based database wrapper with connection pooling and query helpers.
 * Provides a simple interface for database operations with prepared statements.
 */

class Database
{
    private static ?PDO $connection = null;
    private static array $config = [];

    /**
     * Initialize the database connection
     *
     * @param array $config Database configuration
     * @throws PDOException
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get database connection (singleton pattern)
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            if (empty(self::$config)) {
                throw new RuntimeException('Database not initialized. Call Database::init() first.');
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                self::$config['host'],
                self::$config['port'],
                self::$config['database'],
                self::$config['charset']
            );

            self::$connection = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                self::$config['options'] ?? []
            );
        }

        return self::$connection;
    }

    /**
     * Execute a SELECT query
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return array Array of results
     */
    public static function select(string $query, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SELECT query and return a single row
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return array|null Single row or null if not found
     */
    public static function selectOne(string $query, array $params = []): ?array
    {
        $stmt = self::getConnection()->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT query
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return int Last insert ID
     */
    public static function insert(string $query, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($query);
        $stmt->execute($params);
        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * Execute an UPDATE query
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return int Number of affected rows
     */
    public static function update(string $query, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Execute a DELETE query
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return int Number of affected rows
     */
    public static function delete(string $query, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Execute a raw query (for DDL or complex queries)
     *
     * @param string $query SQL query
     * @return bool Success status
     */
    public static function query(string $query): bool
    {
        return self::getConnection()->exec($query) !== false;
    }

    /**
     * Begin a transaction
     *
     * @return bool Success status
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit a transaction
     *
     * @return bool Success status
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback a transaction
     *
     * @return bool Success status
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Check if currently in a transaction
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Prepare a statement for execution
     *
     * @param string $query SQL query with placeholders
     * @return PDOStatement
     */
    public static function prepare(string $query): PDOStatement
    {
        return self::getConnection()->prepare($query);
    }

    /**
     * Close the database connection
     */
    public static function close(): void
    {
        self::$connection = null;
    }

    /**
     * Build a WHERE clause from conditions array
     *
     * @param array $conditions Associative array of column => value
     * @return array [sql string, params array]
     */
    public static function buildWhereClause(array $conditions): array
    {
        if (empty($conditions)) {
            return ['', []];
        }

        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $where[] = "$column IN ($placeholders)";
                $params = array_merge($params, $value);
            } elseif ($value === null) {
                $where[] = "$column IS NULL";
            } else {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Simple table insert helper
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    public static function insertInto(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO $table ($columns) VALUES ($placeholders)";

        return self::insert($query, array_values($data));
    }

    /**
     * Simple table update helper
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value to update
     * @param array $conditions WHERE conditions
     * @return int Number of affected rows
     */
    public static function updateTable(string $table, array $data, array $conditions): int
    {
        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[] = "$column = ?";
            $params[] = $value;
        }

        [$whereClause, $whereParams] = self::buildWhereClause($conditions);
        $params = array_merge($params, $whereParams);

        $query = "UPDATE $table SET " . implode(', ', $sets) . $whereClause;

        return self::update($query, $params);
    }

    /**
     * Simple table delete helper
     *
     * @param string $table Table name
     * @param array $conditions WHERE conditions
     * @return int Number of affected rows
     */
    public static function deleteFrom(string $table, array $conditions): int
    {
        [$whereClause, $params] = self::buildWhereClause($conditions);
        $query = "DELETE FROM $table" . $whereClause;

        return self::delete($query, $params);
    }
}
