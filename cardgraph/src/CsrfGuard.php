<?php
/**
 * Card Graph — CSRF Protection
 *
 * Generates and validates CSRF tokens tied to the user's session.
 */
class CsrfGuard
{
    /**
     * Generate a new CSRF token for the current session.
     */
    public static function generate(): string
    {
        $token = bin2hex(random_bytes(32));
        $sessionId = Auth::getSessionId();

        if ($sessionId) {
            // Store token in a simple way: we use the session table or a separate mechanism.
            // For simplicity, we store it in PHP's $_SESSION after starting a native session
            // tied to our custom session ID.
            // Alternative: store in the DB session row.
            // We'll use a lightweight approach: HMAC of session ID + server secret.
            // This way no extra DB storage is needed.
        }

        // Stateless approach: HMAC-based token
        // Token = random_nonce + HMAC(session_id + nonce, secret_key)
        $nonce = bin2hex(random_bytes(16));
        $secret = self::getSecret();
        $hmac = hash_hmac('sha256', $sessionId . $nonce, $secret);
        return $nonce . '.' . $hmac;
    }

    /**
     * Validate a CSRF token from the request header.
     */
    public static function validate(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token)) {
            jsonError('CSRF token missing', 403);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            jsonError('Invalid CSRF token', 403);
        }

        [$nonce, $hmac] = $parts;
        $sessionId = Auth::getSessionId();
        $secret = self::getSecret();
        $expected = hash_hmac('sha256', $sessionId . $nonce, $secret);

        if (!hash_equals($expected, $hmac)) {
            jsonError('CSRF token validation failed', 403);
        }
    }

    /**
     * Get the CSRF secret key (derived from DB password — it's secret and unique per install).
     */
    private static function getSecret(): string
    {
        $secrets = $GLOBALS['cg_secrets'];
        return hash('sha256', 'cg_csrf_' . $secrets['db']['password']);
    }
}
