<?php
/**
 * Card Graph - Financial Summary Controller
 */
class FinancialSummaryController
{
    /**
     * GET /api/financial-summary/overview
     * Returns yearly and quarterly summary data.
     * Excludes cancelled/negative statuses from all calculations.
     */
    public function overview(array $params = []): void
    {
        $pdo = cg_db();

        // Get excluded status IDs dynamically
        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        // -- Yearly summary --
        $yearlySql = "SELECT
            YEAR(a.transaction_completed_at) AS year,
            COUNT(*) AS total_items,
            SUM(a.quantity_sold) AS total_quantity,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            SUM(CASE WHEN a.transaction_type = 'SHIPPING_CHARGE' THEN 1 ELSE 0 END) AS shipping_charge_count,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(a.shipping_fee), 0) AS total_shipping,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_auction_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams
        FROM CG_AuctionLineItems a
        WHERE a.transaction_completed_at IS NOT NULL
          {$statusFilter}
        GROUP BY YEAR(a.transaction_completed_at)
        ORDER BY year DESC";

        $yearly = $pdo->query($yearlySql)->fetchAll();

        // Add payouts, item costs, general costs per year
        foreach ($yearly as &$row) {
            $yr = (int) $row['year'];

            $payoutRow = $pdo->query(
                "SELECT COALESCE(SUM(amount), 0) AS total_payouts, COUNT(*) AS payout_count
                 FROM CG_Payouts WHERE status != 'Failed' AND YEAR(date_initiated) = {$yr}"
            )->fetch();
            $row['total_payouts'] = round((float) $payoutRow['total_payouts'], 2);
            $row['payout_count'] = (int) $payoutRow['payout_count'];

            $costRow = $pdo->query(
                "SELECT COALESCE(SUM(c.cost_amount), 0) AS total_item_costs
                 FROM CG_ItemCosts c
                 JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
                 WHERE YEAR(a.transaction_completed_at) = {$yr}
                   {$statusFilter}"
            )->fetch();
            $row['total_item_costs'] = round((float) $costRow['total_item_costs'], 2);

            $gcRow = $pdo->query(
                "SELECT COALESCE(SUM(total), 0) AS total_general_costs
                 FROM CG_GeneralCosts WHERE YEAR(cost_date) = {$yr}"
            )->fetch();
            $row['total_general_costs'] = round((float) $gcRow['total_general_costs'], 2);

            // Cast fields
            $row['year'] = $yr;
            $row['total_items'] = (int) $row['total_items'];
            $row['total_quantity'] = (int) $row['total_quantity'];
            $row['auction_count'] = (int) $row['auction_count'];
            $row['giveaway_count'] = (int) $row['giveaway_count'];
            $row['shipping_charge_count'] = (int) $row['shipping_charge_count'];
            $row['tip_count'] = (int) $row['tip_count'];
            $row['total_tips'] = round((float) $row['total_tips'], 2);
            $row['total_earnings'] = round((float) $row['total_earnings'], 2);
            $row['total_fees'] = round((float) $row['total_fees'], 2);
            $row['total_shipping'] = round((float) $row['total_shipping'], 2);
            $row['avg_auction_price'] = round((float) $row['avg_auction_price'], 2);
            $row['unique_buyers'] = (int) $row['unique_buyers'];
            $row['unique_livestreams'] = (int) $row['unique_livestreams'];
            $row['net'] = round(
                $row['total_earnings'] - $row['total_fees'] - $row['total_item_costs'] - $row['total_general_costs'],
                2
            );
        }
        unset($row);

        // -- Quarterly summary --
        $quarterlySql = "SELECT
            YEAR(a.transaction_completed_at) AS year,
            QUARTER(a.transaction_completed_at) AS quarter,
            COUNT(*) AS total_items,
            SUM(a.quantity_sold) AS total_quantity,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            SUM(CASE WHEN a.transaction_type = 'SHIPPING_CHARGE' THEN 1 ELSE 0 END) AS shipping_charge_count,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(a.shipping_fee), 0) AS total_shipping,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_auction_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams
        FROM CG_AuctionLineItems a
        WHERE a.transaction_completed_at IS NOT NULL
          {$statusFilter}
        GROUP BY YEAR(a.transaction_completed_at), QUARTER(a.transaction_completed_at)
        ORDER BY year DESC, quarter DESC";

        $quarterly = $pdo->query($quarterlySql)->fetchAll();

        foreach ($quarterly as &$row) {
            $yr = (int) $row['year'];
            $qtr = (int) $row['quarter'];

            // Quarter date range
            $qStartMonth = ($qtr - 1) * 3 + 1;
            $qEndMonth = $qtr * 3;
            $qStart = sprintf('%d-%02d-01', $yr, $qStartMonth);
            $qEnd = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $yr, $qEndMonth)));

            // Payouts
            $pStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) AS total_payouts, COUNT(*) AS payout_count
                 FROM CG_Payouts WHERE status != 'Failed' AND date_initiated BETWEEN :qstart AND :qend"
            );
            $pStmt->execute([':qstart' => $qStart, ':qend' => $qEnd]);
            $payoutRow = $pStmt->fetch();
            $row['total_payouts'] = round((float) $payoutRow['total_payouts'], 2);
            $row['payout_count'] = (int) $payoutRow['payout_count'];

            // Item costs
            $cStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(c.cost_amount), 0) AS total_item_costs
                 FROM CG_ItemCosts c
                 JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
                 WHERE a.transaction_completed_at BETWEEN :cstart AND :cend
                   {$statusFilter}"
            );
            $cStmt->execute([':cstart' => $qStart . ' 00:00:00', ':cend' => $qEnd . ' 23:59:59']);
            $costRow = $cStmt->fetch();
            $row['total_item_costs'] = round((float) $costRow['total_item_costs'], 2);

            // General costs
            $gStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(total), 0) AS total_general_costs
                 FROM CG_GeneralCosts WHERE cost_date BETWEEN :gstart AND :gend"
            );
            $gStmt->execute([':gstart' => $qStart, ':gend' => $qEnd]);
            $gcRow = $gStmt->fetch();
            $row['total_general_costs'] = round((float) $gcRow['total_general_costs'], 2);

            // Cast fields
            $row['year'] = $yr;
            $row['quarter'] = $qtr;
            $row['total_items'] = (int) $row['total_items'];
            $row['total_quantity'] = (int) $row['total_quantity'];
            $row['auction_count'] = (int) $row['auction_count'];
            $row['giveaway_count'] = (int) $row['giveaway_count'];
            $row['shipping_charge_count'] = (int) $row['shipping_charge_count'];
            $row['tip_count'] = (int) $row['tip_count'];
            $row['total_tips'] = round((float) $row['total_tips'], 2);
            $row['total_earnings'] = round((float) $row['total_earnings'], 2);
            $row['total_fees'] = round((float) $row['total_fees'], 2);
            $row['total_shipping'] = round((float) $row['total_shipping'], 2);
            $row['avg_auction_price'] = round((float) $row['avg_auction_price'], 2);
            $row['unique_buyers'] = (int) $row['unique_buyers'];
            $row['unique_livestreams'] = (int) $row['unique_livestreams'];
            $row['net'] = round(
                $row['total_earnings'] - $row['total_fees'] - $row['total_item_costs'] - $row['total_general_costs'],
                2
            );
        }
        unset($row);

        jsonResponse([
            'yearly' => $yearly,
            'quarterly' => $quarterly,
        ]);
    }

    /**
     * GET /api/financial-summary/costs
     */
    public function listCosts(array $params = []): void
    {
        $pdo = cg_db();
        $sql = "SELECT gc.*, u.display_name AS entered_by_name
                FROM CG_GeneralCosts gc
                JOIN CG_Users u ON u.user_id = gc.entered_by
                ORDER BY gc.cost_date DESC, gc.created_at DESC";
        $data = $pdo->query($sql)->fetchAll();
        jsonResponse(['data' => $data]);
    }

    /**
     * POST /api/financial-summary/costs
     */
    public function createCost(array $params = []): void
    {
        $body = getJsonBody();
        $user = Auth::getCurrentUser();

        $date = $body['cost_date'] ?? null;
        $desc = trim($body['description'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $quantity = (int) ($body['quantity'] ?? 1);
        $distribute = !empty($body['distribute']) ? 1 : 0;

        if (!$date || !$desc || $amount <= 0 || $quantity < 1) {
            jsonError('Date, description, amount (>0), and quantity (>=1) are required', 400);
        }

        $total = round($amount * $quantity, 2);

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_GeneralCosts (cost_date, description, amount, quantity, total, distribute, entered_by)
             VALUES (:cost_date, :description, :amount, :quantity, :total, :distribute, :entered_by)"
        );
        $stmt->execute([
            ':cost_date' => $date,
            ':description' => $desc,
            ':amount' => $amount,
            ':quantity' => $quantity,
            ':total' => $total,
            ':distribute' => $distribute,
            ':entered_by' => $user['user_id'],
        ]);

        jsonResponse(['message' => 'Cost added', 'id' => $pdo->lastInsertId()], 201);
    }

    /**
     * PUT /api/financial-summary/costs/{id}
     */
    public function updateCost(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid cost ID', 400);
        }

        $body = getJsonBody();
        $date = $body['cost_date'] ?? null;
        $desc = trim($body['description'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $quantity = (int) ($body['quantity'] ?? 1);
        $distribute = !empty($body['distribute']) ? 1 : 0;

        if (!$date || !$desc || $amount <= 0 || $quantity < 1) {
            jsonError('Date, description, amount (>0), and quantity (>=1) are required', 400);
        }

        $total = round($amount * $quantity, 2);

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "UPDATE CG_GeneralCosts
             SET cost_date = :cost_date, description = :description, amount = :amount,
                 quantity = :quantity, total = :total, distribute = :distribute
             WHERE general_cost_id = :id"
        );
        $stmt->execute([
            ':cost_date' => $date,
            ':description' => $desc,
            ':amount' => $amount,
            ':quantity' => $quantity,
            ':total' => $total,
            ':distribute' => $distribute,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonError('Cost not found', 404);
        }
        jsonResponse(['message' => 'Cost updated']);
    }

    /**
     * DELETE /api/financial-summary/costs/{id}
     */
    public function deleteCost(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Invalid cost ID', 400);
        }

        $pdo = cg_db();
        $stmt = $pdo->prepare("DELETE FROM CG_GeneralCosts WHERE general_cost_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Cost not found', 404);
        }
        jsonResponse(['message' => 'Cost deleted']);
    }
}
