<?php
/**
 * Card Graph — Authentication Controller
 */
class AuthController
{
    /**
     * POST /api/auth/login
     * Body: { username, password }
     */
    public function login(array $params = []): void
    {
        $body = getJsonBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if (empty($username) || empty($password)) {
            jsonError('Username and password are required', 400);
        }

        // Rate limit check
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!User::checkRateLimit($ip)) {
            jsonError('Too many login attempts. Please try again later.', 429);
        }

        // Find user
        $user = User::findByUsername($username);
        if (!$user || !$user['is_active']) {
            jsonError('Invalid username or password', 401);
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            jsonError('Invalid username or password', 401);
        }

        // Create session
        $sessionId = Auth::createSession((int) $user['user_id']);

        // Generate CSRF token
        $csrfToken = CsrfGuard::generate();

        jsonResponse([
            'user' => [
                'user_id'      => (int) $user['user_id'],
                'username'     => $user['username'],
                'display_name' => $user['display_name'],
                'role'         => $user['role'],
            ],
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(array $params = []): void
    {
        Auth::destroySession();
        jsonResponse(['message' => 'Logged out']);
    }

    /**
     * GET /api/auth/me
     */
    public function me(array $params = []): void
    {
        $user = Auth::getCurrentUser();
        $csrfToken = CsrfGuard::generate();

        jsonResponse([
            'user'       => $user,
            'csrf_token' => $csrfToken,
        ]);
    }
}
