<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/database.php';
$pdo = cg_db();
$sql = file_get_contents(__DIR__ . '/002_cost_matrix.sql');
$pdo->exec($sql);
$r = $pdo->query("SHOW TABLES LIKE 'CG_CostMatrix%'");
$tables = $r->fetchAll(PDO::FETCH_COLUMN);
echo "Tables created: " . implode(', ', $tables) . "\n";
echo "Done.\n";
