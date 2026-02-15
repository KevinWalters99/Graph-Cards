<?php
/**
 * Card Graph — User Controller (Admin only)
 */
class UserController
{
    /**
     * GET /api/users
     */
    public function index(array $params = []): void
    {
        Auth::requireAdmin();
        jsonResponse(['data' => User::getAll()]);
    }

    /**
     * POST /api/users
     * Body: { username, display_name, password, role }
     */
    public function store(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();

        $username = trim($body['username'] ?? '');
        $displayName = trim($body['display_name'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'user';

        if (empty($username) || empty($displayName) || empty($password)) {
            jsonError('Username, display name, and password are required', 400);
        }

        if (strlen($password) < 8) {
            jsonError('Password must be at least 8 characters', 400);
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            $role = 'user';
        }

        // Check uniqueness
        $existing = User::findByUsername($username);
        if ($existing) {
            jsonError('Username already exists', 409);
        }

        $userId = User::create($username, $displayName, $password, $role);
        jsonResponse(['user_id' => $userId, 'message' => 'User created'], 201);
    }

    /**
     * PUT /api/users/{id}
     * Body: { display_name?, role?, is_active?, password? }
     */
    public function update(array $params = []): void
    {
        Auth::requireAdmin();
        $userId = (int) ($params['id'] ?? 0);
        $body = getJsonBody();

        $user = User::findById($userId);
        if (!$user) {
            jsonError('User not found', 404);
        }

        // Update password if provided
        if (!empty($body['password'])) {
            if (strlen($body['password']) < 8) {
                jsonError('Password must be at least 8 characters', 400);
            }
            User::updatePassword($userId, $body['password']);
            unset($body['password']);
        }

        // Update other fields
        $fields = [];
        if (isset($body['display_name'])) $fields['display_name'] = trim($body['display_name']);
        if (isset($body['role'])) $fields['role'] = $body['role'];
        if (isset($body['is_active'])) $fields['is_active'] = (int) $body['is_active'];

        if (!empty($fields)) {
            User::update($userId, $fields);
        }

        jsonResponse(['message' => 'User updated']);
    }
}
