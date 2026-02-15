<?php
/**
 * Card Graph — Status Controller
 */
class StatusController
{
    /**
     * GET /api/statuses
     */
    public function index(array $params = []): void
    {
        $stmt = cg_db()->query(
            "SELECT status_type_id, status_name, display_order, is_active
             FROM CG_StatusTypes ORDER BY display_order"
        );
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * PUT /api/line-items/{id}/status
     * Body: { status_id, reason }
     */
    public function update(array $params = []): void
    {
        $userId = Auth::getUserId();
        $ledgerId = $params['id'] ?? '';
        $body = getJsonBody();

        $newStatusId = (int) ($body['status_id'] ?? 0);
        $reason = trim($body['reason'] ?? '');

        if (empty($ledgerId) || $newStatusId === 0) {
            jsonError('Line item ID and status_id are required', 400);
        }

        $pdo = cg_db();

        // Get current status
        $stmt = $pdo->prepare(
            "SELECT current_status_id FROM CG_AuctionLineItems WHERE ledger_transaction_id = :id"
        );
        $stmt->execute([':id' => $ledgerId]);
        $item = $stmt->fetch();

        if (!$item) {
            jsonError('Line item not found', 404);
        }

        $oldStatusId = (int) $item['current_status_id'];

        if ($oldStatusId === $newStatusId) {
            jsonError('Status is already set to this value', 400);
        }

        // Verify new status exists
        $statusCheck = $pdo->prepare("SELECT 1 FROM CG_StatusTypes WHERE status_type_id = :id");
        $statusCheck->execute([':id' => $newStatusId]);
        if (!$statusCheck->fetch()) {
            jsonError('Invalid status type', 400);
        }

        // Update line item
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE CG_AuctionLineItems SET current_status_id = :status WHERE ledger_transaction_id = :id"
            )->execute([':status' => $newStatusId, ':id' => $ledgerId]);

            // Record history
            $pdo->prepare(
                "INSERT INTO CG_StatusHistory (ledger_transaction_id, old_status_id, new_status_id, changed_by, change_reason)
                 VALUES (:id, :old, :new, :user, :reason)"
            )->execute([
                ':id'     => $ledgerId,
                ':old'    => $oldStatusId,
                ':new'    => $newStatusId,
                ':user'   => $userId,
                ':reason' => $reason ?: null,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Failed to update status: ' . $e->getMessage(), 500);
        }

        jsonResponse(['message' => 'Status updated']);
    }
}
