<?php
/**
 * Card Graph — Secrets Configuration
 *
 * This file lives OUTSIDE the web root and cannot be accessed via browser.
 * On the NAS this is at: /volume1/web/cardgraph/config/secrets.php
 *
 * Reads credentials from .env file. Falls back to defaults if .env not found.
 */

// Load .env file
$_cg_env = [];
$_envPaths = [
    __DIR__ . '/../.env',           // cardgraph/.env
    __DIR__ . '/../../.env',        // project root/.env
    '/volume1/web/cardgraph/.env',  // NAS absolute
    '/volume1/web/.env',            // NAS alt
];
foreach ($_envPaths as $_p) {
    if (is_file($_p)) {
        foreach (file($_p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
            $_line = trim($_line);
            if ($_line === '' || $_line[0] === '#') continue;
            if (strpos($_line, '=') !== false) {
                [$_k, $_v] = explode('=', $_line, 2);
                $_cg_env[trim($_k)] = trim($_v);
            }
        }
        break;
    }
}

return [
    'db' => [
        'host'     => $_cg_env['CG_DB_HOST'] ?? '127.0.0.1',
        'port'     => (int) ($_cg_env['CG_DB_PORT'] ?? 3306),
        'dbname'   => $_cg_env['CG_DB_NAME'] ?? 'card_graph',
        'username' => $_cg_env['CG_DB_USER'] ?? 'cg_app',
        'password' => $_cg_env['CG_DB_PASSWORD'] ?? '',
        'charset'  => 'utf8mb4',
    ],
    'scheduler' => [
        'key' => $_cg_env['CG_SCHEDULER_KEY'] ?? '',
    ],
    'session' => [
        'lifetime' => 3600,        // 1 hour
        'name'     => 'CG_SESS',
    ],
    'csrf' => [
        'token_name' => 'cg_csrf_token',
    ],
    'upload' => [
        'max_size_bytes'     => 10485760,  // 10 MB
        'allowed_extensions' => ['csv'],
        'upload_dir'         => __DIR__ . '/../storage/uploads/',
    ],
];
