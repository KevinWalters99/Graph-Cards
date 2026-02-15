<?php
/**
 * Card Graph — Cost Controller
 */
class CostController
{
    /**
     * GET /api/costs?ledger_transaction_id=X
     */
    public function index(array $params = []): void
    {
        $ledgerId = $_GET['ledger_transaction_id'] ?? '';
        if (empty($ledgerId)) {
            jsonError('ledger_transaction_id is required', 400);
        }

        $stmt = cg_db()->prepare(
            "SELECT c.*, u.display_name AS entered_by_name
             FROM CG_ItemCosts c
             JOIN CG_Users u ON u.user_id = c.entered_by
             WHERE c.ledger_transaction_id = :id
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([':id' => $ledgerId]);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * POST /api/costs
     * Body: { ledger_transaction_id, cost_amount, cost_description }
     */
    public function store(array $params = []): void
    {
        $userId = Auth::getUserId();
        $body = getJsonBody();

        $ledgerId = trim($body['ledger_transaction_id'] ?? '');
        $amount = $body['cost_amount'] ?? null;
        $description = trim($body['cost_description'] ?? '');

        if (empty($ledgerId) || $amount === null) {
            jsonError('ledger_transaction_id and cost_amount are required', 400);
        }

        // Verify line item exists
        $check = cg_db()->prepare("SELECT 1 FROM CG_AuctionLineItems WHERE ledger_transaction_id = :id");
        $check->execute([':id' => $ledgerId]);
        if (!$check->fetch()) {
            jsonError('Line item not found', 404);
        }

        $stmt = cg_db()->prepare(
            "INSERT INTO CG_ItemCosts (ledger_transaction_id, cost_amount, cost_description, entered_by)
             VALUES (:id, :amount, :desc, :user_id)"
        );
        $stmt->execute([
            ':id'      => $ledgerId,
            ':amount'  => (float) $amount,
            ':desc'    => $description ?: null,
            ':user_id' => $userId,
        ]);

        jsonResponse(['cost_id' => (int) cg_db()->lastInsertId(), 'message' => 'Cost added'], 201);
    }

    /**
     * PUT /api/costs/{id}
     */
    public function update(array $params = []): void
    {
        $costId = (int) ($params['id'] ?? 0);
        $body = getJsonBody();

        $stmt = cg_db()->prepare("SELECT * FROM CG_ItemCosts WHERE cost_id = :id");
        $stmt->execute([':id' => $costId]);
        if (!$stmt->fetch()) {
            jsonError('Cost entry not found', 404);
        }

        $amount = $body['cost_amount'] ?? null;
        $description = $body['cost_description'] ?? null;

        $sets = [];
        $bind = [':id' => $costId];

        if ($amount !== null) {
            $sets[] = 'cost_amount = :amount';
            $bind[':amount'] = (float) $amount;
        }
        if ($description !== null) {
            $sets[] = 'cost_description = :desc';
            $bind[':desc'] = trim($description) ?: null;
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql = "UPDATE CG_ItemCosts SET " . implode(', ', $sets) . " WHERE cost_id = :id";
        cg_db()->prepare($sql)->execute($bind);

        jsonResponse(['message' => 'Cost updated']);
    }

    /**
     * DELETE /api/costs/{id}
     */
    public function destroy(array $params = []): void
    {
        $costId = (int) ($params['id'] ?? 0);

        $stmt = cg_db()->prepare("DELETE FROM CG_ItemCosts WHERE cost_id = :id");
        $stmt->execute([':id' => $costId]);

        if ($stmt->rowCount() === 0) {
            jsonError('Cost entry not found', 404);
        }

        jsonResponse(['message' => 'Cost deleted']);
    }
}
