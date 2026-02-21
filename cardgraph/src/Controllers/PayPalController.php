<?php
/**
 * Card Graph — PayPal Controller
 *
 * Handles PayPal transaction listing, assignment, auto-assign, and lock/unlock workflows.
 */
class PayPalController
{
    /**
     * GET /api/paypal/transactions
     * Paginated list with filters and assignment status.
     */
    public function listTransactions(array $params = []): void
    {
        $pdo = cg_db();
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;
        $sort    = $_GET['sort'] ?? 'transaction_date';
        $order   = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Whitelist sortable columns
        $sortable = [
            'transaction_date', 'transaction_time', 'name', 'type', 'status',
            'amount', 'net_amount', 'charge_category', 'order_number', 'created_at',
        ];
        if (!in_array($sort, $sortable, true)) {
            $sort = 'transaction_date';
        }

        $where = [];
        $bind  = [];

        if (!empty($_GET['date_from'])) {
            $where[] = 'pp.transaction_date >= :date_from';
            $bind[':date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'pp.transaction_date <= :date_to';
            $bind[':date_to'] = $_GET['date_to'];
        }
        if (!empty($_GET['name'])) {
            $where[] = 'pp.name LIKE :name';
            $bind[':name'] = '%' . $_GET['name'] . '%';
        }
        if (!empty($_GET['type'])) {
            $where[] = 'pp.type = :type';
            $bind[':type'] = $_GET['type'];
        }
        if (!empty($_GET['charge_category'])) {
            $where[] = 'pp.charge_category = :category';
            $bind[':category'] = $_GET['charge_category'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(pp.name LIKE :search OR pp.item_title LIKE :search2 OR pp.order_number LIKE :search3)';
            $bind[':search']  = '%' . $_GET['search'] . '%';
            $bind[':search2'] = '%' . $_GET['search'] . '%';
            $bind[':search3'] = '%' . $_GET['search'] . '%';
        }

        // Assignment status filter
        $assignFilter = $_GET['assignment_status'] ?? '';

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Build the main query with allocation aggregation
        $sql = "SELECT pp.*,
                    COALESCE(a.alloc_sum, 0) AS allocated_amount,
                    COALESCE(a.alloc_count, 0) AS allocation_count,
                    COALESCE(a.locked_count, 0) AS locked_count,
                    CASE
                        WHEN a.alloc_count IS NULL OR a.alloc_count = 0 THEN 'unassigned'
                        WHEN a.locked_count > 0 AND a.locked_count = a.alloc_count THEN 'locked'
                        WHEN ABS(COALESCE(a.alloc_sum, 0)) >= ABS(pp.amount) - 0.01 THEN 'assigned'
                        ELSE 'partial'
                    END AS assignment_status
                FROM CG_PayPalTransactions pp
                LEFT JOIN (
                    SELECT pp_transaction_id,
                           SUM(amount_allocated) AS alloc_sum,
                           COUNT(*) AS alloc_count,
                           SUM(is_locked) AS locked_count
                    FROM CG_PayPalAllocations
                    GROUP BY pp_transaction_id
                ) a ON a.pp_transaction_id = pp.pp_transaction_id
                {$whereClause}";

        // Apply assignment status filter as HAVING-like wrapper
        if ($assignFilter) {
            $statusCondition = match ($assignFilter) {
                'unassigned' => "(a.alloc_count IS NULL OR a.alloc_count = 0)",
                'partial'    => "(a.alloc_count > 0 AND (a.locked_count = 0 OR a.locked_count < a.alloc_count) AND ABS(COALESCE(a.alloc_sum, 0)) < ABS(pp.amount) - 0.01)",
                'assigned'   => "(a.alloc_count > 0 AND ABS(COALESCE(a.alloc_sum, 0)) >= ABS(pp.amount) - 0.01 AND (a.locked_count = 0 OR a.locked_count < a.alloc_count))",
                'locked'     => "(a.locked_count > 0 AND a.locked_count = a.alloc_count)",
                default      => null,
            };
            if ($statusCondition) {
                $sql .= ($whereClause ? ' AND ' : ' WHERE ') . $statusCondition;
            }
        }

        // Count query
        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS sub";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        // Data query with sort and pagination
        $sql .= " ORDER BY pp.{$sort} {$order}, pp.pp_transaction_id DESC";
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/paypal/summary
     * Scorecard totals for the Detail tab.
     */
    public function getSummary(array $params = []): void
    {
        $pdo = cg_db();
        $where = [];
        $bind  = [];

        if (!empty($_GET['date_from'])) {
            $where[] = 'transaction_date >= :date_from';
            $bind[':date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'transaction_date <= :date_to';
            $bind[':date_to'] = $_GET['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
                    COUNT(*) AS total_transactions,
                    SUM(CASE WHEN charge_category = 'purchase' THEN 1 ELSE 0 END) AS purchase_count,
                    SUM(CASE WHEN charge_category = 'refund' THEN 1 ELSE 0 END) AS refund_count,
                    SUM(CASE WHEN charge_category = 'income' THEN 1 ELSE 0 END) AS income_count,
                    SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) AS total_debits,
                    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_credits,
                    SUM(amount) AS net_amount,
                    SUM(CASE WHEN charge_category IN ('purchase','refund','income') THEN 1 ELSE 0 END) AS assignable_count
                FROM CG_PayPalTransactions
                {$whereClause}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        // Allocation stats
        $allocSql = "SELECT
                        SUM(CASE WHEN a.alloc_count IS NULL OR a.alloc_count = 0 THEN 1 ELSE 0 END) AS unassigned_count,
                        SUM(CASE WHEN a.alloc_count > 0 AND ABS(COALESCE(a.alloc_sum, 0)) < ABS(pp.amount) - 0.01 THEN 1 ELSE 0 END) AS partial_count,
                        SUM(CASE WHEN a.alloc_count > 0 AND ABS(COALESCE(a.alloc_sum, 0)) >= ABS(pp.amount) - 0.01 AND (a.locked_count = 0 OR a.locked_count < a.alloc_count) THEN 1 ELSE 0 END) AS assigned_count,
                        SUM(CASE WHEN a.locked_count > 0 AND a.locked_count = a.alloc_count THEN 1 ELSE 0 END) AS locked_count,
                        COALESCE(SUM(CASE WHEN a.alloc_count IS NULL OR a.alloc_count = 0 THEN pp.amount ELSE 0 END), 0) AS unassigned_total,
                        COALESCE(SUM(CASE WHEN a.alloc_count > 0 THEN a.alloc_sum ELSE 0 END), 0) AS assigned_total,
                        COALESCE(SUM(CASE WHEN a.locked_count > 0 AND a.locked_count = a.alloc_count THEN a.alloc_sum ELSE 0 END), 0) AS locked_total
                    FROM CG_PayPalTransactions pp
                    LEFT JOIN (
                        SELECT pp_transaction_id,
                               SUM(amount_allocated) AS alloc_sum,
                               COUNT(*) AS alloc_count,
                               SUM(is_locked) AS locked_count
                        FROM CG_PayPalAllocations
                        GROUP BY pp_transaction_id
                    ) a ON a.pp_transaction_id = pp.pp_transaction_id
                    WHERE pp.charge_category IN ('purchase','refund','income')";

        if (!empty($_GET['date_from'])) {
            $allocSql .= " AND pp.transaction_date >= :date_from";
        }
        if (!empty($_GET['date_to'])) {
            $allocSql .= " AND pp.transaction_date <= :date_to";
        }

        $allocStmt = $pdo->prepare($allocSql);
        $allocStmt->execute($bind);
        $allocStats = $allocStmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse(array_merge($summary, $allocStats));
    }

    /**
     * GET /api/paypal/transactions/{id}
     * Single transaction detail with allocations.
     */
    public function getTransaction(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid ID', 400);

        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT * FROM CG_PayPalTransactions WHERE pp_transaction_id = :id");
        $stmt->execute([':id' => $id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$txn) jsonError('Transaction not found', 404);

        $allocStmt = $pdo->prepare(
            "SELECT a.*, u.display_name AS assigned_by_name,
                    lu.display_name AS locked_by_name,
                    ls.livestream_title
             FROM CG_PayPalAllocations a
             LEFT JOIN CG_Users u ON u.user_id = a.assigned_by
             LEFT JOIN CG_Users lu ON lu.user_id = a.locked_by
             LEFT JOIN CG_Livestreams ls ON ls.livestream_id = a.livestream_id COLLATE utf8mb4_unicode_ci
             WHERE a.pp_transaction_id = :id
             ORDER BY a.assigned_at"
        );
        $allocStmt->execute([':id' => $id]);
        $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'transaction' => $txn,
            'allocations' => $allocations,
        ]);
    }

    /**
     * DELETE /api/paypal/transactions/{id}
     */
    public function deleteTransaction(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid ID', 400);

        $pdo = cg_db();
        $stmt = $pdo->prepare("DELETE FROM CG_PayPalTransactions WHERE pp_transaction_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) jsonError('Not found', 404);
        jsonResponse(['message' => 'Deleted']);
    }

    /**
     * POST /api/paypal/allocations
     * Create a new allocation (manual assign).
     */
    public function assignTransaction(array $params = []): void
    {
        $userId = Auth::getUserId();
        $body = getJsonBody();

        $ppTxnId     = (int) ($body['pp_transaction_id'] ?? 0);
        $salesSource = $body['sales_source'] ?? 'Auction';
        $livestreamId = !empty($body['livestream_id']) ? $body['livestream_id'] : null;
        $amount      = (float) ($body['amount_allocated'] ?? 0);
        $notes       = trim($body['notes'] ?? '');

        if ($ppTxnId <= 0 || $amount == 0) {
            jsonError('Transaction ID and amount are required', 400);
        }

        $validSources = ['Auction', 'eBay', 'Private-Collection'];
        if (!in_array($salesSource, $validSources, true)) {
            jsonError('Invalid sales source', 400);
        }

        $pdo = cg_db();

        // Fetch transaction amount
        $stmt = $pdo->prepare("SELECT amount FROM CG_PayPalTransactions WHERE pp_transaction_id = :id");
        $stmt->execute([':id' => $ppTxnId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$txn) jsonError('Transaction not found', 404);

        $txnAmount = (float) $txn['amount'];

        // Get current allocation total
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_allocated), 0) FROM CG_PayPalAllocations WHERE pp_transaction_id = :id"
        );
        $stmt->execute([':id' => $ppTxnId]);
        $allocatedSum = (float) $stmt->fetchColumn();
        $remaining = $txnAmount - $allocatedSum;

        // Enforce sign match (negative for purchases, positive for income)
        if (($txnAmount < 0 && $amount > 0) || ($txnAmount > 0 && $amount < 0)) {
            jsonError('Allocation sign must match transaction (negative for purchases, positive for income)', 400);
        }

        // Enforce no over-allocation (0.01 epsilon for rounding)
        if (abs($amount) > abs($remaining) + 0.01) {
            $fmtRemaining = number_format(abs($remaining), 2);
            $fmtRequested = number_format(abs($amount), 2);
            jsonError("Over-allocation: only \${$fmtRemaining} remaining, cannot allocate \${$fmtRequested}", 400);
        }

        // Snap to exact remaining if within a penny (prevents dust)
        if (abs(abs($amount) - abs($remaining)) <= 0.01) {
            $amount = $remaining;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO CG_PayPalAllocations
                (pp_transaction_id, sales_source, livestream_id, amount_allocated, notes, assigned_by)
             VALUES (:pp_id, :source, :ls_id, :amount, :notes, :user_id)"
        );
        $stmt->execute([
            ':pp_id'   => $ppTxnId,
            ':source'  => $salesSource,
            ':ls_id'   => $livestreamId,
            ':amount'  => $amount,
            ':notes'   => $notes ?: null,
            ':user_id' => $userId,
        ]);

        jsonResponse([
            'message' => 'Allocation created',
            'id'      => (int) $pdo->lastInsertId(),
        ], 201);
    }

    /**
     * PUT /api/paypal/allocations/{id}
     */
    public function updateAllocation(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid ID', 400);

        $body = getJsonBody();
        $pdo = cg_db();

        // Fetch allocation with transaction context
        $stmt = $pdo->prepare(
            "SELECT a.is_locked, a.pp_transaction_id, a.amount_allocated AS current_amount, pp.amount AS txn_amount
             FROM CG_PayPalAllocations a
             JOIN CG_PayPalTransactions pp ON pp.pp_transaction_id = a.pp_transaction_id
             WHERE a.allocation_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $alloc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) jsonError('Allocation not found', 404);
        if ($alloc['is_locked']) jsonError('Cannot edit a locked allocation', 403);

        // If amount is being changed, enforce over-allocation check
        if (array_key_exists('amount_allocated', $body)) {
            $newAmount = (float) $body['amount_allocated'];
            $txnAmount = (float) $alloc['txn_amount'];

            // Sum of all OTHER allocations (excluding this one)
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount_allocated), 0)
                 FROM CG_PayPalAllocations
                 WHERE pp_transaction_id = :pp_id AND allocation_id != :alloc_id"
            );
            $stmt->execute([':pp_id' => $alloc['pp_transaction_id'], ':alloc_id' => $id]);
            $otherSum = (float) $stmt->fetchColumn();
            $remaining = $txnAmount - $otherSum;

            if (($txnAmount < 0 && $newAmount > 0) || ($txnAmount > 0 && $newAmount < 0)) {
                jsonError('Allocation sign must match transaction', 400);
            }

            if (abs($newAmount) > abs($remaining) + 0.01) {
                $fmtRemaining = number_format(abs($remaining), 2);
                $fmtRequested = number_format(abs($newAmount), 2);
                jsonError("Over-allocation: only \${$fmtRemaining} available, cannot allocate \${$fmtRequested}", 400);
            }

            // Snap to exact remaining if within a penny
            if (abs(abs($newAmount) - abs($remaining)) <= 0.01) {
                $body['amount_allocated'] = $remaining;
            }
        }

        $fields = [
            'sales_source'    => 'string',
            'livestream_id'   => 'string',
            'amount_allocated' => 'float',
            'notes'           => 'string',
        ];

        $sets = [];
        $bind = [':id' => $id];

        foreach ($fields as $field => $type) {
            if (array_key_exists($field, $body)) {
                $sets[] = "{$field} = :{$field}";
                $val = $body[$field];
                if ($type === 'float') $val = (float) $val;
                if ($type === 'string' && ($val === '' || $val === null)) $val = null;
                $bind[":{$field}"] = $val;
            }
        }

        if (empty($sets)) jsonError('No fields to update', 400);

        $sql = "UPDATE CG_PayPalAllocations SET " . implode(', ', $sets) . " WHERE allocation_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        jsonResponse(['message' => 'Allocation updated']);
    }

    /**
     * DELETE /api/paypal/allocations/{id}
     */
    public function deleteAllocation(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid ID', 400);

        $pdo = cg_db();

        // Check if locked
        $stmt = $pdo->prepare("SELECT is_locked FROM CG_PayPalAllocations WHERE allocation_id = :id");
        $stmt->execute([':id' => $id]);
        $alloc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) jsonError('Allocation not found', 404);
        if ($alloc['is_locked']) jsonError('Cannot delete a locked allocation', 403);

        $stmt = $pdo->prepare("DELETE FROM CG_PayPalAllocations WHERE allocation_id = :id");
        $stmt->execute([':id' => $id]);

        jsonResponse(['message' => 'Allocation deleted']);
    }

    /**
     * POST /api/paypal/auto-assign
     * Match unassigned purchases to auctions by eBay order number.
     */
    public function autoAssign(array $params = []): void
    {
        $userId = Auth::getUserId();
        $pdo = cg_db();

        // Find assignable transactions with order numbers that have no allocations
        $stmt = $pdo->prepare(
            "SELECT pp.pp_transaction_id, pp.order_number, pp.amount
             FROM CG_PayPalTransactions pp
             LEFT JOIN CG_PayPalAllocations a ON a.pp_transaction_id = pp.pp_transaction_id
             WHERE pp.charge_category IN ('purchase')
               AND pp.order_number IS NOT NULL
               AND a.allocation_id IS NULL"
        );
        $stmt->execute();
        $unassigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unassigned)) {
            jsonResponse(['matched' => 0, 'unmatched' => 0, 'message' => 'No unassigned transactions with order numbers']);
            return;
        }

        // Try to match each order number against CG_AuctionLineItems
        $matchStmt = $pdo->prepare(
            "SELECT li.livestream_id
             FROM CG_AuctionLineItems li
             WHERE li.order_id = :order_id
             LIMIT 1"
        );

        $insertStmt = $pdo->prepare(
            "INSERT INTO CG_PayPalAllocations
                (pp_transaction_id, sales_source, livestream_id, amount_allocated, notes, assigned_by)
             VALUES (:pp_id, 'Auction', :ls_id, :amount, 'Auto-assigned by order number', :user_id)"
        );

        $matched = 0;
        $unmatched = 0;

        $pdo->beginTransaction();
        try {
            foreach ($unassigned as $txn) {
                $matchStmt->execute([':order_id' => $txn['order_number']]);
                $match = $matchStmt->fetch(PDO::FETCH_ASSOC);

                if ($match) {
                    $insertStmt->execute([
                        ':pp_id'   => $txn['pp_transaction_id'],
                        ':ls_id'   => $match['livestream_id'],
                        ':amount'  => $txn['amount'],
                        ':user_id' => $userId,
                    ]);
                    $matched++;
                } else {
                    $unmatched++;
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        jsonResponse([
            'matched'   => $matched,
            'unmatched' => $unmatched,
            'message'   => "Matched {$matched} of " . count($unassigned) . " transactions",
        ]);
    }

    /**
     * POST /api/paypal/lock
     * Lock (sign off) allocations by date range.
     */
    public function lockAllocations(array $params = []): void
    {
        $userId = Auth::getUserId();
        $body = getJsonBody();

        $dateFrom = $body['date_from'] ?? '';
        $dateTo   = $body['date_to'] ?? '';

        if (empty($dateFrom) || empty($dateTo)) {
            jsonError('date_from and date_to are required', 400);
        }

        $pdo = cg_db();

        // Lock all unlocked allocations for fully-assigned transactions in date range
        $stmt = $pdo->prepare(
            "UPDATE CG_PayPalAllocations a
             INNER JOIN CG_PayPalTransactions pp ON pp.pp_transaction_id = a.pp_transaction_id
             SET a.is_locked = 1,
                 a.locked_by = :user_id,
                 a.locked_at = NOW()
             WHERE pp.transaction_date >= :date_from
               AND pp.transaction_date <= :date_to
               AND a.is_locked = 0"
        );
        $stmt->execute([
            ':user_id'   => $userId,
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
        ]);

        $count = $stmt->rowCount();
        jsonResponse([
            'message' => "Locked {$count} allocations",
            'locked_count' => $count,
        ]);
    }

    /**
     * POST /api/paypal/unlock
     * Unlock allocations by date range (admin only).
     */
    public function unlockAllocations(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();

        $dateFrom = $body['date_from'] ?? '';
        $dateTo   = $body['date_to'] ?? '';

        if (empty($dateFrom) || empty($dateTo)) {
            jsonError('date_from and date_to are required', 400);
        }

        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "UPDATE CG_PayPalAllocations a
             INNER JOIN CG_PayPalTransactions pp ON pp.pp_transaction_id = a.pp_transaction_id
             SET a.is_locked = 0,
                 a.locked_by = NULL,
                 a.locked_at = NULL
             WHERE pp.transaction_date >= :date_from
               AND pp.transaction_date <= :date_to
               AND a.is_locked = 1"
        );
        $stmt->execute([
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
        ]);

        $count = $stmt->rowCount();
        jsonResponse([
            'message' => "Unlocked {$count} allocations",
            'unlocked_count' => $count,
        ]);
    }

    /**
     * GET /api/paypal/assignments/summary
     * Assignment breakdown by source and status.
     */
    public function getAssignmentSummary(array $params = []): void
    {
        $pdo = cg_db();

        // By source
        $bySource = $pdo->query(
            "SELECT sales_source,
                    COUNT(*) AS allocation_count,
                    SUM(amount_allocated) AS total_allocated,
                    SUM(is_locked) AS locked_count
             FROM CG_PayPalAllocations
             GROUP BY sales_source
             ORDER BY sales_source"
        )->fetchAll(PDO::FETCH_ASSOC);

        // By month
        $byMonth = $pdo->query(
            "SELECT DATE_FORMAT(pp.transaction_date, '%Y-%m') AS month,
                    COUNT(DISTINCT pp.pp_transaction_id) AS transaction_count,
                    SUM(pp.amount) AS total_amount,
                    COUNT(DISTINCT a.pp_transaction_id) AS assigned_count
             FROM CG_PayPalTransactions pp
             LEFT JOIN CG_PayPalAllocations a ON a.pp_transaction_id = pp.pp_transaction_id
             WHERE pp.charge_category IN ('purchase','refund','income')
             GROUP BY month
             ORDER BY month DESC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'by_source' => $bySource,
            'by_month'  => $byMonth,
        ]);
    }

    /**
     * GET /api/paypal/types
     * Distinct transaction types for filter dropdown.
     */
    public function getTypes(array $params = []): void
    {
        $pdo = cg_db();
        $types = $pdo->query(
            "SELECT DISTINCT type FROM CG_PayPalTransactions ORDER BY type"
        )->fetchAll(PDO::FETCH_COLUMN);

        jsonResponse(['data' => $types]);
    }
}
