<?php
/**
 * Card Graph — Bootstrap
 *
 * Loaded by the front controller (public/index.php).
 * Sets up timezone, error handling, autoloading, and the database connection.
 */

// Timezone: Central Standard Time
date_default_timezone_set('America/Chicago');

// Error handling: log errors, don't display
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/error.log');
error_reporting(E_ALL);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load secrets
$GLOBALS['cg_secrets'] = require __DIR__ . '/../config/secrets.php';

// Database connection (lazy singleton)
function cg_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = require __DIR__ . '/../config/database.php';
    }
    return $pdo;
}

// Simple autoloader for src/ classes
spl_autoload_register(function (string $class) {
    // Check Controllers/ subdirectory
    $controllerPath = __DIR__ . '/Controllers/' . $class . '.php';
    if (file_exists($controllerPath)) {
        require_once $controllerPath;
        return;
    }

    // Check Models/ subdirectory
    $modelPath = __DIR__ . '/Models/' . $class . '.php';
    if (file_exists($modelPath)) {
        require_once $modelPath;
        return;
    }

    // Check src/ root
    $srcPath = __DIR__ . '/' . $class . '.php';
    if (file_exists($srcPath)) {
        require_once $srcPath;
        return;
    }
});

// Load helper functions
require_once __DIR__ . '/Helpers.php';
