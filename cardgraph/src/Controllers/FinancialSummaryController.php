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
            YEAR(a.order_placed_at) AS year,
            COUNT(*) AS total_items,
            SUM(a.quantity_sold) AS total_quantity,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN a.transaction_amount ELSE 0 END), 0) AS giveaway_net,
            SUM(CASE WHEN a.transaction_type = 'SHIPPING_CHARGE' THEN 1 ELSE 0 END) AS shipping_charge_count,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(a.shipping_fee), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_auction_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams
        FROM CG_AuctionLineItems a
        WHERE a.order_placed_at IS NOT NULL
          {$statusFilter}
        GROUP BY YEAR(a.order_placed_at)
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
                 WHERE YEAR(a.order_placed_at) = {$yr}
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
            $row['giveaway_net'] = round((float) $row['giveaway_net'], 2);
            $row['shipping_charge_count'] = (int) $row['shipping_charge_count'];
            $row['tip_count'] = (int) $row['tip_count'];
            $row['total_tips'] = round((float) $row['total_tips'], 2);
            $row['total_earnings'] = round((float) $row['total_earnings'], 2);
            $row['total_fees'] = round((float) $row['total_fees'], 2);
            $row['total_shipping'] = round((float) $row['total_shipping'], 2);
            $row['avg_auction_price'] = round((float) $row['avg_auction_price'], 2);
            $row['unique_buyers'] = (int) $row['unique_buyers'];
            $row['unique_livestreams'] = (int) $row['unique_livestreams'];
            $row['total_item_price'] = round((float) $row['total_item_price'], 2);
            $row['net'] = round(
                $row['total_item_price'] - $row['total_fees'] - $row['total_item_costs'] - $row['total_general_costs'],
                2
            );
        }
        unset($row);

        // -- Quarterly summary --
        $quarterlySql = "SELECT
            YEAR(a.order_placed_at) AS year,
            QUARTER(a.order_placed_at) AS quarter,
            COUNT(*) AS total_items,
            SUM(a.quantity_sold) AS total_quantity,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN a.transaction_amount ELSE 0 END), 0) AS giveaway_net,
            SUM(CASE WHEN a.transaction_type = 'SHIPPING_CHARGE' THEN 1 ELSE 0 END) AS shipping_charge_count,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(a.shipping_fee), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_auction_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams
        FROM CG_AuctionLineItems a
        WHERE a.order_placed_at IS NOT NULL
          {$statusFilter}
        GROUP BY YEAR(a.order_placed_at), QUARTER(a.order_placed_at)
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
                 WHERE a.order_placed_at BETWEEN :cstart AND :cend
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
            $row['giveaway_net'] = round((float) $row['giveaway_net'], 2);
            $row['shipping_charge_count'] = (int) $row['shipping_charge_count'];
            $row['tip_count'] = (int) $row['tip_count'];
            $row['total_tips'] = round((float) $row['total_tips'], 2);
            $row['total_earnings'] = round((float) $row['total_earnings'], 2);
            $row['total_fees'] = round((float) $row['total_fees'], 2);
            $row['total_shipping'] = round((float) $row['total_shipping'], 2);
            $row['avg_auction_price'] = round((float) $row['avg_auction_price'], 2);
            $row['unique_buyers'] = (int) $row['unique_buyers'];
            $row['unique_livestreams'] = (int) $row['unique_livestreams'];
            $row['total_item_price'] = round((float) $row['total_item_price'], 2);
            $row['net'] = round(
                $row['total_item_price'] - $row['total_fees'] - $row['total_item_costs'] - $row['total_general_costs'],
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
     * GET /api/financial-summary/monthly
     * Returns monthly breakdown with sub-components:
     *   - Auction earnings, fees, item costs (from CG_AuctionLineItems + CG_ItemCosts)
     *   - General costs (from CG_GeneralCosts)
     *   - Payouts (from CG_Payouts)
     *   - PayPal purchases/refunds/income (from CG_PayPalTransactions)
     */
    public function monthly(array $params = []): void
    {
        $pdo = cg_db();

        // Get excluded status IDs
        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        // Monthly auction data
        $monthlySql = "SELECT
            DATE_FORMAT(a.order_placed_at, '%Y-%m') AS month,
            COUNT(*) AS total_items,
            SUM(a.quantity_sold) AS total_quantity,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(a.shipping_fee), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams
        FROM CG_AuctionLineItems a
        WHERE a.order_placed_at IS NOT NULL
          {$statusFilter}
        GROUP BY DATE_FORMAT(a.order_placed_at, '%Y-%m')
        ORDER BY month DESC";

        $monthly = $pdo->query($monthlySql)->fetchAll(PDO::FETCH_ASSOC);

        // Collect all months present
        $allMonths = [];
        foreach ($monthly as $row) {
            $allMonths[$row['month']] = true;
        }

        // Payouts by month
        $payoutsByMonth = $pdo->query(
            "SELECT DATE_FORMAT(date_initiated, '%Y-%m') AS month,
                    COALESCE(SUM(amount), 0) AS total_payouts,
                    COUNT(*) AS payout_count
             FROM CG_Payouts WHERE status != 'Failed'
             GROUP BY DATE_FORMAT(date_initiated, '%Y-%m')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $payoutsMap = [];
        foreach ($payoutsByMonth as $row) {
            $payoutsMap[$row['month']] = $row;
            $allMonths[$row['month']] = true;
        }

        // Item costs by month
        $itemCostsByMonth = $pdo->query(
            "SELECT DATE_FORMAT(a.order_placed_at, '%Y-%m') AS month,
                    COALESCE(SUM(c.cost_amount), 0) AS total_item_costs
             FROM CG_ItemCosts c
             JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
             WHERE a.order_placed_at IS NOT NULL {$statusFilter}
             GROUP BY DATE_FORMAT(a.order_placed_at, '%Y-%m')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $itemCostsMap = [];
        foreach ($itemCostsByMonth as $row) {
            $itemCostsMap[$row['month']] = round((float) $row['total_item_costs'], 2);
            $allMonths[$row['month']] = true;
        }

        // General costs by month
        $genCostsByMonth = $pdo->query(
            "SELECT DATE_FORMAT(cost_date, '%Y-%m') AS month,
                    COALESCE(SUM(total), 0) AS total_general_costs
             FROM CG_GeneralCosts
             GROUP BY DATE_FORMAT(cost_date, '%Y-%m')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $genCostsMap = [];
        foreach ($genCostsByMonth as $row) {
            $genCostsMap[$row['month']] = round((float) $row['total_general_costs'], 2);
            $allMonths[$row['month']] = true;
        }

        // PayPal by month (purchases as costs, income as revenue)
        $ppByMonth = $pdo->query(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                    COALESCE(SUM(CASE WHEN charge_category = 'purchase' THEN amount ELSE 0 END), 0) AS pp_purchases,
                    COALESCE(SUM(CASE WHEN charge_category = 'refund' THEN amount ELSE 0 END), 0) AS pp_refunds,
                    COALESCE(SUM(CASE WHEN charge_category = 'income' THEN amount ELSE 0 END), 0) AS pp_income,
                    COALESCE(SUM(fees), 0) AS pp_fees,
                    COUNT(*) AS pp_transaction_count
             FROM CG_PayPalTransactions
             WHERE charge_category IN ('purchase', 'refund', 'income')
             GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $ppMap = [];
        foreach ($ppByMonth as $row) {
            $ppMap[$row['month']] = $row;
            $allMonths[$row['month']] = true;
        }

        // Build monthly map from auction data
        $monthlyMap = [];
        foreach ($monthly as $row) {
            $monthlyMap[$row['month']] = $row;
        }

        // Merge all data
        $result = [];
        foreach ($allMonths as $month => $_) {
            $auc = $monthlyMap[$month] ?? [];
            $pay = $payoutsMap[$month] ?? [];
            $pp = $ppMap[$month] ?? [];

            $totalItemPrice = round((float) ($auc['total_item_price'] ?? 0), 2);
            $totalFees = round((float) ($auc['total_fees'] ?? 0), 2);
            $totalItemCosts = $itemCostsMap[$month] ?? 0;
            $totalGeneralCosts = $genCostsMap[$month] ?? 0;
            $ppPurchases = round((float) ($pp['pp_purchases'] ?? 0), 2);
            $ppRefunds = round((float) ($pp['pp_refunds'] ?? 0), 2);
            $ppIncome = round((float) ($pp['pp_income'] ?? 0), 2);
            $ppFees = round((float) ($pp['pp_fees'] ?? 0), 2);

            // Auction-only net (without PayPal)
            $auctionNet = round($totalItemPrice - $totalFees - $totalItemCosts, 2);
            $totalPayouts = round((float) ($pay['total_payouts'] ?? 0), 2);
            // Net = Payouts - GenCosts - PayPalOut + PayPalIn (only these 4)
            $comprehensiveNet = round(
                $totalPayouts - $totalGeneralCosts - abs($ppPurchases) + $ppIncome,
                2
            );

            $result[] = [
                'month' => $month,
                'total_items' => (int) ($auc['total_items'] ?? 0),
                'total_quantity' => (int) ($auc['total_quantity'] ?? 0),
                'auction_count' => (int) ($auc['auction_count'] ?? 0),
                'giveaway_count' => (int) ($auc['giveaway_count'] ?? 0),
                'total_earnings' => round((float) ($auc['total_earnings'] ?? 0), 2),
                'total_fees' => $totalFees,
                'total_shipping' => round((float) ($auc['total_shipping'] ?? 0), 2),
                'total_item_price' => $totalItemPrice,
                'unique_buyers' => (int) ($auc['unique_buyers'] ?? 0),
                'unique_livestreams' => (int) ($auc['unique_livestreams'] ?? 0),
                'total_payouts' => round((float) ($pay['total_payouts'] ?? 0), 2),
                'payout_count' => (int) ($pay['payout_count'] ?? 0),
                'total_item_costs' => $totalItemCosts,
                'total_general_costs' => $totalGeneralCosts,
                'pp_purchases' => $ppPurchases,
                'pp_refunds' => $ppRefunds,
                'pp_income' => $ppIncome,
                'pp_fees' => $ppFees,
                'pp_transaction_count' => (int) ($pp['pp_transaction_count'] ?? 0),
                'auction_net' => $auctionNet,
                'net' => $comprehensiveNet,
            ];
        }

        // Sort descending by month
        usort($result, function ($a, $b) {
            return strcmp($b['month'], $a['month']);
        });

        jsonResponse(['monthly' => $result]);
    }

    /**
     * GET /api/financial-summary/monthly-details?month=YYYY-MM
     * Returns daily aggregated summaries for a given month.
     * Same column structure as the monthly summary: auctions, earnings,
     * fees, item costs, general costs, PayPal, payouts, net — per day.
     */
    public function monthlyDetails(array $params = []): void
    {
        $month = $_GET['month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            jsonError('Invalid month format. Use YYYY-MM.', 400);
        }

        $pdo = cg_db();
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Get excluded status IDs
        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        $days = [];

        // Auction data by day
        $stmt = $pdo->prepare(
            "SELECT DATE(a.order_placed_at) AS day,
                    SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
                    COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
                    COALESCE(SUM(
                        COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                        COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                        COALESCE(a.shipping_fee, 0)
                    ), 0) AS total_fees,
                    COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price
             FROM CG_AuctionLineItems a
             WHERE a.order_placed_at BETWEEN :start AND :end
               {$statusFilter}
             GROUP BY DATE(a.order_placed_at)"
        );
        $stmt->execute([':start' => $startDate . ' 00:00:00', ':end' => $endDate . ' 23:59:59']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['day'];
            if (!isset($days[$d])) $days[$d] = $this->emptyDay($d);
            $days[$d]['auction_count'] = (int) $row['auction_count'];
            $days[$d]['total_earnings'] = round((float) $row['total_earnings'], 2);
            $days[$d]['total_fees'] = round((float) $row['total_fees'], 2);
            $days[$d]['total_item_price'] = round((float) $row['total_item_price'], 2);
        }

        // Item costs by day (based on auction order date)
        $stmt = $pdo->prepare(
            "SELECT DATE(a.order_placed_at) AS day,
                    COALESCE(SUM(c.cost_amount), 0) AS total_item_costs
             FROM CG_ItemCosts c
             JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
             WHERE a.order_placed_at BETWEEN :start AND :end
               {$statusFilter}
             GROUP BY DATE(a.order_placed_at)"
        );
        $stmt->execute([':start' => $startDate . ' 00:00:00', ':end' => $endDate . ' 23:59:59']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['day'];
            if (!isset($days[$d])) $days[$d] = $this->emptyDay($d);
            $days[$d]['total_item_costs'] = round((float) $row['total_item_costs'], 2);
        }

        // General costs by day
        $stmt = $pdo->prepare(
            "SELECT cost_date AS day, COALESCE(SUM(total), 0) AS total_general_costs
             FROM CG_GeneralCosts
             WHERE cost_date BETWEEN :start AND :end
             GROUP BY cost_date"
        );
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['day'];
            if (!isset($days[$d])) $days[$d] = $this->emptyDay($d);
            $days[$d]['total_general_costs'] = round((float) $row['total_general_costs'], 2);
        }

        // PayPal by day
        $stmt = $pdo->prepare(
            "SELECT transaction_date AS day,
                    COALESCE(SUM(CASE WHEN charge_category = 'purchase' THEN amount ELSE 0 END), 0) AS pp_purchases,
                    COALESCE(SUM(CASE WHEN charge_category = 'refund' THEN amount ELSE 0 END), 0) AS pp_refunds,
                    COALESCE(SUM(CASE WHEN charge_category = 'income' THEN amount ELSE 0 END), 0) AS pp_income
             FROM CG_PayPalTransactions
             WHERE transaction_date BETWEEN :start AND :end
               AND charge_category IN ('purchase', 'refund', 'income')
             GROUP BY transaction_date"
        );
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['day'];
            if (!isset($days[$d])) $days[$d] = $this->emptyDay($d);
            $days[$d]['pp_purchases'] = round((float) $row['pp_purchases'], 2);
            $days[$d]['pp_refunds'] = round((float) $row['pp_refunds'], 2);
            $days[$d]['pp_income'] = round((float) $row['pp_income'], 2);
        }

        // Payouts by day
        $stmt = $pdo->prepare(
            "SELECT date_initiated AS day, COALESCE(SUM(amount), 0) AS total_payouts
             FROM CG_Payouts
             WHERE status != 'Failed'
               AND date_initiated BETWEEN :start AND :end
             GROUP BY date_initiated"
        );
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['day'];
            if (!isset($days[$d])) $days[$d] = $this->emptyDay($d);
            $days[$d]['total_payouts'] = round((float) $row['total_payouts'], 2);
        }

        // Compute net for each day: Payouts - GenCosts - PayPalOut + PayPalIn
        $result = [];
        foreach ($days as $d => &$day) {
            $day['net'] = round(
                $day['total_payouts'] - $day['total_general_costs']
                - abs($day['pp_purchases']) + $day['pp_income'],
                2
            );
            $result[] = $day;
        }
        usort($result, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        jsonResponse(['month' => $month, 'days' => $result]);
    }

    private function emptyDay(string $date): array
    {
        return [
            'date' => $date,
            'auction_count' => 0, 'total_earnings' => 0, 'total_fees' => 0,
            'total_item_price' => 0, 'total_item_costs' => 0, 'total_general_costs' => 0,
            'pp_purchases' => 0, 'pp_refunds' => 0, 'pp_income' => 0,
            'total_payouts' => 0, 'net' => 0,
        ];
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

    // =========================================================
    // Tax Preparation
    // =========================================================

    /**
     * GET /api/financial-summary/tax-preview?year=YYYY
     * Calculates quarterly + annual tax summary from existing financial data.
     * Returns calculated values and any saved (draft/locked) records for the year.
     */
    public function taxPreview(array $params = []): void
    {
        try {
            $year = (int) ($_GET['year'] ?? date('Y'));
            if ($year < 2020 || $year > 2099) {
                jsonError('Invalid year', 400);
            }

            $pdo = cg_db();

            // Get excluded status IDs
            $excludedStatuses = $pdo->query(
                "SELECT status_type_id FROM CG_StatusTypes
                 WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
            )->fetchAll(\PDO::FETCH_COLUMN);
            $excludeList = implode(',', array_map('intval', $excludedStatuses));
            $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

            $quarters = [];
            for ($q = 1; $q <= 4; $q++) {
                $qStartMonth = ($q - 1) * 3 + 1;
                $qEndMonth = $q * 3;
                $qStart = sprintf('%d-%02d-01', $year, $qStartMonth);
                $qEnd = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $year, $qEndMonth)));

                // Payouts (income received)
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) AS total_payouts
                     FROM CG_Payouts WHERE status != 'Failed'
                     AND date_initiated BETWEEN :qs AND :qe"
                );
                $stmt->execute([':qs' => $qStart, ':qe' => $qEnd]);
                $totalPayouts = round((float) $stmt->fetchColumn(), 2);

                // PayPal income
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN charge_category = 'income' THEN amount ELSE 0 END), 0) AS pp_income,
                            COALESCE(SUM(CASE WHEN charge_category = 'purchase' THEN ABS(amount) ELSE 0 END), 0) AS pp_purchases
                     FROM CG_PayPalTransactions
                     WHERE transaction_date BETWEEN :qs AND :qe
                       AND charge_category IN ('purchase', 'income')"
                );
                $stmt->execute([':qs' => $qStart, ':qe' => $qEnd]);
                $ppRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                $ppIncome = round((float) $ppRow['pp_income'], 2);
                $ppPurchases = round((float) $ppRow['pp_purchases'], 2);

                // Item costs (COGS)
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(c.cost_amount), 0) AS total_item_costs
                     FROM CG_ItemCosts c
                     JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
                     WHERE a.order_placed_at BETWEEN :qs AND :qe
                       {$statusFilter}"
                );
                $stmt->execute([':qs' => $qStart . ' 00:00:00', ':qe' => $qEnd . ' 23:59:59']);
                $itemCosts = round((float) $stmt->fetchColumn(), 2);

                // Platform fees + shipping
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(
                        COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                        COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0)
                    ), 0) AS platform_fees,
                    COALESCE(SUM(a.shipping_fee), 0) AS shipping_costs
                     FROM CG_AuctionLineItems a
                     WHERE a.order_placed_at BETWEEN :qs AND :qe
                       AND a.order_placed_at IS NOT NULL
                       {$statusFilter}"
                );
                $stmt->execute([':qs' => $qStart . ' 00:00:00', ':qe' => $qEnd . ' 23:59:59']);
                $feeRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                $platformFees = round((float) $feeRow['platform_fees'], 2);
                $shippingCosts = round((float) $feeRow['shipping_costs'], 2);

                // General costs
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(total), 0) AS total_general_costs
                     FROM CG_GeneralCosts WHERE cost_date BETWEEN :qs AND :qe"
                );
                $stmt->execute([':qs' => $qStart, ':qe' => $qEnd]);
                $generalCosts = round((float) $stmt->fetchColumn(), 2);

                // Compute totals
                $grossIncome = round($totalPayouts + $ppIncome, 2);
                $totalCogs = round($itemCosts + $ppPurchases, 2);
                $grossProfit = round($grossIncome - $totalCogs, 2);
                $totalOperating = round($platformFees + $shippingCosts + $generalCosts, 2);

                $quarters[$q] = [
                    'quarter' => $q,
                    'total_payouts' => $totalPayouts,
                    'paypal_income' => $ppIncome,
                    'gross_income' => $grossIncome,
                    'item_costs' => $itemCosts,
                    'paypal_purchases' => $ppPurchases,
                    'total_cogs' => $totalCogs,
                    'platform_fees' => $platformFees,
                    'shipping_costs' => $shippingCosts,
                    'general_costs' => $generalCosts,
                    'total_operating' => $totalOperating,
                    'gross_profit' => $grossProfit,
                    'net_before_deductions' => round($grossProfit - $totalOperating, 2),
                ];
            }

            // Annual totals
            $annual = [
                'total_payouts' => 0, 'paypal_income' => 0, 'gross_income' => 0,
                'item_costs' => 0, 'paypal_purchases' => 0, 'total_cogs' => 0,
                'platform_fees' => 0, 'shipping_costs' => 0, 'general_costs' => 0,
                'total_operating' => 0, 'gross_profit' => 0, 'net_before_deductions' => 0,
            ];
            foreach ($quarters as $qd) {
                foreach ($annual as $k => &$v) {
                    if (isset($qd[$k])) $v = round($v + $qd[$k], 2);
                }
                unset($v);
            }

            // Fetch any saved tax records for this year
            $stmt = $pdo->prepare(
                "SELECT * FROM CG_TaxRecords WHERE tax_year = :yr ORDER BY tax_quarter ASC"
            );
            $stmt->execute([':yr' => $year]);
            $savedRecords = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Cast numeric fields on saved records
            foreach ($savedRecords as &$rec) {
                $rec['tax_record_id'] = (int) $rec['tax_record_id'];
                $rec['tax_year'] = (int) $rec['tax_year'];
                $rec['tax_quarter'] = $rec['tax_quarter'] !== null ? (int) $rec['tax_quarter'] : null;
                $rec['is_locked'] = (int) $rec['is_locked'];
                foreach (['total_payouts','paypal_income','gross_income','item_costs','paypal_purchases',
                          'total_cogs','platform_fees','shipping_costs','general_costs','total_operating',
                          'phone_amount','phone_deduction','mileage_deduction','equipment_deduction',
                          'supplies_deduction','advertising_deduction','other_deduction','total_deductions',
                          'gross_profit','net_profit'] as $numField) {
                    if (isset($rec[$numField])) $rec[$numField] = round((float) $rec[$numField], 2);
                }
                $rec['phone_pct'] = (int) ($rec['phone_pct'] ?? 0);
                $rec['mileage_miles'] = round((float) ($rec['mileage_miles'] ?? 0), 1);
                $rec['mileage_rate'] = round((float) ($rec['mileage_rate'] ?? 0.67), 3);
            }
            unset($rec);

            // Available years (for dropdown)
            $years = $pdo->query(
                "SELECT DISTINCT YEAR(date_initiated) AS yr FROM CG_Payouts WHERE status != 'Failed'
                 UNION
                 SELECT DISTINCT YEAR(order_placed_at) FROM CG_AuctionLineItems WHERE order_placed_at IS NOT NULL
                 ORDER BY yr DESC"
            )->fetchAll(\PDO::FETCH_COLUMN);

            jsonResponse([
                'year' => $year,
                'quarters' => array_values($quarters),
                'annual' => $annual,
                'saved_records' => $savedRecords,
                'available_years' => array_map('intval', $years),
            ]);
        } catch (\Throwable $e) {
            jsonError('Tax preview error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/financial-summary/tax-records
     * Returns all saved tax records.
     */
    public function listTaxRecords(array $params = []): void
    {
        try {
            $pdo = cg_db();
            $rows = $pdo->query(
                "SELECT tr.*, u.display_name AS created_by_name, lu.display_name AS locked_by_name
                 FROM CG_TaxRecords tr
                 JOIN CG_Users u ON u.user_id = tr.created_by
                 LEFT JOIN CG_Users lu ON lu.user_id = tr.locked_by
                 ORDER BY tr.tax_year DESC, tr.tax_quarter ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['tax_record_id'] = (int) $row['tax_record_id'];
                $row['tax_year'] = (int) $row['tax_year'];
                $row['tax_quarter'] = $row['tax_quarter'] !== null ? (int) $row['tax_quarter'] : null;
                $row['is_locked'] = (int) $row['is_locked'];
                $row['net_profit'] = round((float) $row['net_profit'], 2);
                $row['gross_income'] = round((float) $row['gross_income'], 2);
                $row['total_deductions'] = round((float) $row['total_deductions'], 2);
            }
            unset($row);

            jsonResponse(['data' => $rows]);
        } catch (\Throwable $e) {
            jsonError('Tax records error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/financial-summary/tax-records
     * Save a tax record (draft). Checks for duplicate period.
     */
    public function saveTaxRecord(array $params = []): void
    {
        try {
            $body = getJsonBody();
            $user = Auth::getCurrentUser();

            $year = (int) ($body['tax_year'] ?? 0);
            $quarter = isset($body['tax_quarter']) && $body['tax_quarter'] !== null ? (int) $body['tax_quarter'] : null;
            $periodType = $quarter ? 'quarterly' : 'annual';

            if ($year < 2020 || $year > 2099) {
                jsonError('Invalid year', 400);
            }
            if ($quarter !== null && ($quarter < 1 || $quarter > 4)) {
                jsonError('Invalid quarter', 400);
            }

            $pdo = cg_db();

            // Check for existing locked record
            $stmt = $pdo->prepare(
                "SELECT tax_record_id, is_locked FROM CG_TaxRecords
                 WHERE tax_year = :yr AND (tax_quarter = :q OR (tax_quarter IS NULL AND :q IS NULL))
                 AND period_type = :pt"
            );
            $stmt->execute([':yr' => $year, ':q' => $quarter, ':pt' => $periodType]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing && (int) $existing['is_locked'] === 1) {
                jsonError('This period is already locked and cannot be modified.', 409);
            }

            // Deduction calculations
            $phoneAmount = round((float) ($body['phone_amount'] ?? 0), 2);
            $phonePct = max(0, min(100, (int) ($body['phone_pct'] ?? 0)));
            $phoneDeduction = round($phoneAmount * $phonePct / 100, 2);

            $mileageMiles = round((float) ($body['mileage_miles'] ?? 0), 1);
            $mileageRate = round((float) ($body['mileage_rate'] ?? 0.67), 3);
            $mileageDeduction = round($mileageMiles * $mileageRate, 2);

            $equipmentDeduction = round((float) ($body['equipment_deduction'] ?? 0), 2);
            $suppliesDeduction = round((float) ($body['supplies_deduction'] ?? 0), 2);
            $advertisingDeduction = round((float) ($body['advertising_deduction'] ?? 0), 2);
            $otherDeduction = round((float) ($body['other_deduction'] ?? 0), 2);
            $deductionNotes = trim($body['deduction_notes'] ?? '');

            $totalDeductions = round(
                $phoneDeduction + $mileageDeduction + $equipmentDeduction +
                $suppliesDeduction + $advertisingDeduction + $otherDeduction, 2
            );

            // Financial figures (from preview calculation, passed from frontend)
            $totalPayouts = round((float) ($body['total_payouts'] ?? 0), 2);
            $paypalIncome = round((float) ($body['paypal_income'] ?? 0), 2);
            $grossIncome = round($totalPayouts + $paypalIncome, 2);
            $itemCosts = round((float) ($body['item_costs'] ?? 0), 2);
            $ppPurchases = round((float) ($body['paypal_purchases'] ?? 0), 2);
            $totalCogs = round($itemCosts + $ppPurchases, 2);
            $platformFees = round((float) ($body['platform_fees'] ?? 0), 2);
            $shippingCosts = round((float) ($body['shipping_costs'] ?? 0), 2);
            $generalCosts = round((float) ($body['general_costs'] ?? 0), 2);
            $totalOperating = round($platformFees + $shippingCosts + $generalCosts, 2);
            $grossProfit = round($grossIncome - $totalCogs, 2);
            $netProfit = round($grossProfit - $totalOperating - $totalDeductions, 2);

            if ($existing) {
                // Update existing draft
                $stmt = $pdo->prepare(
                    "UPDATE CG_TaxRecords SET
                        total_payouts = :tp, paypal_income = :pi, gross_income = :gi,
                        item_costs = :ic, paypal_purchases = :pp, total_cogs = :tc,
                        platform_fees = :pf, shipping_costs = :sc, general_costs = :gc,
                        total_operating = :to2,
                        phone_amount = :pa, phone_pct = :ppct, phone_deduction = :pd,
                        mileage_miles = :mm, mileage_rate = :mr, mileage_deduction = :md,
                        equipment_deduction = :ed, supplies_deduction = :sd,
                        advertising_deduction = :ad, other_deduction = :od,
                        deduction_notes = :dn, total_deductions = :td,
                        gross_profit = :gp, net_profit = :np
                     WHERE tax_record_id = :id"
                );
                $stmt->execute([
                    ':tp' => $totalPayouts, ':pi' => $paypalIncome, ':gi' => $grossIncome,
                    ':ic' => $itemCosts, ':pp' => $ppPurchases, ':tc' => $totalCogs,
                    ':pf' => $platformFees, ':sc' => $shippingCosts, ':gc' => $generalCosts,
                    ':to2' => $totalOperating,
                    ':pa' => $phoneAmount, ':ppct' => $phonePct, ':pd' => $phoneDeduction,
                    ':mm' => $mileageMiles, ':mr' => $mileageRate, ':md' => $mileageDeduction,
                    ':ed' => $equipmentDeduction, ':sd' => $suppliesDeduction,
                    ':ad' => $advertisingDeduction, ':od' => $otherDeduction,
                    ':dn' => $deductionNotes, ':td' => $totalDeductions,
                    ':gp' => $grossProfit, ':np' => $netProfit,
                    ':id' => $existing['tax_record_id'],
                ]);
                jsonResponse(['message' => 'Tax record updated', 'id' => (int) $existing['tax_record_id']]);
            } else {
                // Insert new record
                $stmt = $pdo->prepare(
                    "INSERT INTO CG_TaxRecords (
                        tax_year, tax_quarter, period_type,
                        total_payouts, paypal_income, gross_income,
                        item_costs, paypal_purchases, total_cogs,
                        platform_fees, shipping_costs, general_costs, total_operating,
                        phone_amount, phone_pct, phone_deduction,
                        mileage_miles, mileage_rate, mileage_deduction,
                        equipment_deduction, supplies_deduction, advertising_deduction,
                        other_deduction, deduction_notes, total_deductions,
                        gross_profit, net_profit, created_by
                     ) VALUES (
                        :yr, :q, :pt,
                        :tp, :pi, :gi,
                        :ic, :pp, :tc,
                        :pf, :sc, :gc, :to2,
                        :pa, :ppct, :pd,
                        :mm, :mr, :md,
                        :ed, :sd, :ad,
                        :od, :dn, :td,
                        :gp, :np, :cb
                     )"
                );
                $stmt->execute([
                    ':yr' => $year, ':q' => $quarter, ':pt' => $periodType,
                    ':tp' => $totalPayouts, ':pi' => $paypalIncome, ':gi' => $grossIncome,
                    ':ic' => $itemCosts, ':pp' => $ppPurchases, ':tc' => $totalCogs,
                    ':pf' => $platformFees, ':sc' => $shippingCosts, ':gc' => $generalCosts,
                    ':to2' => $totalOperating,
                    ':pa' => $phoneAmount, ':ppct' => $phonePct, ':pd' => $phoneDeduction,
                    ':mm' => $mileageMiles, ':mr' => $mileageRate, ':md' => $mileageDeduction,
                    ':ed' => $equipmentDeduction, ':sd' => $suppliesDeduction,
                    ':ad' => $advertisingDeduction, ':od' => $otherDeduction,
                    ':dn' => $deductionNotes, ':td' => $totalDeductions,
                    ':gp' => $grossProfit, ':np' => $netProfit,
                    ':cb' => $user['user_id'],
                ]);
                jsonResponse(['message' => 'Tax record saved', 'id' => (int) $pdo->lastInsertId()], 201);
            }
        } catch (\Throwable $e) {
            jsonError('Tax save error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/financial-summary/tax-records/{id}/lock
     * Locks a tax record so it cannot be modified.
     */
    public function lockTaxRecord(array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if ($id <= 0) {
                jsonError('Invalid record ID', 400);
            }

            $pdo = cg_db();
            $user = Auth::getCurrentUser();

            // Check record exists and is not already locked
            $stmt = $pdo->prepare("SELECT tax_record_id, is_locked FROM CG_TaxRecords WHERE tax_record_id = :id");
            $stmt->execute([':id' => $id]);
            $rec = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$rec) {
                jsonError('Tax record not found', 404);
            }
            if ((int) $rec['is_locked'] === 1) {
                jsonError('Record is already locked', 409);
            }

            $stmt = $pdo->prepare(
                "UPDATE CG_TaxRecords SET is_locked = 1, locked_by = :lb, locked_at = NOW()
                 WHERE tax_record_id = :id"
            );
            $stmt->execute([':lb' => $user['user_id'], ':id' => $id]);

            jsonResponse(['message' => 'Tax record locked']);
        } catch (\Throwable $e) {
            jsonError('Tax lock error: ' . $e->getMessage(), 500);
        }
    }
}
