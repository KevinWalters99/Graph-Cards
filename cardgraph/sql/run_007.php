<?php
/**
 * Temporary migration runner for 007_transcription.sql
 * Place in public/ alongside the SQL file, run via browser, then delete both.
 */
require_once __DIR__ . '/../src/bootstrap.php';

$pdo = cg_db();
$sql = file_get_contents(__DIR__ . '/007_transcription.sql');

$statements = array_filter(array_map('trim', explode(';', $sql)));
$results = [];

foreach ($statements as $stmt) {
    // Strip comment-only lines, then check if anything remains
    $cleaned = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
    if (empty($cleaned)) continue;
    try {
        $pdo->exec($stmt);
        $results[] = "OK: " . substr($cleaned, 0, 80) . "...";
    } catch (PDOException $e) {
        $results[] = "ERR: " . $e->getMessage() . " — " . substr($cleaned, 0, 80) . "...";
    }
}

header('Content-Type: text/plain');
echo "Migration 007 Results:\n\n";
echo implode("\n", $results) . "\n";
