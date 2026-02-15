<?php
/**
 * Card Graph — Dashboard Controller
 */
class DashboardController
{
    /**
     * GET /api/dashboard/summary
     * Query params: date_from, date_to
     */
    public function summary(array $params = []): void
    {
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $pdo = cg_db();

        // Build date conditions
        $conditions = '';
        $bindParams = [];
        if ($dateFrom) {
            $conditions .= ' AND a.order_placed_at >= :date_from';
            $bindParams[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $conditions .= ' AND a.order_placed_at <= :date_to';
            $bindParams[':date_to'] = $dateTo . ' 23:59:59';
        }

        // Main summary
        $sql = "SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN a.transaction_type = 'ORDER_EARNINGS' AND a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
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
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.buyer_paid ELSE 0 END), 0) AS total_buyer_paid,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_auction_price,
            COALESCE(SUM(COALESCE(a.commission_fee, 0)), 0) AS commission_fee,
            COALESCE(SUM(COALESCE(a.payment_processing_fee, 0)), 0) AS processing_fee,
            COALESCE(SUM(COALESCE(a.tax_on_commission_fee, 0)), 0) AS tax_on_commission,
            COALESCE(SUM(COALESCE(a.tax_on_payment_processing_fee, 0)), 0) AS tax_on_processing,
            COALESCE(SUM(COALESCE(a.shipping_fee, 0)), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN
                COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0) ELSE 0 END), 0) AS giveaway_fees,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.livestream_id) AS unique_livestreams,
            COUNT(DISTINCT a.shipment_id) AS unique_shipments
        FROM CG_AuctionLineItems a
        WHERE 1=1 {$conditions}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindParams);
        $summary = $stmt->fetch();

        // Giveaways won by buyers who also purchased
        $bgSql = "SELECT COUNT(*) AS buyer_giveaways
            FROM CG_AuctionLineItems a
            WHERE a.buy_format = 'GIVEAWAY'
            AND a.buyer_id IN (
                SELECT DISTINCT a2.buyer_id FROM CG_AuctionLineItems a2
                WHERE a2.buy_format = 'AUCTION'
            )
            {$conditions}";
        $bgStmt = $pdo->prepare($bgSql);
        $bgStmt->execute($bindParams);
        $buyerGiveaways = (int) $bgStmt->fetch()['buyer_giveaways'];

        // Payout totals (with date filter on date_initiated)
        $payoutConditions = '';
        $payoutParams = [];
        if ($dateFrom) {
            $payoutConditions .= ' AND p.date_initiated >= :pdate_from';
            $payoutParams[':pdate_from'] = $dateFrom;
        }
        if ($dateTo) {
            $payoutConditions .= ' AND p.date_initiated <= :pdate_to';
            $payoutParams[':pdate_to'] = $dateTo;
        }

        $payoutSql = "SELECT
            COALESCE(SUM(p.amount), 0) AS total_payouts,
            COUNT(*) AS payout_count
        FROM CG_Payouts p
        WHERE p.status != 'Failed' {$payoutConditions}";

        $stmt = $pdo->prepare($payoutSql);
        $stmt->execute($payoutParams);
        $payouts = $stmt->fetch();

        // Statement count
        $stmtCount = $pdo->query("SELECT COUNT(*) AS cnt FROM CG_EarningsStatements")->fetch();

        // Cost data
        $costSql = "SELECT
            COALESCE(SUM(c.cost_amount), 0) AS total_costs
        FROM CG_ItemCosts c
        JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
        WHERE 1=1 {$conditions}";
        $stmt = $pdo->prepare($costSql);
        $stmt->execute($bindParams);
        $costs = $stmt->fetch();

        // Top buyer helper query (by item count)
        $topBuyerSql = function (string $where) use ($pdo) {
            $sql = "SELECT b.buyer_id, b.buyer_name,
                    SUM(a.quantity_sold) AS total_items,
                    COALESCE(SUM(a.transaction_amount), 0) AS total_value
                FROM CG_AuctionLineItems a
                JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
                WHERE a.buy_format != 'GIVEAWAY' AND {$where}
                GROUP BY b.buyer_id, b.buyer_name
                ORDER BY total_items DESC
                LIMIT 1";
            $row = $pdo->query($sql)->fetch();
            if (!$row) return null;
            return [
                'buyer_id'    => (int) $row['buyer_id'],
                'buyer_name'  => $row['buyer_name'],
                'total_items' => (int) $row['total_items'],
                'total_value' => round((float) $row['total_value'], 2),
            ];
        };

        // Top spender helper query (by dollar amount)
        $topSpenderSql = function (string $where) use ($pdo) {
            $sql = "SELECT b.buyer_id, b.buyer_name,
                    SUM(a.quantity_sold) AS total_items,
                    COALESCE(SUM(a.transaction_amount), 0) AS total_value
                FROM CG_AuctionLineItems a
                JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
                WHERE a.buy_format != 'GIVEAWAY' AND {$where}
                GROUP BY b.buyer_id, b.buyer_name
                ORDER BY total_value DESC
                LIMIT 1";
            $row = $pdo->query($sql)->fetch();
            if (!$row) return null;
            return [
                'buyer_id'    => (int) $row['buyer_id'],
                'buyer_name'  => $row['buyer_name'],
                'total_items' => (int) $row['total_items'],
                'total_value' => round((float) $row['total_value'], 2),
            ];
        };

        // Top buyer: last stream
        $lastLs = $pdo->query(
            "SELECT l.livestream_id, l.livestream_title, DATE(MIN(a.order_placed_at)) AS stream_date
             FROM CG_AuctionLineItems a
             JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
             GROUP BY l.livestream_id, l.livestream_title
             ORDER BY stream_date DESC LIMIT 1"
        )->fetch();
        $topBuyerLastStream = null;
        $lastStreamLabel = null;
        if ($lastLs) {
            $topBuyerLastStream = $topBuyerSql("a.livestream_id = " . $pdo->quote($lastLs['livestream_id']));
            $lastStreamLabel = $lastLs['livestream_title'] . ' (' . $lastLs['stream_date'] . ')';
        }

        // Top buyer: last month
        $lastMonth = date('Y-m', strtotime('-1 month'));
        $topBuyerLastMonth = $topBuyerSql(
            "DATE_FORMAT(a.order_placed_at, '%Y-%m') = " . $pdo->quote($lastMonth)
        );

        // Top buyer: this year
        $currentYear = date('Y');
        $topBuyerYear = $topBuyerSql("YEAR(a.order_placed_at) = {$currentYear}");

        // Top spender: last stream, last month, year
        $topSpenderLastStream = $lastLs
            ? $topSpenderSql("a.livestream_id = " . $pdo->quote($lastLs['livestream_id']))
            : null;
        $topSpenderLastMonth = $topSpenderSql(
            "DATE_FORMAT(a.order_placed_at, '%Y-%m') = " . $pdo->quote($lastMonth)
        );
        $topSpenderYear = $topSpenderSql("YEAR(a.order_placed_at) = {$currentYear}");

        $totalItemPrice = round((float) $summary['total_item_price'], 2);
        $totalFees      = round((float) $summary['total_fees'], 2);
        $totalCosts     = round((float) $costs['total_costs'], 2);
        $giveawayFees   = round((float) $summary['giveaway_fees'], 2);
        $auctionFees    = round($totalFees - $giveawayFees, 2);
        $profit         = round($totalItemPrice - $totalFees - $totalCosts, 2);
        $profitPct      = ($totalItemPrice > 0) ? round(($profit / $totalItemPrice) * 100, 2) : 0;

        jsonResponse([
            'total_items'          => (int) $summary['total_items'],
            'auction_count'        => (int) $summary['auction_count'],
            'giveaway_count'       => (int) $summary['giveaway_count'],
            'giveaway_net'         => round((float) $summary['giveaway_net'], 2),
            'shipping_charge_count' => (int) $summary['shipping_charge_count'],
            'tip_count'            => (int) $summary['tip_count'],
            'total_tips'           => round((float) $summary['total_tips'], 2),
            'total_earnings'       => round((float) $summary['total_earnings'], 2),
            'total_fees'           => $totalFees,
            'net_earnings'         => round((float) $summary['total_earnings'], 2),
            'total_item_price'     => $totalItemPrice,
            'total_buyer_paid'     => round((float) $summary['total_buyer_paid'], 2),
            'avg_auction_price'    => round((float) $summary['avg_auction_price'], 2),
            'commission_fee'       => round((float) $summary['commission_fee'], 2),
            'processing_fee'       => round((float) $summary['processing_fee'], 2),
            'tax_on_commission'    => round((float) $summary['tax_on_commission'], 2),
            'tax_on_processing'    => round((float) $summary['tax_on_processing'], 2),
            'total_shipping'       => round((float) $summary['total_shipping'], 2),
            'giveaway_fees'        => $giveawayFees,
            'auction_fees'         => $auctionFees,
            'profit'               => $profit,
            'profit_pct'           => $profitPct,
            'unique_buyers'        => (int) $summary['unique_buyers'],
            'unique_livestreams'   => (int) $summary['unique_livestreams'],
            'unique_shipments'     => (int) $summary['unique_shipments'],
            'buyer_giveaways'      => $buyerGiveaways,
            'total_payouts'        => round((float) $payouts['total_payouts'], 2),
            'payout_count'         => (int) $payouts['payout_count'],
            'total_costs'          => $totalCosts,
            'statements_uploaded'  => (int) $stmtCount['cnt'],
            'top_buyer_last_stream' => $topBuyerLastStream,
            'top_buyer_last_stream_label' => $lastStreamLabel,
            'top_buyer_last_month' => $topBuyerLastMonth,
            'top_buyer_last_month_label' => date('F Y', strtotime($lastMonth . '-01')),
            'top_buyer_year'       => $topBuyerYear,
            'top_buyer_year_label' => $currentYear,
            'top_spender_last_stream' => $topSpenderLastStream,
            'top_spender_last_month'  => $topSpenderLastMonth,
            'top_spender_year'        => $topSpenderYear,
        ]);
    }

    /**
     * GET /api/dashboard/trends
     * Query params: date_from, date_to, group_by (day|week|month)
     */
    public function trends(array $params = []): void
    {
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $groupBy = $_GET['group_by'] ?? 'day';

        $pdo = cg_db();

        $dateExpr = match ($groupBy) {
            'week'  => "DATE_FORMAT(a.order_placed_at, '%x-W%v')",
            'month' => "DATE_FORMAT(a.order_placed_at, '%Y-%m')",
            default => "DATE(a.order_placed_at)",
        };

        $conditions = '';
        $bindParams = [];
        if ($dateFrom) {
            $conditions .= ' AND a.order_placed_at >= :date_from';
            $bindParams[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $conditions .= ' AND a.order_placed_at <= :date_to';
            $bindParams[':date_to'] = $dateTo . ' 23:59:59';
        }

        $sql = "SELECT
            {$dateExpr} AS period,
            COUNT(*) AS item_count,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            COALESCE(SUM(a.transaction_amount), 0) AS earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS fees
        FROM CG_AuctionLineItems a
        WHERE a.order_placed_at IS NOT NULL {$conditions}
        GROUP BY period
        ORDER BY period";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindParams);

        // Livestream breakdown
        $lsSql = "SELECT
            l.livestream_title,
            DATE(MIN(a.order_placed_at)) AS stream_date,
            COUNT(*) AS item_count,
            COALESCE(SUM(a.transaction_amount), 0) AS earnings,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COUNT(DISTINCT a.shipment_id) AS shipment_count
        FROM CG_AuctionLineItems a
        JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
        WHERE 1=1 {$conditions}
        GROUP BY l.livestream_id, l.livestream_title
        ORDER BY stream_date DESC, earnings DESC";

        $lsStmt = $pdo->prepare($lsSql);
        $lsStmt->execute($bindParams);

        jsonResponse([
            'trends'      => $stmt->fetchAll(),
            'livestreams' => $lsStmt->fetchAll(),
        ]);
    }
}
