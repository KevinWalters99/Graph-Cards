<?php
/**
 * Card Graph - Cards Analytics Controller
 * Analyzes card performance by player, team, maker, style, etc.
 * Only includes aligned records (parsed cards matched to actual line items).
 */
class CardsAnalyticsController
{
    /**
     * GET /api/cards-analytics/summary
     * Returns aggregated card stats by dimension (player, team, maker, style, specialty).
     * Query params:
     *   dimension = player|team|maker|style|specialty (default: player)
     *   sort = revenue|count|avg_price (default: revenue)
     *   order = desc|asc (default: desc)
     *   limit = int (default: 50)
     */
    public function summary(array $params = []): void
    {
        try {
        $pdo = cg_db();
        $dimension = $_GET['dimension'] ?? 'player';
        $sort = $_GET['sort'] ?? 'revenue';
        $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 500);

        // Excluded statuses
        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        // Build dimension-specific SELECT/GROUP BY
        switch ($dimension) {
            case 'team':
                $selectCol = "COALESCE(t.team_name, r.raw_team, 'Unknown') AS dimension_label,
                    MAX(t.mlb_id) AS team_mlb_id";
                $groupCol = "COALESCE(t.team_id, 0), COALESCE(t.team_name, r.raw_team, 'Unknown')";
                $join = "LEFT JOIN CG_Teams t ON t.team_id = r.team_id";
                break;
            case 'maker':
                $selectCol = "COALESCE(m.name, r.raw_maker, 'Unknown') AS dimension_label";
                $groupCol = "COALESCE(m.maker_id, 0), COALESCE(m.name, r.raw_maker, 'Unknown')";
                $join = "LEFT JOIN CG_CardMakers m ON m.maker_id = r.maker_id";
                break;
            case 'style':
                $selectCol = "COALESCE(s.style_name, r.raw_style, 'Unknown') AS dimension_label";
                $groupCol = "COALESCE(s.style_id, 0), COALESCE(s.style_name, r.raw_style, 'Unknown')";
                $join = "LEFT JOIN CG_CardStyles s ON s.style_id = r.style_id";
                break;
            case 'specialty':
                $selectCol = "COALESCE(sp.name, r.raw_specialty, 'Unknown') AS dimension_label";
                $groupCol = "COALESCE(sp.specialty_id, 0), COALESCE(sp.name, r.raw_specialty, 'Unknown')";
                $join = "LEFT JOIN CG_CardSpecialties sp ON sp.specialty_id = r.specialty_id";
                break;
            default: // player
                $selectCol = "COALESCE(CONCAT(p.first_name, ' ', p.last_name), r.raw_player, 'Unknown') AS dimension_label,
                    MAX(p.mlb_id) AS player_mlb_id,
                    MAX(t.mlb_id) AS team_mlb_id";
                $groupCol = "COALESCE(p.player_id, 0), COALESCE(CONCAT(p.first_name, ' ', p.last_name), r.raw_player, 'Unknown')";
                $join = "LEFT JOIN CG_Players p ON p.player_id = r.player_id
                         LEFT JOIN CG_Teams t ON t.team_id = r.team_id";
                break;
        }

        // Sort column
        $sortCol = 'total_revenue';
        if ($sort === 'count') $sortCol = 'auction_count';
        if ($sort === 'avg_price') $sortCol = 'avg_price';

        $sql = "SELECT
            {$selectCol},
            COUNT(*) AS auction_count,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END) AS total_revenue,
            AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END) AS avg_price,
            MIN(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END) AS min_price,
            MAX(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END) AS max_price,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            SUM(CASE WHEN r.is_rookie = 1 THEN 1 ELSE 0 END) AS rookie_count,
            SUM(CASE WHEN r.is_autograph = 1 THEN 1 ELSE 0 END) AS auto_count,
            SUM(CASE WHEN r.is_relic = 1 THEN 1 ELSE 0 END) AS relic_count,
            SUM(CASE WHEN r.is_graded = 1 THEN 1 ELSE 0 END) AS graded_count,
            COUNT(DISTINCT a.livestream_id) AS stream_count,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers
        FROM CG_TranscriptionRecords r
        JOIN CG_AuctionLineItems a ON a.order_id = r.matched_order_id
        {$join}
        WHERE r.is_aligned = 1
          AND r.matched_order_id IS NOT NULL
          AND a.order_placed_at IS NOT NULL
          {$statusFilter}
        GROUP BY {$groupCol}
        HAVING dimension_label != 'Unknown'
        ORDER BY {$sortCol} {$order}
        LIMIT {$limit}";

        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($rows as &$row) {
            $row['auction_count'] = (int) $row['auction_count'];
            $row['total_revenue'] = round((float) $row['total_revenue'], 2);
            $row['avg_price'] = round((float) $row['avg_price'], 2);
            $row['min_price'] = round((float) ($row['min_price'] ?? 0), 2);
            $row['max_price'] = round((float) ($row['max_price'] ?? 0), 2);
            $row['giveaway_count'] = (int) $row['giveaway_count'];
            $row['rookie_count'] = (int) $row['rookie_count'];
            $row['auto_count'] = (int) $row['auto_count'];
            $row['relic_count'] = (int) $row['relic_count'];
            $row['graded_count'] = (int) $row['graded_count'];
            $row['stream_count'] = (int) $row['stream_count'];
            $row['unique_buyers'] = (int) $row['unique_buyers'];
            if (isset($row['player_mlb_id'])) $row['player_mlb_id'] = $row['player_mlb_id'] ? (int) $row['player_mlb_id'] : null;
            if (isset($row['team_mlb_id'])) $row['team_mlb_id'] = $row['team_mlb_id'] ? (int) $row['team_mlb_id'] : null;
        }
        unset($row);

        jsonResponse(['data' => $rows, 'dimension' => $dimension, 'total' => count($rows)]);
        } catch (\Throwable $e) {
            jsonError('Cards analytics error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/cards-analytics/totals
     * Returns overall card analytics totals for the summary cards at top.
     */
    public function totals(array $params = []): void
    {
        try {
        $pdo = cg_db();

        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled', 'Refused', 'Did Not Pay', 'Returned', 'Disputed')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        $sql = "SELECT
            COUNT(*) AS total_aligned,
            COUNT(DISTINCT COALESCE(r.player_id, 0)) AS unique_players,
            COUNT(DISTINCT COALESCE(r.team_id, 0)) AS unique_teams,
            COUNT(DISTINCT COALESCE(r.maker_id, 0)) AS unique_makers,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END) AS total_revenue,
            AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END) AS avg_price,
            SUM(CASE WHEN r.is_rookie = 1 THEN 1 ELSE 0 END) AS rookie_count,
            SUM(CASE WHEN r.is_autograph = 1 THEN 1 ELSE 0 END) AS auto_count,
            SUM(CASE WHEN r.is_graded = 1 THEN 1 ELSE 0 END) AS graded_count
        FROM CG_TranscriptionRecords r
        JOIN CG_AuctionLineItems a ON a.order_id = r.matched_order_id
        WHERE r.is_aligned = 1
          AND r.matched_order_id IS NOT NULL
          AND a.order_placed_at IS NOT NULL
          {$statusFilter}";

        $row = $pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);

        jsonResponse([
            'total_aligned'  => (int) $row['total_aligned'],
            'unique_players' => max(0, (int) $row['unique_players'] - 1), // subtract Unknown (0)
            'unique_teams'   => max(0, (int) $row['unique_teams'] - 1),
            'unique_makers'  => max(0, (int) $row['unique_makers'] - 1),
            'total_revenue'  => round((float) $row['total_revenue'], 2),
            'avg_price'      => round((float) $row['avg_price'], 2),
            'rookie_count'   => (int) $row['rookie_count'],
            'auto_count'     => (int) $row['auto_count'],
            'graded_count'   => (int) $row['graded_count'],
        ]);
        } catch (\Throwable $e) {
            jsonError('Cards analytics error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * TEMP: Backfill maker_id/style_id/specialty_id for existing records using bulk SQL.
     * GET /api/debug/fix-mlbids
     */
    public function fixMlbIds(array $params = []): void
    {
        try {
            $pdo = cg_db();

            // Bulk backfill maker_id via JOIN (exact match on raw_maker = maker name)
            $makerFixed = $pdo->exec(
                "UPDATE CG_TranscriptionRecords r
                 JOIN CG_CardMakers m ON LOWER(TRIM(r.raw_maker)) = LOWER(m.name) AND m.is_active = 1
                 SET r.maker_id = m.maker_id
                 WHERE r.maker_id IS NULL AND r.raw_maker IS NOT NULL AND r.raw_maker != ''"
            );

            // Bulk backfill style_id
            $styleFixed = $pdo->exec(
                "UPDATE CG_TranscriptionRecords r
                 JOIN CG_CardStyles s ON LOWER(TRIM(r.raw_style)) = LOWER(s.style_name) AND s.is_active = 1
                 SET r.style_id = s.style_id
                 WHERE r.style_id IS NULL AND r.raw_style IS NOT NULL AND r.raw_style != ''"
            );

            // Bulk backfill specialty_id
            $specFixed = $pdo->exec(
                "UPDATE CG_TranscriptionRecords r
                 JOIN CG_CardSpecialties sp ON LOWER(TRIM(r.raw_specialty)) = LOWER(sp.name) AND sp.is_active = 1
                 SET r.specialty_id = sp.specialty_id
                 WHERE r.specialty_id IS NULL AND r.raw_specialty IS NOT NULL AND r.raw_specialty != ''"
            );

            // Check remaining unmatched
            $remaining = $pdo->query(
                "SELECT 'maker' AS type, raw_maker AS val, COUNT(*) AS cnt
                 FROM CG_TranscriptionRecords WHERE maker_id IS NULL AND raw_maker IS NOT NULL AND raw_maker != ''
                 GROUP BY raw_maker
                 UNION ALL
                 SELECT 'style', raw_style, COUNT(*)
                 FROM CG_TranscriptionRecords WHERE style_id IS NULL AND raw_style IS NOT NULL AND raw_style != ''
                 GROUP BY raw_style
                 UNION ALL
                 SELECT 'specialty', raw_specialty, COUNT(*)
                 FROM CG_TranscriptionRecords WHERE specialty_id IS NULL AND raw_specialty IS NOT NULL AND raw_specialty != ''
                 GROUP BY raw_specialty"
            )->fetchAll(\PDO::FETCH_ASSOC);

            jsonResponse([
                'makers_fixed' => (int) $makerFixed,
                'styles_fixed' => (int) $styleFixed,
                'specialties_fixed' => (int) $specFixed,
                'still_unmatched' => $remaining,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }
}
