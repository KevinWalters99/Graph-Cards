<?php
/**
 * Run migration 005_paypal.sql
 * Execute via: http://192.168.0.215:8880/sql/run_005.php
 * DELETE from NAS after running.
 */
header('Content-Type: text/plain');
$host = '127.0.0.1';
$port = 3307;
$db   = 'card_graph';
$user = 'cg_app';
$pass = 'ACe!sysD#0kVnBWF';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents(__DIR__ . '/005_paypal.sql');

    // Split on semicolons and run each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        // Strip comment lines
        $lines = explode("\n", $stmt);
        $lines = array_filter($lines, function($line) {
            return strpos(trim($line), '--') !== 0;
        });
        $clean = trim(implode("\n", $lines));
        if (empty($clean)) continue;

        echo "Running: " . substr($clean, 0, 80) . "...\n";
        $pdo->exec($clean);
        echo "  OK\n";
    }

    echo "\nMigration 005 completed successfully.\n";

    // Verify tables exist
    echo "\nCG_PayPalTransactions columns:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM CG_PayPalTransactions")->fetchAll(PDO::FETCH_COLUMN);
    echo "  " . implode(', ', $cols) . "\n";

    echo "\nCG_PayPalAllocations columns:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM CG_PayPalAllocations")->fetchAll(PDO::FETCH_COLUMN);
    echo "  " . implode(', ', $cols) . "\n";

    echo "\nCG_UploadLog upload_type ENUM:\n";
    $col = $pdo->query("SHOW COLUMNS FROM CG_UploadLog LIKE 'upload_type'")->fetch();
    echo "  " . $col['Type'] . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
