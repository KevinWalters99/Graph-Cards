<?php
/**
 * Temporary migration runner — delete after use
 */
$host = '127.0.0.1';
$port = 3307;
$db   = 'card_graph';
$user = 'cg_app';
$pass = 'ACe!sysD#0kVnBWF';

header('Content-Type: text/plain');

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents(__DIR__ . '/../sql/004_recurring_milestones.sql');

    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        // Strip leading comment lines
        $lines = explode("\n", $stmt);
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
            $cleaned[] = $line;
        }
        $stmt = trim(implode("\n", $cleaned));
        if (empty($stmt)) continue;
        echo "Running: " . substr($stmt, 0, 80) . "...\n";
        $pdo->exec($stmt);
        echo "  OK\n";
    }

    echo "\nMigration 004 completed successfully.\n";

    $cols = $pdo->query("SHOW COLUMNS FROM CG_AnalyticsMilestones")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nCG_AnalyticsMilestones columns:\n";
    foreach ($cols as $c) {
        echo "  {$c['Field']} — {$c['Type']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
