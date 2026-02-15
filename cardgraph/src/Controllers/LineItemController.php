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
        $sortKey = $_GET['sort'] ?? 'transaction_completed_at';
        $sortDir = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Whitelist sort columns
        $allowedSorts = [
            'transaction_completed_at', 'order_placed_at', 'listing_title',
            'transaction_amount', 'original_item_price', 'buyer_paid',
            'buy_format', 'order_id', 'ledger_transaction_id',
        ];
        if (!in_array($sortKey, $allowedSorts, true)) {
            $sortKey = 'transaction_completed_at';
        }

        // Build WHERE conditions
        $where = [];
        $bind = [];

        if (!empty($_GET['date_from'])) {
            $where[] = 'a.transaction_completed_at >= :date_from';
            $bind[':date_from'] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'a.transaction_completed_at <= :date_to';
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

        // Count
        $countSql = "SELECT COUNT(*) as total FROM CG_AuctionLineItems a
                     LEFT JOIN CG_Buyers b ON b.buyer_id = a.buyer_id
                     {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetch()['total'];

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
        ORDER BY a.{$sortKey} {$sortDir}
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
}
