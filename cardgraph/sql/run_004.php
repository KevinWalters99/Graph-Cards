<?php
/**
 * Run migration 004_recurring_milestones.sql
 * Execute via: http://192.168.0.215:8880/sql/run_004.php
 */
$host = '127.0.0.1';
$port = 3307;
$db   = 'card_graph';
$user = 'cg_app';
$pass = 'ACe!sysD#0kVnBWF';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents(__DIR__ . '/004_recurring_milestones.sql');

    // Split on semicolons and run each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        echo "Running: " . substr($stmt, 0, 80) . "...\n";
        $pdo->exec($stmt);
        echo "  OK\n";
    }

    echo "\nMigration 004 completed successfully.\n";

    // Verify columns exist
    $cols = $pdo->query("SHOW COLUMNS FROM CG_AnalyticsMilestones")->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns: " . implode(', ', $cols) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
