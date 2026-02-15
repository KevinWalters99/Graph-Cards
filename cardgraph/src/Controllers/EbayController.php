<?php
/**
 * Card Graph - eBay Transactions Controller
 */
class EbayController
{
    /**
     * GET /api/ebay/orders
     * List eBay orders with optional filters.
     */
    public function listOrders(array $params = []): void
    {
        $pdo = cg_db();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
        $sort = $_GET['sort'] ?? 'order_date';
        $order = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        // Whitelist sort columns
        $sortable = ['order_date', 'order_number', 'total_amount', 'seller_buyer_name', 'status', 'source', 'delivery_date'];
        if (!in_array($sort, $sortable)) {
            $sort = 'order_date';
        }

        $conditions = [];
        $bindParams = [];

        // Date filters
        if (!empty($_GET['date_from'])) {
            $conditions[] = ['clause' => 'o.order_date >= :date_from', 'param' => ':date_from', 'value' => $_GET['date_from'] . ' 00:00:00'];
        }
        if (!empty($_GET['date_to'])) {
            $conditions[] = ['clause' => 'o.order_date <= :date_to', 'param' => ':date_to', 'value' => $_GET['date_to'] . ' 23:59:59'];
        }

        // Transaction type filter
        if (!empty($_GET['transaction_type'])) {
            $conditions[] = ['clause' => 'o.transaction_type = :txn_type', 'param' => ':txn_type', 'value' => $_GET['transaction_type']];
        }

        // Status filter
        if (!empty($_GET['status'])) {
            $conditions[] = ['clause' => 'o.status = :status', 'param' => ':status', 'value' => $_GET['status']];
        }

        // Source filter
        if (!empty($_GET['source'])) {
            $conditions[] = ['clause' => 'o.source = :source', 'param' => ':source', 'value' => $_GET['source']];
        }

        // Search
        if (!empty($_GET['search'])) {
            $conditions[] = [
                'clause' => '(o.order_number LIKE :search OR o.seller_buyer_name LIKE :search2 OR o.email_subject LIKE :search3)',
                'param' => ':search',
                'value' => '%' . $_GET['search'] . '%',
            ];
            $bindParams[':search2'] = '%' . $_GET['search'] . '%';
            $bindParams[':search3'] = '%' . $_GET['search'] . '%';
        }

        $baseQuery = "SELECT o.*, (SELECT COUNT(*) FROM CG_EbayOrderItems i WHERE i.ebay_order_id = o.ebay_order_id) AS item_count FROM CG_EbayOrders o";

        $result = buildPaginatedQuery($baseQuery, $conditions, "o.{$sort} {$order}", $page, $perPage);

        // Merge extra bind params
        $allParams = array_merge($result['params'], $bindParams);

        // Count
        $countStmt = $pdo->prepare($result['countQuery']);
        $countStmt->execute($allParams);
        $total = (int) $countStmt->fetch()['total'];

        // Data
        $stmt = $pdo->prepare($result['query']);
        $stmt->execute($allParams);
        $data = $stmt->fetchAll();

        // Cast types
        foreach ($data as &$row) {
            $row['ebay_order_id'] = (int) $row['ebay_order_id'];
            $row['subtotal'] = round((float) $row['subtotal'], 2);
            $row['shipping_cost'] = round((float) $row['shipping_cost'], 2);
            $row['sales_tax'] = round((float) $row['sales_tax'], 2);
            $row['total_amount'] = round((float) $row['total_amount'], 2);
            $row['item_count'] = (int) $row['item_count'];
            $row['reported_item_count'] = $row['reported_item_count'] !== null ? (int) $row['reported_item_count'] : null;
        }
        unset($row);

        jsonResponse([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/ebay/orders/{id}
     * Get order detail with items.
     */
    public function showOrder(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid order ID', 400);
        }

        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT * FROM CG_EbayOrders WHERE ebay_order_id = :id");
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonError('Order not found', 404);
        }

        $itemStmt = $pdo->prepare("SELECT * FROM CG_EbayOrderItems WHERE ebay_order_id = :id ORDER BY ebay_item_id");
        $itemStmt->execute([':id' => $id]);
        $items = $itemStmt->fetchAll();

        // Cast
        $order['ebay_order_id'] = (int) $order['ebay_order_id'];
        $order['subtotal'] = round((float) $order['subtotal'], 2);
        $order['shipping_cost'] = round((float) $order['shipping_cost'], 2);
        $order['sales_tax'] = round((float) $order['sales_tax'], 2);
        $order['total_amount'] = round((float) $order['total_amount'], 2);
        $order['reported_item_count'] = $order['reported_item_count'] !== null ? (int) $order['reported_item_count'] : null;

        foreach ($items as &$item) {
            $item['ebay_item_id'] = (int) $item['ebay_item_id'];
            $item['ebay_order_id'] = (int) $item['ebay_order_id'];
            $item['item_price'] = round((float) $item['item_price'], 2);
            $item['quantity'] = (int) $item['quantity'];
        }
        unset($item);

        jsonResponse([
            'order' => $order,
            'items' => $items,
        ]);
    }

    /**
     * PUT /api/ebay/orders/{id}
     * Update order status or notes.
     */
    public function updateOrder(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid order ID', 400);
        }

        $body = getJsonBody();

        $pdo = cg_db();
        $sets = [];
        $bindParams = [':id' => $id];

        if (isset($body['status'])) {
            $valid = ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Returned', 'Cancelled'];
            if (!in_array($body['status'], $valid)) {
                jsonError('Invalid status', 400);
            }
            $sets[] = 'status = :status';
            $bindParams[':status'] = $body['status'];
        }

        if (isset($body['notes'])) {
            $sets[] = 'notes = :notes';
            $bindParams[':notes'] = trim($body['notes']);
        }

        if (isset($body['transaction_type'])) {
            $validTypes = ['PURCHASE', 'SALE'];
            if (!in_array($body['transaction_type'], $validTypes)) {
                jsonError('Invalid transaction type', 400);
            }
            $sets[] = 'transaction_type = :txn_type';
            $bindParams[':txn_type'] = $body['transaction_type'];
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql = "UPDATE CG_EbayOrders SET " . implode(', ', $sets) . " WHERE ebay_order_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindParams);

        if ($stmt->rowCount() === 0) {
            jsonError('Order not found', 404);
        }

        jsonResponse(['message' => 'Order updated']);
    }

    /**
     * DELETE /api/ebay/orders/{id}
     */
    public function deleteOrder(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid order ID', 400);
        }

        $pdo = cg_db();
        $stmt = $pdo->prepare("DELETE FROM CG_EbayOrders WHERE ebay_order_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Order not found', 404);
        }

        jsonResponse(['message' => 'Order deleted']);
    }

    /**
     * POST /api/ebay/import
     * Trigger email import by running the Python script.
     */
    public function importEmails(array $params = []): void
    {
        $body = getJsonBody();
        $phase = !empty($body['phase']) ? (int)$body['phase'] : 1;

        // Find Python and script
        $scriptDir = realpath(__DIR__ . '/../../tools');
        $script = $scriptDir . '/ebay_import.py';

        if (!file_exists($script)) {
            jsonError('Import script not found at: ' . $script, 500);
        }

        // Check if import is already running
        $lockFile = $scriptDir . '/import.lock';
        $outputFile = $scriptDir . '/import_output.txt';

        // Check for status poll
        if (!empty($body['check_status'])) {
            if (file_exists($lockFile)) {
                jsonResponse(['status' => 'running']);
            } elseif (file_exists($outputFile)) {
                $outputText = file_get_contents($outputFile);
                $summary = $this->parseImportOutput($outputText);
                jsonResponse([
                    'status' => 'complete',
                    'output' => $outputText,
                    'summary' => $summary,
                ]);
            } else {
                jsonResponse(['status' => 'idle']);
            }
            return;
        }

        if (file_exists($lockFile)) {
            jsonError('Import already running', 409);
        }

        // Try python3 first, fall back to python
        $pythonBin = 'python3';
        exec('which python3 2>/dev/null', $testOut, $testRet);
        if ($testRet !== 0) {
            exec('which python 2>/dev/null', $testOut2, $testRet2);
            if ($testRet2 === 0) {
                $pythonBin = 'python';
            }
        }

        // Run in background: write output to file, use lock file
        $args = ' --no-move --phase ' . $phase;
        $cmd = 'touch ' . escapeshellarg($lockFile) . ' && '
             . escapeshellcmd($pythonBin) . ' ' . escapeshellarg($script) . $args
             . ' > ' . escapeshellarg($outputFile) . ' 2>&1'
             . '; rm -f ' . escapeshellarg($lockFile);

        // Launch as true background process (nohup + redirect to avoid blocking)
        shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');

        jsonResponse([
            'status' => 'started',
            'summary' => ['orders_imported' => 0, 'deliveries_updated' => 0, 'linked' => 0],
        ]);
    }

    private function parseImportOutput(string $outputText): array
    {
        $summary = [
            'orders_imported' => 0,
            'deliveries_updated' => 0,
            'linked' => 0,
        ];

        if (preg_match_all('/(\d+) imported/', $outputText, $m)) {
            foreach ($m[1] as $v) {
                $summary['orders_imported'] += (int)$v;
            }
        }
        if (preg_match('/(\d+) updated/', $outputText, $m)) {
            $summary['deliveries_updated'] = (int)$m[1];
        }
        if (preg_match('/(\d+) linked/', $outputText, $m)) {
            $summary['linked'] = (int)$m[1];
        }

        return $summary;
    }

    /**
     * GET /api/ebay/summary
     * Summary stats for the eBay tab header cards.
     */
    public function summary(array $params = []): void
    {
        $pdo = cg_db();

        $row = $pdo->query("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_spent,
                COALESCE(SUM(subtotal), 0) AS total_subtotal,
                COALESCE(SUM(shipping_cost), 0) AS total_shipping,
                COALESCE(SUM(sales_tax), 0) AS total_tax,
                COALESCE(AVG(total_amount), 0) AS avg_order_total
            FROM CG_EbayOrders
        ")->fetch();

        $itemRow = $pdo->query("SELECT COUNT(*) AS total_items FROM CG_EbayOrderItems")->fetch();

        // Use reported_item_count when available (eBay truncates emails to 10 items)
        $reportedRow = $pdo->query("
            SELECT COALESCE(SUM(COALESCE(reported_item_count,
                (SELECT COUNT(*) FROM CG_EbayOrderItems i WHERE i.ebay_order_id = o.ebay_order_id)
            )), 0) AS estimated_items
            FROM CG_EbayOrders o
        ")->fetch();

        $sellerRow = $pdo->query("
            SELECT COUNT(DISTINCT seller_buyer_name) AS unique_sellers
            FROM CG_EbayOrders
            WHERE seller_buyer_name IS NOT NULL
        ")->fetch();

        $deliveredRow = $pdo->query("
            SELECT COUNT(*) AS delivered_count
            FROM CG_EbayOrders
            WHERE status = 'Delivered'
        ")->fetch();

        // Source breakdown
        $sourceRows = $pdo->query("
            SELECT COALESCE(source, 'ebay_confirmed') AS source,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(total_amount), 0) AS total
            FROM CG_EbayOrders
            GROUP BY COALESCE(source, 'ebay_confirmed')
        ")->fetchAll();
        $sources = [];
        foreach ($sourceRows as $sr) {
            $sources[$sr['source']] = [
                'count' => (int) $sr['cnt'],
                'total' => round((float) $sr['total'], 2),
            ];
        }

        jsonResponse([
            'total_orders' => (int) $row['total_orders'],
            'total_spent' => round((float) $row['total_spent'], 2),
            'total_subtotal' => round((float) $row['total_subtotal'], 2),
            'total_shipping' => round((float) $row['total_shipping'], 2),
            'total_tax' => round((float) $row['total_tax'], 2),
            'avg_order_total' => round((float) $row['avg_order_total'], 2),
            'total_items' => (int) $reportedRow['estimated_items'],
            'total_items_in_db' => (int) $itemRow['total_items'],
            'unique_sellers' => (int) $sellerRow['unique_sellers'],
            'delivered_count' => (int) $deliveredRow['delivered_count'],
            'sources' => $sources,
        ]);
    }
}
