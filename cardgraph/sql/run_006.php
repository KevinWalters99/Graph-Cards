<?php
/**
 * Temporary migration runner for 006_alerts.sql
 * Place in public/ alongside the SQL file, run via browser, then delete both.
 */
require_once __DIR__ . '/../src/bootstrap.php';

$pdo = cg_db();
$sql = file_get_contents(__DIR__ . '/006_alerts.sql');

$statements = array_filter(array_map('trim', explode(';', $sql)));
$results = [];

foreach ($statements as $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
    try {
        $pdo->exec($stmt);
        $results[] = "OK: " . substr($stmt, 0, 60) . "...";
    } catch (PDOException $e) {
        $results[] = "ERR: " . $e->getMessage() . " — " . substr($stmt, 0, 60) . "...";
    }
}

header('Content-Type: text/plain');
echo "Migration 006 Results:\n\n";
echo implode("\n", $results) . "\n";
