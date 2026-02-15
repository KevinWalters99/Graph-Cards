<?php
/**
 * Card Graph - Top Buyers Controller
 */
class TopBuyersController
{
    /**
     * GET /api/top-buyers/livestreams
     * Returns livestream list for dropdown.
     */
    public function livestreams(array $params = []): void
    {
        $pdo = cg_db();

        $sql = "SELECT
            l.livestream_id,
            l.livestream_title,
            DATE(MIN(a.order_placed_at)) AS stream_date,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            COUNT(*) AS total_items
        FROM CG_AuctionLineItems a
        JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
        GROUP BY l.livestream_id, l.livestream_title
        ORDER BY stream_date DESC";

        $stmt = $pdo->query($sql);
        $livestreams = $stmt->fetchAll();

        // Get available years
        $yearSql = "SELECT DISTINCT YEAR(order_placed_at) AS yr
                    FROM CG_AuctionLineItems
                    WHERE order_placed_at IS NOT NULL
                    ORDER BY yr DESC";
        $years = $pdo->query($yearSql)->fetchAll(PDO::FETCH_COLUMN);

        jsonResponse([
            'data' => $livestreams,
            'years' => $years,
        ]);
    }

    /**
     * GET /api/top-buyers?filter=xxx
     * filter can be: livestream UUID, "all", "year:2025", "year:2026"
     */
    public function index(array $params = []): void
    {
        $filter = $_GET['filter'] ?? null;
        if (empty($filter)) {
            jsonError('filter is required', 400);
        }

        $pdo = cg_db();

        // Build WHERE clauses - separate params for main and subquery
        // (PDO native prepared statements don't allow reusing named params)
        $where = '';
        $subWhere = '';
        $bindParams = [];

        if ($filter === 'all') {
            $where = '1=1';
            $subWhere = '1=1';
        } elseif (str_starts_with($filter, 'year:')) {
            $year = (int) substr($filter, 5);
            $where = 'YEAR(a.order_placed_at) = :year';
            $subWhere = 'YEAR(a2.order_placed_at) = :sub_year';
            $bindParams[':year'] = $year;
            $bindParams[':sub_year'] = $year;
        } elseif (str_starts_with($filter, 'month:')) {
            // month:YYYY-MM
            $ym = substr($filter, 6);
            $where = "DATE_FORMAT(a.order_placed_at, '%Y-%m') = :month";
            $subWhere = "DATE_FORMAT(a2.order_placed_at, '%Y-%m') = :sub_month";
            $bindParams[':month'] = $ym;
            $bindParams[':sub_month'] = $ym;
        } elseif (str_starts_with($filter, 'quarter:')) {
            // quarter:YYYY-Q
            $parts = explode('-', substr($filter, 8));
            $qYear = (int) $parts[0];
            $qNum = (int) ($parts[1] ?? 1);
            $qStartMonth = ($qNum - 1) * 3 + 1;
            $qStart = sprintf('%d-%02d-01', $qYear, $qStartMonth);
            $qEnd = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $qYear, $qNum * 3)));
            $where = 'a.order_placed_at BETWEEN :q_start AND :q_end';
            $subWhere = 'a2.order_placed_at BETWEEN :sub_q_start AND :sub_q_end';
            $bindParams[':q_start'] = $qStart . ' 00:00:00';
            $bindParams[':q_end'] = $qEnd . ' 23:59:59';
            $bindParams[':sub_q_start'] = $qStart . ' 00:00:00';
            $bindParams[':sub_q_end'] = $qEnd . ' 23:59:59';
        } else {
            $where = 'a.livestream_id = :ls_id';
            $subWhere = 'a2.livestream_id = :sub_ls_id';
            $bindParams[':ls_id'] = $filter;
            $bindParams[':sub_ls_id'] = $filter;
        }

        $sql = "SELECT
            b.buyer_name,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auctions_won,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaways_won,
            SUM(CASE WHEN a.buy_format != 'GIVEAWAY' THEN a.quantity_sold ELSE 0 END) AS items_quantity,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN a.quantity_sold ELSE 0 END) AS giveaway_quantity,
            SUM(a.quantity_sold) AS total_quantity,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE((
                SELECT SUM(c.cost_amount)
                FROM CG_ItemCosts c
                WHERE c.ledger_transaction_id IN (
                    SELECT a2.ledger_transaction_id
                    FROM CG_AuctionLineItems a2
                    WHERE a2.buyer_id = b.buyer_id AND {$subWhere}
                )
            ), 0) AS total_costs,
            MAX(CASE WHEN a.buy_format != 'GIVEAWAY' THEN 1 ELSE 0 END) AS has_purchases
        FROM CG_AuctionLineItems a
        JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
        WHERE {$where}
        GROUP BY b.buyer_id, b.buyer_name
        ORDER BY has_purchases DESC, total_quantity DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindParams);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }
}
