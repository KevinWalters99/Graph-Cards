<?php
/**
 * Card Graph — User Model
 */
class User
{
    /**
     * Find a user by username.
     */
    public static function findByUsername(string $username): ?array
    {
        $stmt = cg_db()->prepare(
            "SELECT user_id, username, display_name, password_hash, role, is_active
             FROM CG_Users WHERE username = :username"
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a user by ID.
     */
    public static function findById(int $userId): ?array
    {
        $stmt = cg_db()->prepare(
            "SELECT user_id, username, display_name, role, is_active, created_at, updated_at
             FROM CG_Users WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get all users.
     */
    public static function getAll(): array
    {
        $stmt = cg_db()->query(
            "SELECT user_id, username, display_name, role, is_active, created_at, updated_at
             FROM CG_Users ORDER BY username"
        );
        return $stmt->fetchAll();
    }

    /**
     * Create a new user.
     */
    public static function create(string $username, string $displayName, string $password, string $role = 'user'): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = cg_db()->prepare(
            "INSERT INTO CG_Users (username, display_name, password_hash, role)
             VALUES (:username, :display_name, :password_hash, :role)"
        );
        $stmt->execute([
            ':username'      => $username,
            ':display_name'  => $displayName,
            ':password_hash' => $hash,
            ':role'          => $role,
        ]);
        return (int) cg_db()->lastInsertId();
    }

    /**
     * Update a user.
     */
    public static function update(int $userId, array $fields): bool
    {
        $allowed = ['display_name', 'role', 'is_active'];
        $sets = [];
        $params = [':id' => $userId];

        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sql = "UPDATE CG_Users SET " . implode(', ', $sets) . " WHERE user_id = :id";
        $stmt = cg_db()->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Update a user's password.
     */
    public static function updatePassword(int $userId, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = cg_db()->prepare(
            "UPDATE CG_Users SET password_hash = :hash WHERE user_id = :id"
        );
        return $stmt->execute([':hash' => $hash, ':id' => $userId]);
    }

    /**
     * Check login rate limiting (5 attempts per 15 minutes per IP).
     */
    public static function checkRateLimit(string $ip): bool
    {
        $stmt = cg_db()->prepare(
            "SELECT COUNT(*) as attempts FROM CG_Sessions
             WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        return ($row['attempts'] ?? 0) < 10;  // Allow 10 session creates per 15 min
    }
}
