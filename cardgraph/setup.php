<?php
/**
 * Card Graph — Setup Script
 *
 * Run this ONCE from the NAS command line to:
 * 1. Create all database tables
 * 2. Seed status types
 * 3. Create the initial admin user with a proper bcrypt hash
 *
 * Usage (via SSH on the NAS):
 *   cd /volume1/web/cardgraph
 *   php setup.php
 *
 * Or from Web Station's PHP CLI:
 *   /usr/local/bin/php82 setup.php
 */

echo "=== Card Graph Setup ===\n\n";

// Load config
require_once __DIR__ . '/src/bootstrap.php';

try {
    $pdo = cg_db();
    echo "[OK] Database connection successful.\n";
} catch (Exception $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    echo "Check your config/secrets.php settings.\n";
    exit(1);
}

// Run DDL
echo "\n--- Creating tables ---\n";
$ddl = file_get_contents(__DIR__ . '/sql/001_create_tables.sql');
$statements = array_filter(
    array_map('trim', explode(';', $ddl)),
    fn($s) => !empty($s) && !str_starts_with($s, '--')
);

foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
        // Extract table name for display
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) {
            echo "  [OK] {$m[1]}\n";
        }
    } catch (PDOException $e) {
        echo "  [WARN] " . $e->getMessage() . "\n";
    }
}

// Seed status types
echo "\n--- Seeding status types ---\n";
$seed = file_get_contents(__DIR__ . '/sql/002_seed_data.sql');
$seedStatements = array_filter(
    array_map('trim', explode(';', $seed)),
    fn($s) => !empty($s) && !str_starts_with($s, '--')
);

foreach ($seedStatements as $sql) {
    // Skip the placeholder admin insert from seed file
    if (str_contains($sql, 'PLACEHOLDER_GENERATE_ON_NAS')) {
        continue;
    }
    try {
        $pdo->exec($sql);
        echo "  [OK] Status types seeded.\n";
    } catch (PDOException $e) {
        echo "  [WARN] " . $e->getMessage() . "\n";
    }
}

// Create admin user
echo "\n--- Creating admin user ---\n";

$check = $pdo->query("SELECT COUNT(*) AS cnt FROM CG_Users WHERE username = 'admin'");
if ((int) $check->fetch()['cnt'] > 0) {
    echo "  [SKIP] Admin user already exists.\n";
} else {
    // Prompt for password (or use default)
    $password = 'changeme';
    if (function_exists('readline')) {
        $input = readline("  Enter admin password (or press Enter for 'changeme'): ");
        if (!empty(trim($input))) {
            $password = trim($input);
        }
    } else {
        echo "  Using default password: 'changeme' (change it after first login!)\n";
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare(
        "INSERT INTO CG_Users (username, display_name, password_hash, role)
         VALUES ('admin', 'Administrator', :hash, 'admin')"
    );
    $stmt->execute([':hash' => $hash]);
    echo "  [OK] Admin user created (username: admin).\n";
}

// Create upload directory
$uploadDir = __DIR__ . '/storage/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
    echo "\n[OK] Created upload directory: {$uploadDir}\n";
}

$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0750, true);
    echo "[OK] Created log directory: {$logDir}\n";
}

// Touch log files
touch($logDir . '/app.log');
touch($logDir . '/error.log');

echo "\n=== Setup complete! ===\n";
echo "Next steps:\n";
echo "1. Update config/secrets.php with your actual database password\n";
echo "2. Configure Web Station to point to /volume1/web/cardgraph/public/\n";
echo "3. Open http://<NAS_IP>:8880/ in your browser\n";
echo "4. Log in with username 'admin' and your chosen password\n";
echo "5. CHANGE the admin password if you used the default!\n\n";
