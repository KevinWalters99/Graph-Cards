<?php
/**
 * Card Graph - Cost Matrix Controller
 * Admin-only endpoints for managing pricing rules and applying costs to auctions.
 */
class CostMatrixController
{
    /**
     * GET /api/cost-matrix/rules
     */
    public function listRules(array $params = []): void
    {
        Auth::requireAdmin();

        $stmt = cg_db()->query(
            "SELECT * FROM CG_CostMatrixRules ORDER BY display_order ASC, min_price ASC"
        );

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * POST /api/cost-matrix/rules
     */
    public function createRule(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();

        $minPrice    = (float) ($body['min_price'] ?? 0);
        $maxPrice    = isset($body['max_price']) && $body['max_price'] !== '' && $body['max_price'] !== null
                       ? (float) $body['max_price'] : null;
        $pctRate     = (float) ($body['pct_rate'] ?? 0);
        $fixedCost   = (float) ($body['fixed_cost'] ?? 0);
        $minimumCost = (float) ($body['minimum_cost'] ?? 0);
        $displayOrder = (int) ($body['display_order'] ?? 0);

        $stmt = cg_db()->prepare(
            "INSERT INTO CG_CostMatrixRules (min_price, max_price, pct_rate, fixed_cost, minimum_cost, display_order)
             VALUES (:min, :max, :pct, :fixed, :minimum, :ord)"
        );
        $stmt->execute([
            ':min'     => $minPrice,
            ':max'     => $maxPrice,
            ':pct'     => $pctRate,
            ':fixed'   => $fixedCost,
            ':minimum' => $minimumCost,
            ':ord'     => $displayOrder,
        ]);

        jsonResponse(['rule_id' => (int) cg_db()->lastInsertId(), 'message' => 'Rule created'], 201);
    }

    /**
     * PUT /api/cost-matrix/rules/{id}
     */
    public function updateRule(array $params = []): void
    {
        Auth::requireAdmin();
        $ruleId = (int) ($params['id'] ?? 0);
        $body = getJsonBody();

        $stmt = cg_db()->prepare("SELECT * FROM CG_CostMatrixRules WHERE rule_id = :id");
        $stmt->execute([':id' => $ruleId]);
        if (!$stmt->fetch()) {
            jsonError('Rule not found', 404);
        }

        $sets = [];
        $bind = [':id' => $ruleId];

        $fields = [
            'min_price'     => 'float',
            'max_price'     => 'float_nullable',
            'pct_rate'      => 'float',
            'fixed_cost'    => 'float',
            'minimum_cost'  => 'float',
            'display_order' => 'int',
            'is_active'     => 'int',
        ];

        foreach ($fields as $field => $type) {
            if (array_key_exists($field, $body)) {
                if ($type === 'float_nullable') {
                    $val = ($body[$field] === '' || $body[$field] === null) ? null : (float) $body[$field];
                } elseif ($type === 'float') {
                    $val = (float) $body[$field];
                } else {
                    $val = (int) $body[$field];
                }
                $sets[] = "$field = :$field";
                $bind[":$field"] = $val;
            }
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql = "UPDATE CG_CostMatrixRules SET " . implode(', ', $sets) . " WHERE rule_id = :id";
        cg_db()->prepare($sql)->execute($bind);

        jsonResponse(['message' => 'Rule updated']);
    }

    /**
     * DELETE /api/cost-matrix/rules/{id}
     */
    public function deleteRule(array $params = []): void
    {
        Auth::requireAdmin();
        $ruleId = (int) ($params['id'] ?? 0);

        $stmt = cg_db()->prepare("DELETE FROM CG_CostMatrixRules WHERE rule_id = :id");
        $stmt->execute([':id' => $ruleId]);

        if ($stmt->rowCount() === 0) {
            jsonError('Rule not found', 404);
        }

        jsonResponse(['message' => 'Rule deleted']);
    }

    /**
     * GET /api/cost-matrix/livestreams
     */
    public function livestreams(array $params = []): void
    {
        Auth::requireAdmin();

        $sql = "SELECT
            l.livestream_id,
            l.livestream_title,
            DATE(MIN(a.order_placed_at)) AS stream_date,
            COUNT(*) AS total_items,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            SUM(a.original_item_price) AS total_revenue
        FROM CG_AuctionLineItems a
        JOIN CG_Livestreams l ON l.livestream_id = a.livestream_id
        GROUP BY l.livestream_id, l.livestream_title
        ORDER BY stream_date DESC";

        $stmt = cg_db()->query($sql);
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * POST /api/cost-matrix/preview
     * Body: { livestream_id }
     */
    public function preview(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $livestreamId = trim($body['livestream_id'] ?? '');

        if (empty($livestreamId)) {
            jsonError('livestream_id is required', 400);
        }

        $result = $this->calculateCosts($livestreamId);
        jsonResponse($result);
    }

    /**
     * POST /api/cost-matrix/apply
     * Body: { livestream_id }
     */
    public function apply(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(120);
        $userId = Auth::getUserId();
        $body = getJsonBody();
        $livestreamId = trim($body['livestream_id'] ?? '');

        if (empty($livestreamId)) {
            jsonError('livestream_id is required', 400);
        }

        $pdo = cg_db();

        // Delete existing matrix-applied costs for this livestream
        $delStmt = $pdo->prepare(
            "DELETE ic FROM CG_ItemCosts ic
             JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = ic.ledger_transaction_id
             WHERE a.livestream_id = :ls AND ic.cost_description = 'Cost Matrix'"
        );
        $delStmt->execute([':ls' => $livestreamId]);
        $deleted = $delStmt->rowCount();

        // Calculate costs
        $result = $this->calculateCosts($livestreamId);

        if (empty($result['items'])) {
            jsonResponse([
                'message'  => 'No items found for this auction',
                'deleted'  => $deleted,
                'inserted' => 0,
            ]);
            return;
        }

        // Batch insert using multi-row INSERT for speed
        $toInsert = [];
        foreach ($result['items'] as $item) {
            if ($item['cost'] > 0) {
                $toInsert[] = $item;
            }
        }

        $inserted = 0;
        if (!empty($toInsert)) {
            // Build multi-row insert in chunks of 100
            $chunks = array_chunk($toInsert, 100);
            foreach ($chunks as $chunk) {
                $placeholders = [];
                $bind = [];
                foreach ($chunk as $i => $item) {
                    $placeholders[] = "(:lid{$i}, :amt{$i}, 'Cost Matrix', :uid{$i})";
                    $bind[":lid{$i}"] = $item['ledger_transaction_id'];
                    $bind[":amt{$i}"] = $item['cost'];
                    $bind[":uid{$i}"] = $userId;
                }
                $sql = "INSERT INTO CG_ItemCosts (ledger_transaction_id, cost_amount, cost_description, entered_by) VALUES "
                     . implode(', ', $placeholders);
                $pdo->prepare($sql)->execute($bind);
                $inserted += count($chunk);
            }
        }

        jsonResponse([
            'message'    => "Applied cost matrix: $inserted costs written",
            'deleted'    => $deleted,
            'inserted'   => $inserted,
            'total_cost' => $result['total_cost'],
            'item_count' => $result['item_count'],
        ]);
    }

    /**
     * POST /api/cost-matrix/clear
     * Body: { livestream_id }
     */
    public function clear(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $livestreamId = trim($body['livestream_id'] ?? '');

        if (empty($livestreamId)) {
            jsonError('livestream_id is required', 400);
        }

        $stmt = cg_db()->prepare(
            "DELETE ic FROM CG_ItemCosts ic
             JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = ic.ledger_transaction_id
             WHERE a.livestream_id = :ls AND ic.cost_description = 'Cost Matrix'"
        );
        $stmt->execute([':ls' => $livestreamId]);

        jsonResponse([
            'message' => $stmt->rowCount() . ' matrix cost(s) cleared',
            'deleted' => $stmt->rowCount(),
        ]);
    }

    /**
     * GET /api/cost-matrix/auction-summary?livestream_id=X
     * Returns scorecard metrics for a specific auction.
     */
    public function auctionSummary(array $params = []): void
    {
        Auth::requireAdmin();

        $livestreamId = trim($_GET['livestream_id'] ?? '');
        if (empty($livestreamId)) {
            jsonError('livestream_id is required', 400);
        }

        $pdo = cg_db();

        $sql = "SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS auction_count,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_item_price,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.buyer_paid ELSE 0 END), 0) AS total_buyer_paid,
            COALESCE(SUM(a.transaction_amount), 0) AS total_earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) +
                COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) +
                COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS total_fees,
            COALESCE(SUM(COALESCE(a.commission_fee, 0)), 0) AS commission_fee,
            COALESCE(SUM(COALESCE(a.payment_processing_fee, 0)), 0) AS processing_fee,
            COALESCE(SUM(COALESCE(a.tax_on_commission_fee, 0)), 0) AS tax_on_commission,
            COALESCE(SUM(COALESCE(a.tax_on_payment_processing_fee, 0)), 0) AS tax_on_processing,
            COALESCE(SUM(COALESCE(a.shipping_fee, 0)), 0) AS total_shipping,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN
                COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0) ELSE 0 END), 0) AS giveaway_fees,
            COALESCE(AVG(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price END), 0) AS avg_item_price,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.shipment_id) AS unique_shipments,
            SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN 1 ELSE 0 END) AS giveaway_count,
            COALESCE(SUM(CASE WHEN a.buy_format = 'GIVEAWAY' THEN a.transaction_amount ELSE 0 END), 0) AS giveaway_net,
            SUM(CASE WHEN a.transaction_type = 'TIP' THEN 1 ELSE 0 END) AS tip_count,
            COALESCE(SUM(CASE WHEN a.transaction_type = 'TIP' THEN a.transaction_amount ELSE 0 END), 0) AS total_tips
        FROM CG_AuctionLineItems a
        WHERE a.livestream_id = :ls";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ls' => $livestreamId]);
        $summary = $stmt->fetch();

        $costSql = "SELECT COALESCE(SUM(c.cost_amount), 0) AS total_costs
            FROM CG_ItemCosts c
            JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
            WHERE a.livestream_id = :ls";
        $costStmt = $pdo->prepare($costSql);
        $costStmt->execute([':ls' => $livestreamId]);
        $costs = $costStmt->fetch();

        // Giveaways won by buyers who also purchased
        $bgSql = "SELECT COUNT(*) AS buyer_giveaways
            FROM CG_AuctionLineItems a
            WHERE a.buy_format = 'GIVEAWAY'
            AND a.livestream_id = :ls
            AND a.buyer_id IN (
                SELECT DISTINCT a2.buyer_id FROM CG_AuctionLineItems a2
                WHERE a2.buy_format = 'AUCTION'
            )";
        $bgStmt = $pdo->prepare($bgSql);
        $bgStmt->execute([':ls' => $livestreamId]);
        $buyerGiveaways = (int) $bgStmt->fetch()['buyer_giveaways'];

        $totalItemPrice = round((float) $summary['total_item_price'], 2);
        $totalEarnings  = round((float) $summary['total_earnings'], 2);
        $totalFees      = round((float) $summary['total_fees'], 2);
        $totalCosts     = round((float) $costs['total_costs'], 2);
        $giveawayFees   = round((float) $summary['giveaway_fees'], 2);
        $auctionFees    = round($totalFees - $giveawayFees, 2);
        $profit         = round($totalItemPrice - $totalFees - $totalCosts, 2);
        $profitPct      = ($totalItemPrice > 0) ? round(($profit / $totalItemPrice) * 100, 2) : 0;

        jsonResponse([
            'total_items'     => (int) $summary['total_items'],
            'auction_count'   => (int) $summary['auction_count'],
            'total_item_price' => $totalItemPrice,
            'total_buyer_paid' => round((float) $summary['total_buyer_paid'], 2),
            'total_earnings'  => $totalEarnings,
            'total_fees'      => $totalFees,
            'commission_fee'  => round((float) $summary['commission_fee'], 2),
            'processing_fee'  => round((float) $summary['processing_fee'], 2),
            'tax_on_commission'  => round((float) $summary['tax_on_commission'], 2),
            'tax_on_processing'  => round((float) $summary['tax_on_processing'], 2),
            'total_shipping'  => round((float) $summary['total_shipping'], 2),
            'giveaway_fees'   => $giveawayFees,
            'auction_fees'    => $auctionFees,
            'total_costs'     => $totalCosts,
            'profit'          => $profit,
            'profit_pct'      => $profitPct,
            'avg_item_price'  => round((float) $summary['avg_item_price'], 2),
            'unique_buyers'   => (int) $summary['unique_buyers'],
            'unique_shipments' => (int) $summary['unique_shipments'],
            'giveaway_count'  => (int) $summary['giveaway_count'],
            'giveaway_net'    => round((float) $summary['giveaway_net'], 2),
            'buyer_giveaways' => $buyerGiveaways,
            'tip_count'       => (int) $summary['tip_count'],
            'total_tips'      => round((float) $summary['total_tips'], 2),
        ]);
    }

    /**
     * Calculate costs for all items in a livestream using active rules.
     */
    private function calculateCosts(string $livestreamId): array
    {
        $pdo = cg_db();

        // Load active rules
        $rulesStmt = $pdo->query(
            "SELECT * FROM CG_CostMatrixRules WHERE is_active = 1 ORDER BY display_order ASC, min_price ASC"
        );
        $rules = $rulesStmt->fetchAll();

        if (empty($rules)) {
            return [
                'item_count' => 0,
                'total_cost' => 0,
                'items'      => [],
                'tiers'      => [],
                'error'      => 'No active rules defined',
            ];
        }

        // Load items for this livestream
        $itemsStmt = $pdo->prepare(
            "SELECT ledger_transaction_id, listing_title, original_item_price, transaction_amount
             FROM CG_AuctionLineItems
             WHERE livestream_id = :ls
             ORDER BY original_item_price ASC"
        );
        $itemsStmt->execute([':ls' => $livestreamId]);
        $items = $itemsStmt->fetchAll();

        $results = [];
        $totalCost = 0;
        $tierCounts = [];

        foreach ($items as $item) {
            $price = (float) $item['original_item_price'];
            $matchedRule = null;

            // Find matching rule
            foreach ($rules as $rule) {
                $min = (float) $rule['min_price'];
                $max = $rule['max_price'] !== null ? (float) $rule['max_price'] : null;

                if ($price >= $min && ($max === null || $price <= $max)) {
                    $matchedRule = $rule;
                    break;
                }
            }

            $cost = 0;
            $ruleId = null;
            if ($matchedRule) {
                $calculated = ($matchedRule['pct_rate'] / 100) * $price + (float) $matchedRule['fixed_cost'];
                $cost = max($calculated, (float) $matchedRule['minimum_cost']);
                $cost = round($cost, 2);
                $ruleId = (int) $matchedRule['rule_id'];

                $tierKey = 'rule_' . $ruleId;
                if (!isset($tierCounts[$tierKey])) {
                    $tierCounts[$tierKey] = [
                        'rule_id'    => $ruleId,
                        'min_price'  => $matchedRule['min_price'],
                        'max_price'  => $matchedRule['max_price'],
                        'pct_rate'   => $matchedRule['pct_rate'],
                        'fixed_cost' => $matchedRule['fixed_cost'],
                        'count'      => 0,
                        'total'      => 0,
                    ];
                }
                $tierCounts[$tierKey]['count']++;
                $tierCounts[$tierKey]['total'] += $cost;
            }

            $totalCost += $cost;

            $results[] = [
                'ledger_transaction_id' => $item['ledger_transaction_id'],
                'title'                 => $item['listing_title'],
                'price'                 => $price,
                'cost'                  => $cost,
                'rule_id'               => $ruleId,
            ];
        }

        return [
            'item_count' => count($items),
            'total_cost' => round($totalCost, 2),
            'items'      => $results,
            'tiers'      => array_values($tierCounts),
        ];
    }
}
