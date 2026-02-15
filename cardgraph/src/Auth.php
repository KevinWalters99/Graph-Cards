<?php
/**
 * Card Graph — Authentication Helper
 *
 * Manages session validation and current user resolution.
 */
class Auth
{
    private static ?array $currentUser = null;
    private static ?string $sessionId = null;

    /**
     * Get the currently authenticated user, or null if not logged in.
     */
    public static function getCurrentUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $secrets = $GLOBALS['cg_secrets'];
        $sessionName = $secrets['session']['name'];

        // Read session ID from cookie
        $sessionId = $_COOKIE[$sessionName] ?? null;
        if (empty($sessionId)) {
            return null;
        }

        self::$sessionId = $sessionId;

        // Look up session in database
        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "SELECT s.session_id, s.user_id, s.expires_at, s.is_valid,
                    u.user_id, u.username, u.display_name, u.role, u.is_active
             FROM CG_Sessions s
             JOIN CG_Users u ON u.user_id = s.user_id
             WHERE s.session_id = :session_id
               AND s.is_valid = 1
               AND s.expires_at > NOW()
               AND u.is_active = 1"
        );
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            // Expired or invalid session — clear cookie
            self::clearSessionCookie();
            return null;
        }

        // Extend session (sliding window)
        $lifetime = $secrets['session']['lifetime'];
        $stmtUpdate = $pdo->prepare(
            "UPDATE CG_Sessions SET expires_at = DATE_ADD(NOW(), INTERVAL :lifetime SECOND)
             WHERE session_id = :session_id"
        );
        $stmtUpdate->execute([
            ':lifetime'   => $lifetime,
            ':session_id' => $sessionId,
        ]);

        self::$currentUser = [
            'user_id'      => (int) $row['user_id'],
            'username'     => $row['username'],
            'display_name' => $row['display_name'],
            'role'         => $row['role'],
        ];

        return self::$currentUser;
    }

    /**
     * Require that the current user has admin role.
     */
    public static function requireAdmin(): void
    {
        $user = self::getCurrentUser();
        if (!$user || $user['role'] !== 'admin') {
            jsonError('Forbidden: admin access required', 403);
        }
    }

    /**
     * Get the current user's ID (or exit with 401).
     */
    public static function getUserId(): int
    {
        $user = self::getCurrentUser();
        if (!$user) {
            jsonError('Unauthorized', 401);
        }
        return $user['user_id'];
    }

    /**
     * Create a new session for a user.
     */
    public static function createSession(int $userId): string
    {
        $secrets = $GLOBALS['cg_secrets'];
        $sessionId = bin2hex(random_bytes(64)); // 128-char hex string
        $lifetime = $secrets['session']['lifetime'];

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_Sessions (session_id, user_id, ip_address, user_agent, expires_at)
             VALUES (:session_id, :user_id, :ip, :ua, DATE_ADD(NOW(), INTERVAL :lifetime SECOND))"
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':user_id'    => $userId,
            ':ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            ':lifetime'   => $lifetime,
        ]);

        // Set cookie
        $sessionName = $secrets['session']['name'];
        setcookie($sessionName, $sessionId, [
            'expires'  => 0,  // Session cookie (expires when browser closes)
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Strict',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);

        self::$sessionId = $sessionId;
        return $sessionId;
    }

    /**
     * Invalidate the current session.
     */
    public static function destroySession(): void
    {
        if (self::$sessionId) {
            $pdo = cg_db();
            $stmt = $pdo->prepare(
                "UPDATE CG_Sessions SET is_valid = 0 WHERE session_id = :session_id"
            );
            $stmt->execute([':session_id' => self::$sessionId]);
        }
        self::clearSessionCookie();
        self::$currentUser = null;
        self::$sessionId = null;
    }

    /**
     * Clear the session cookie.
     */
    private static function clearSessionCookie(): void
    {
        $sessionName = $GLOBALS['cg_secrets']['session']['name'];
        setcookie($sessionName, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Get the current session ID.
     */
    public static function getSessionId(): ?string
    {
        return self::$sessionId;
    }
}
