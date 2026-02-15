<?php
/**
 * One-time migration runner — run via CLI or browser, then delete.
 */
$base = realpath(__DIR__ . '/..') ?: __DIR__;
if (basename(__DIR__) === 'public') {
    require_once $base . '/src/bootstrap.php';
} else {
    $base = __DIR__;
    require_once __DIR__ . '/src/bootstrap.php';
}

$file = $_GET['file'] ?? ($argv[1] ?? null);
if (!$file || !preg_match('/^[0-9a-z_]+\.sql$/i', $file)) {
    die("Usage: ?file=003_analytics.sql\n");
}

$path = $base . '/sql/' . $file;
if (!file_exists($path)) {
    die("File not found: {$path}\n");
}

$pdo = cg_db();
$ddl = file_get_contents($path);
// Strip SQL comment lines before splitting on semicolons
$ddl = preg_replace('/^\s*--.*$/m', '', $ddl);
$statements = array_filter(
    array_map('trim', explode(';', $ddl)),
    fn($s) => !empty($s)
);

echo "<pre>\n=== Running {$file} ===\n";
foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) {
            echo "[OK] Created table: {$m[1]}\n";
        } elseif (preg_match('/INSERT INTO (\w+)/i', $sql, $m)) {
            echo "[OK] Inserted into: {$m[1]}\n";
        } else {
            echo "[OK] Executed statement\n";
        }
    } catch (PDOException $e) {
        echo "[ERR] " . $e->getMessage() . "\n";
    }
}
echo "=== Done ===\n</pre>";
