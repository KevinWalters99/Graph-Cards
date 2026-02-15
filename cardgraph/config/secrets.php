<?php
/**
 * Card Graph — Secrets Configuration
 *
 * This file lives OUTSIDE the web root and cannot be accessed via browser.
 * On the NAS this is at: /volume1/web/cardgraph/config/secrets.php
 *
 * IMPORTANT: Update these values during NAS setup!
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'card_graph',
        'username' => 'cg_app',
        'password' => 'CHANGE_ME_ON_NAS',  // Set during NAS setup
        'charset'  => 'utf8mb4',
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
