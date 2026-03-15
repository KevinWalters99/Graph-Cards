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
}
