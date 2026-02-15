<?php
/**
 * Card Graph — Payout Controller
 */
class PayoutController
{
    /**
     * GET /api/payouts
     */
    public function index(array $params = []): void
    {
        $pdo = cg_db();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
        $sortKey = $_GET['sort'] ?? 'date_initiated';
        $sortDir = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $allowedSorts = ['date_initiated', 'arrival_date', 'amount', 'destination', 'status', 'created_at'];
        if (!in_array($sortKey, $allowedSorts, true)) {
            $sortKey = 'date_initiated';
        }

        $where = [];
        $bind = [];

        if (!empty($_GET['date_from'])) {
            $where[] = 'p.date_initiated >= :date_from';
            $bind[':date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'p.date_initiated <= :date_to';
            $bind[':date_to'] = $_GET['date_to'];
        }
        if (!empty($_GET['status'])) {
            $where[] = 'p.status = :status';
            $bind[':status'] = $_GET['status'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM CG_Payouts p {$whereClause}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetch()['total'];

        // Summary excluding Failed
        $summaryWhere = !empty($where)
            ? 'WHERE ' . implode(' AND ', $where) . " AND p.status != 'Failed'"
            : "WHERE p.status != 'Failed'";
        $summaryStmt = $pdo->prepare("SELECT
            COALESCE(SUM(p.amount), 0) AS total_amount,
            COUNT(*) AS payout_count,
            SUM(CASE WHEN p.status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
            COALESCE(SUM(CASE WHEN p.status = 'Completed' THEN p.amount ELSE 0 END), 0) AS completed_amount,
            SUM(CASE WHEN p.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
            COALESCE(SUM(CASE WHEN p.status = 'In Progress' THEN p.amount ELSE 0 END), 0) AS in_progress_amount
        FROM CG_Payouts p {$summaryWhere}");
        $summaryStmt->execute($bind);
        $summary = $summaryStmt->fetch();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT p.*, u.display_name AS entered_by_name
                FROM CG_Payouts p
                JOIN CG_Users u ON u.user_id = p.entered_by
                {$whereClause}
                ORDER BY p.{$sortKey} {$sortDir}
                LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        jsonResponse([
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'summary'  => [
                'total_amount'      => round((float) $summary['total_amount'], 2),
                'payout_count'      => (int) $summary['payout_count'],
                'completed_count'   => (int) $summary['completed_count'],
                'completed_amount'  => round((float) $summary['completed_amount'], 2),
                'in_progress_count' => (int) $summary['in_progress_count'],
                'in_progress_amount' => round((float) $summary['in_progress_amount'], 2),
            ],
        ]);
    }

    /**
     * POST /api/payouts
     */
    public function store(array $params = []): void
    {
        $userId = Auth::getUserId();
        $body = getJsonBody();

        $amount = $body['amount'] ?? null;
        $destination = trim($body['destination'] ?? '');
        $dateInitiated = $body['date_initiated'] ?? '';
        $arrivalDate = $body['arrival_date'] ?? null;
        $status = $body['status'] ?? 'In Progress';
        $notes = trim($body['notes'] ?? '');

        if ($amount === null || empty($destination) || empty($dateInitiated)) {
            jsonError('Amount, destination, and date_initiated are required', 400);
        }

        $validStatuses = ['In Progress', 'Failed', 'Completed'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'In Progress';
        }

        $stmt = cg_db()->prepare(
            "INSERT INTO CG_Payouts (amount, destination, date_initiated, arrival_date, status, notes, entered_by)
             VALUES (:amount, :dest, :initiated, :arrival, :status, :notes, :user_id)"
        );
        $stmt->execute([
            ':amount'    => (float) $amount,
            ':dest'      => $destination,
            ':initiated' => parseDate($dateInitiated),
            ':arrival'   => parseDate($arrivalDate),
            ':status'    => $status,
            ':notes'     => $notes ?: null,
            ':user_id'   => $userId,
        ]);

        jsonResponse(['payout_id' => (int) cg_db()->lastInsertId(), 'message' => 'Payout created'], 201);
    }

    /**
     * PUT /api/payouts/{id}
     */
    public function update(array $params = []): void
    {
        $payoutId = (int) ($params['id'] ?? 0);
        $body = getJsonBody();

        $stmt = cg_db()->prepare("SELECT * FROM CG_Payouts WHERE payout_id = :id");
        $stmt->execute([':id' => $payoutId]);
        if (!$stmt->fetch()) {
            jsonError('Payout not found', 404);
        }

        $allowed = ['amount', 'destination', 'date_initiated', 'arrival_date', 'status', 'notes'];
        $sets = [];
        $bind = [':id' => $payoutId];

        foreach ($body as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;

            if ($key === 'date_initiated' || $key === 'arrival_date') {
                $value = parseDate($value);
            } elseif ($key === 'amount') {
                $value = (float) $value;
            } elseif ($key === 'status') {
                $validStatuses = ['In Progress', 'Failed', 'Completed'];
                if (!in_array($value, $validStatuses, true)) continue;
            } else {
                $value = trim($value);
            }

            $sets[] = "{$key} = :{$key}";
            $bind[":{$key}"] = $value;
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql = "UPDATE CG_Payouts SET " . implode(', ', $sets) . " WHERE payout_id = :id";
        cg_db()->prepare($sql)->execute($bind);

        jsonResponse(['message' => 'Payout updated']);
    }

    /**
     * DELETE /api/payouts/{id}
     */
    public function destroy(array $params = []): void
    {
        $payoutId = (int) ($params['id'] ?? 0);

        $stmt = cg_db()->prepare("DELETE FROM CG_Payouts WHERE payout_id = :id");
        $stmt->execute([':id' => $payoutId]);

        if ($stmt->rowCount() === 0) {
            jsonError('Payout not found', 404);
        }

        jsonResponse(['message' => 'Payout deleted']);
    }
}
