<?php
/**
 * Card Graph — Database Connection Factory
 *
 * Returns a configured PDO instance with CST timezone and error mode.
 * Usage: $pdo = require __DIR__ . '/../config/database.php';
 */

$secrets = require __DIR__ . '/secrets.php';
$db = $secrets['db'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset']
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
];

try {
    $pdo = new PDO($dsn, $db['username'], $db['password'], $options);
} catch (PDOException $e) {
    error_log('Card Graph DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

return $pdo;
