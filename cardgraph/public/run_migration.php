<?php
header('Content-Type: text/plain');
$pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=card_graph;charset=utf8mb4", 'cg_app', 'ACe!sysD#0kVnBWF', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "=== CG_EbayOrders for Feb 17-18 ===\n";
$stmt = $pdo->query("SELECT ebay_order_id, order_number, order_date, source, seller_buyer_name, total_amount, status, email_subject
    FROM CG_EbayOrders
    WHERE order_date >= '2026-02-17' AND order_date < '2026-02-19'
    ORDER BY order_date");
foreach ($stmt as $r) {
    echo sprintf("  ID:%d | %s | %s | %s | %s | $%.2f | %s | subj: %s\n",
        $r['ebay_order_id'], $r['order_date'], $r['order_number'], $r['source'],
        $r['seller_buyer_name'] ?? '-', $r['total_amount'], $r['status'],
        substr($r['email_subject'] ?? '', 0, 60));
}

echo "\n=== Total orders by date (last 14 days) ===\n";
$stmt = $pdo->query("SELECT DATE(order_date) AS d, COUNT(*) AS cnt, GROUP_CONCAT(DISTINCT source) AS sources
    FROM CG_EbayOrders
    WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY d ORDER BY d");
foreach ($stmt as $r) {
    echo sprintf("  %s: %d orders (%s)\n", $r['d'], $r['cnt'], $r['sources']);
}

echo "\n=== Email process log count ===\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM CG_EmailProcessLog");
    echo "  " . $stmt->fetch()['cnt'] . " log entries total\n";
} catch (Exception $e) {
    echo "  Table may not exist: " . $e->getMessage() . "\n";
}

echo "\n=== All orders total ===\n";
$stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM CG_EbayOrders");
echo "  " . $stmt->fetch()['cnt'] . " total orders\n";

echo "\n=== Orders missing (no Feb 15, 16, 19, 20) ===\n";
$stmt = $pdo->query("SELECT DATE(order_date) AS d, COUNT(*) AS cnt
    FROM CG_EbayOrders
    WHERE order_date >= '2026-02-15' AND order_date < '2026-02-21'
    GROUP BY d ORDER BY d");
foreach ($stmt as $r) {
    echo sprintf("  %s: %d orders\n", $r['d'], $r['cnt']);
}

echo "\n=== Recent import output (if exists) ===\n";
$outFile = __DIR__ . '/../tools/import_output.txt';
if (file_exists($outFile)) {
    $output = file_get_contents($outFile);
    echo substr($output, -2000) . "\n";
} else {
    echo "  No import_output.txt found\n";
}
