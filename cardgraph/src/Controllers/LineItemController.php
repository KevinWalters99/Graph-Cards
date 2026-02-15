<?php
/**
 * Card Graph — Line Item Controller
 */
class LineItemController
{
    /**
     * GET /api/line-items
     */
    public function index(array $params = []): void
    {
        $pdo = cg_db();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
        $sortKey = $_GET['sort'] ?? 'order_placed_at';
        $sortDir = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Whitelist sort columns — maps sort key to SQL expression
        $allowedSorts = [
            'transaction_completed_at' => 'a.transaction_completed_at',
            'order_placed_at'          => 'a.order_placed_at',
            'listing_title'            => 'a.listing_title',
            'transaction_amount'       => 'a.transaction_amount',
            'original_item_price'      => 'a.original_item_price',
            'buyer_paid'               => 'a.buyer_paid',
            'buy_format'               => 'a.buy_format',
            'order_id'                 => 'a.order_id',
            'ledger_transaction_id'    => 'a.ledger_transaction_id',
            'transaction_type'         => 'a.transaction_type',
            'buyer_name'               => 'b.buyer_name',
            'cost_amount'              => 'COALESCE(ic.total_cost, 0)',
            'status_name'              => 's.status_name',
            'profit'                   => '(a.transaction_amount - COALESCE(ic.total_cost, 0))',
        ];
        if (!array_key_exists($sortKey, $allowedSorts)) {
            $sortKey = 'order_placed_at';
        }
        $sortExpr = $allowedSorts[$sortKey];

        // Build WHERE conditions
        $where = [];
        $bind = [];

        if (!empty($_GET['date_from'])) {
            $where[] = 'a.order_placed_at >= :date_from';
            $bind[':date_from'] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'a.order_placed_at <= :date_to';
            $bind[':date_to'] = $_GET['date_to'] . ' 23:59:59';
        }
        if (!empty($_GET['status'])) {
            $where[] = 'a.current_status_id = :status';
            $bind[':status'] = (int) $_GET['status'];
        }
        if (!empty($_GET['buy_format'])) {
            $where[] = 'a.buy_format = :buy_format';
            $bind[':buy_format'] = strtoupper($_GET['buy_format']);
        }
        if (!empty($_GET['livestream_id'])) {
            $where[] = 'a.livestream_id = :livestream_id';
            $bind[':livestream_id'] = $_GET['livestream_id'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(a.listing_title LIKE :search OR b.buyer_name LIKE :search2)';
            $bind[':search'] = '%' . $_GET['search'] . '%';
            $bind[':search2'] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['transaction_type'])) {
            $where[] = 'a.transaction_type = :txn_type';
            $bind[':txn_type'] = $_GET['transaction_type'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Summary aggregates
        $summarySql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN a.transaction_amount ELSE 0 END), 0) AS giveaway_net,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.transaction_amount ELSE 0 END), 0) AS total_sales,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.buyer_paid ELSE 0 END), 0) AS total_buyer_paid,
            COALESCE(SUM(COALESCE(a.commission_fee, 0)), 0) AS total_commission,
            COALESCE(SUM(COALESCE(a.payment_processing_fee, 0)), 0) AS total_processing,
            COALESCE(SUM(COALESCE(a.tax_on_commission_fee, 0)), 0) AS total_tax_commission,
            COALESCE(SUM(COALESCE(a.tax_on_payment_processing_fee, 0)), 0) AS total_tax_processing,
            COALESCE(SUM(COALESCE(a.shipping_fee, 0)), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN
                COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0) ELSE 0 END), 0) AS giveaway_fees,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.shipment_id) AS unique_shipments,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_price
        FROM CG_AuctionLineItems a
        LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
        {$whereClause}";
        $countStmt = $pdo->prepare($summarySql);
        $countStmt->execute($bind);
        $summary = $countStmt->fetch();
        $total = (int) $summary['total'];

        // Giveaways won by buyers who also purchased
        $bgWhere = !empty($where)
            ? 'WHERE ' . implode(' AND ', $where) . " AND a.buy_format = 'GIVEAWAY'"
            : "WHERE a.buy_format = 'GIVEAWAY'";
        $bgSql = "SELECT COUNT(*) AS buyer_giveaways
            FROM CG_AuctionLineItems a
            LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
            {$bgWhere}
            AND a.buyer_id IN (
                SELECT DISTINCT a2.buyer_id FROM CG_AuctionLineItems a2
                WHERE a2.buy_format = 'AUCTION'
            )";
        $bgStmt = $pdo->prepare($bgSql);
        $bgStmt->execute($bind);
        $buyerGiveaways = (int) $bgStmt->fetch()['buyer_giveaways'];

        // Costs for filtered items
        $costSql = "SELECT COALESCE(SUM(c.cost_amount), 0) AS total_costs
        FROM CG_ItemCosts c
        JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
        LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
        {$whereClause}";
        $costStmt = $pdo->prepare($costSql);
        $costStmt->execute($bind);
        $costs = $costStmt->fetch();

        // Data
        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT
            a.ledger_transaction_id, a.order_id, a.transaction_type, a.listing_title,
            a.listing_description, a.buy_format, a.product_category,
            a.transaction_amount, a.buyer_paid, a.original_item_price,
            a.shipping_fee, a.commission_fee, a.payment_processing_fee,
            a.transaction_completed_at, a.order_placed_at,
            a.current_status_id, a.shipment_id,
            b.buyer_name, b.buyer_state,
            s.status_name,
            l.livestream_title,
            COALESCE(ic.total_cost, 0) AS cost_amount
        FROM CG_AuctionLineItems a
        LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
        LEFT JOIN CG_StatusTypes s ON s.status_type_id = a.current_status_id
        LEFT JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
        LEFT JOIN (
            SELECT ledger_transaction_id, SUM(cost_amount) AS total_cost
            FROM CG_ItemCosts GROUP BY ledger_transaction_id
        ) ic ON ic.ledger_transaction_id = a.ledger_transaction_id
        {$whereClause}
        ORDER BY {$sortExpr} {$sortDir}
        LIMIT {$perPage} OFFSET {$offset}";

        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($bind);

        jsonResponse([
            'data'     => $dataStmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'sort'     => $sortKey,
            'order'    => $sortDir,
            'summary'  => $this->buildSummary($summary, $costs, $buyerGiveaways),
        ]);
    }

    /**
     * GET /api/line-items/{id}
     */
    public function show(array $params = []): void
    {
        $id = $params['id'] ?? '';
        if (empty($id)) {
            jsonError('Missing line item ID', 400);
        }

        $pdo = cg_db();

        // Line item details
        $stmt = $pdo->prepare(
            "SELECT a.*, b.buyer_name, b.buyer_state, b.buyer_country,
                    s.status_name, l.livestream_title,
                    COALESCE(ic.total_cost, 0) AS cost_amount
             FROM CG_AuctionLineItems a
             LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
             LEFT JOIN CG_StatusTypes s ON s.status_type_id = a.current_status_id
             LEFT JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
             LEFT JOIN (
                SELECT ledger_transaction_id, SUM(cost_amount) AS total_cost
                FROM CG_ItemCosts GROUP BY ledger_transaction_id
             ) ic ON ic.ledger_transaction_id = a.ledger_transaction_id
             WHERE a.ledger_transaction_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            jsonError('Line item not found', 404);
        }

        // Status history
        $histStmt = $pdo->prepare(
            "SELECT h.*, s_old.status_name AS old_status_name,
                    s_new.status_name AS new_status_name,
                    u.display_name AS changed_by_name
             FROM CG_StatusHistory h
             LEFT JOIN CG_StatusTypes s_old ON s_old.status_type_id = h.old_status_id
             JOIN CG_StatusTypes s_new ON s_new.status_type_id = h.new_status_id
             JOIN CG_Users u ON u.user_id = h.changed_by
             WHERE h.ledger_transaction_id = :id
             ORDER BY h.changed_at DESC"
        );
        $histStmt->execute([':id' => $id]);

        // Cost entries
        $costStmt = $pdo->prepare(
            "SELECT c.*, u.display_name AS entered_by_name
             FROM CG_ItemCosts c
             JOIN CG_Users u ON u.user_id = c.entered_by
             WHERE c.ledger_transaction_id = :id
             ORDER BY c.created_at DESC"
        );
        $costStmt->execute([':id' => $id]);

        jsonResponse([
            'item'    => $item,
            'history' => $histStmt->fetchAll(),
            'costs'   => $costStmt->fetchAll(),
        ]);
    }

    /**
     * GET /api/livestreams
     * Returns livestream list for filter dropdowns. Available to all authenticated users.
     */
    public function livestreams(array $params = []): void
    {
        $sql = "SELECT
            l.livestream_id,
            l.livestream_title,
            DATE(MIN(a.order_placed_at)) AS stream_date,
            COUNT(*) AS total_items
        FROM CG_AuctionLineItems a
        JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
        GROUP BY l.livestream_id, l.livestream_title
        ORDER BY stream_date DESC";

        $stmt = cg_db()->query($sql);
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * Build the full financial summary from query results.
     */
    private function buildSummary(array $summary, array $costs, int $buyerGiveaways): array
    {
        $totalSales       = round((float) $summary['total_sales'], 2);
        $totalEarnings    = round((float) $summary['total_earnings'], 2);
        $totalItemPrice   = round((float) $summary['total_item_price'], 2);
        $totalBuyerPaid   = round((float) $summary['total_buyer_paid'], 2);
        $commission       = round((float) $summary['total_commission'], 2);
        $processing       = round((float) $summary['total_processing'], 2);
        $taxCommission    = round((float) $summary['total_tax_commission'], 2);
        $taxProcessing    = round((float) $summary['total_tax_processing'], 2);
        $shipping         = round((float) $summary['total_shipping'], 2);
        $totalFees        = round($commission + $processing + $taxCommission + $taxProcessing + $shipping, 2);
        $giveawayFees     = round((float) $summary['giveaway_fees'], 2);
        $auctionFees      = round($totalFees - $giveawayFees, 2);
        $totalCosts       = round((float) $costs['total_costs'], 2);
        $giveawayNet      = round((float) $summary['giveaway_net'], 2);

        $profit    = round($totalItemPrice - $totalFees - $totalCosts, 2);
        $profitPct = ($totalItemPrice > 0) ? round(($profit / $totalItemPrice) * 100, 2) : 0;

        return [
            'auction_count'       => (int) $summary['auction_count'],
            'giveaway_count'      => (int) $summary['giveaway_count'],
            'giveaway_net'        => $giveawayNet,
            'unique_buyers'       => (int) $summary['unique_buyers'],
            'unique_shipments'    => (int) $summary['unique_shipments'],
            'avg_price'           => round((float) $summary['avg_price'], 2),
            'tip_count'           => (int) $summary['tip_count'],
            'total_tips'          => round((float) $summary['total_tips'], 2),
            'buyer_giveaways'     => $buyerGiveaways,
            'total_item_price'    => $totalItemPrice,
            'total_buyer_paid'    => $totalBuyerPaid,
            'total_sales'         => $totalSales,
            'total_earnings'      => $totalEarnings,
            'total_shipping'      => $shipping,
            'commission_fee'      => $commission,
            'processing_fee'      => $processing,
            'tax_on_commission'   => $taxCommission,
            'tax_on_processing'   => $taxProcessing,
            'total_fees'          => $totalFees,
            'auction_fees'        => $auctionFees,
            'giveaway_fees'       => $giveawayFees,
            'total_costs'         => $totalCosts,
            'profit'              => $profit,
            'profit_pct'          => $profitPct,
        ];
    }
}
