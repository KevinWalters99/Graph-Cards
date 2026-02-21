<?php
/**
 * Card Graph — Alert & Notification Controller
 *
 * CRUD for alert definitions, trigger logic for active alerts,
 * dismissal tracking, and scroll ticker settings/data.
 */
class AlertController
{
    // ─── CRUD (admin only) ───────────────────────────────────────

    /**
     * GET /api/alerts — List all alert definitions.
     */
    public function listAlerts(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $stmt = $pdo->query(
            "SELECT a.*, u.display_name AS created_by_name
             FROM CG_AlertDefinitions a
             LEFT JOIN CG_Users u ON u.user_id = a.created_by
             ORDER BY a.alert_id DESC"
        );

        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/alerts — Create a new alert definition.
     */
    public function createAlert(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $userId = Auth::getUserId();

        $required = ['title', 'description', 'alert_type', 'frequency', 'time_of_day'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                jsonError("Missing required field: {$field}", 400);
            }
        }

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_AlertDefinitions
                (title, description, alert_type, frequency, day_of_week, time_of_day,
                 anchor_date, action_check, is_active, created_by)
             VALUES
                (:title, :description, :alert_type, :frequency, :day_of_week, :time_of_day,
                 :anchor_date, :action_check, :is_active, :created_by)"
        );

        $stmt->execute([
            ':title'        => trim($body['title']),
            ':description'  => trim($body['description']),
            ':alert_type'   => $body['alert_type'],
            ':frequency'    => $body['frequency'],
            ':day_of_week'  => $body['day_of_week'] ?? null,
            ':time_of_day'  => $body['time_of_day'],
            ':anchor_date'  => !empty($body['anchor_date']) ? $body['anchor_date'] : null,
            ':action_check' => !empty($body['action_check']) ? $body['action_check'] : null,
            ':is_active'    => isset($body['is_active']) ? (int) $body['is_active'] : 1,
            ':created_by'   => $userId,
        ]);

        jsonResponse(['alert_id' => (int) $pdo->lastInsertId()], 201);
    }

    /**
     * PUT /api/alerts/{id} — Update an alert definition.
     */
    public function updateAlert(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $body = getJsonBody();

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "UPDATE CG_AlertDefinitions SET
                title = :title,
                description = :description,
                alert_type = :alert_type,
                frequency = :frequency,
                day_of_week = :day_of_week,
                time_of_day = :time_of_day,
                anchor_date = :anchor_date,
                action_check = :action_check,
                is_active = :is_active
             WHERE alert_id = :id"
        );

        $stmt->execute([
            ':title'        => trim($body['title']),
            ':description'  => trim($body['description']),
            ':alert_type'   => $body['alert_type'],
            ':frequency'    => $body['frequency'],
            ':day_of_week'  => $body['day_of_week'] ?? null,
            ':time_of_day'  => $body['time_of_day'],
            ':anchor_date'  => !empty($body['anchor_date']) ? $body['anchor_date'] : null,
            ':action_check' => !empty($body['action_check']) ? $body['action_check'] : null,
            ':is_active'    => isset($body['is_active']) ? (int) $body['is_active'] : 1,
            ':id'           => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonError('Alert not found', 404);
        }

        jsonResponse(['success' => true]);
    }

    /**
     * DELETE /api/alerts/{id} — Delete an alert definition.
     */
    public function deleteAlert(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("DELETE FROM CG_AlertDefinitions WHERE alert_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Alert not found', 404);
        }

        jsonResponse(['success' => true]);
    }

    /**
     * PUT /api/alerts/{id}/toggle — Toggle active status.
     */
    public function toggleAlert(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "UPDATE CG_AlertDefinitions SET is_active = NOT is_active WHERE alert_id = :id"
        );
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Alert not found', 404);
        }

        // Return new state
        $check = $pdo->prepare("SELECT is_active FROM CG_AlertDefinitions WHERE alert_id = :id");
        $check->execute([':id' => $id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        jsonResponse(['is_active' => (int) $row['is_active']]);
    }

    // ─── Active Alerts (all users) ──────────────────────────────

    /**
     * GET /api/alerts/active — Get currently triggered alerts for the logged-in user.
     */
    public function getActiveAlerts(array $params = []): void
    {
        $userId = Auth::getUserId();
        $pdo = cg_db();

        $stmt = $pdo->query(
            "SELECT * FROM CG_AlertDefinitions WHERE is_active = 1"
        );
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = new DateTime('now', new DateTimeZone('America/Chicago'));
        $activeAlerts = [];

        foreach ($definitions as $def) {
            $trigger = $this->checkTrigger($def, $now, $pdo, $userId);
            if ($trigger !== null) {
                $activeAlerts[] = $trigger;
            }
        }

        jsonResponse(['data' => $activeAlerts]);
    }

    /**
     * POST /api/alerts/{id}/dismiss — Dismiss a notification for current period.
     */
    public function dismissAlert(array $params = []): void
    {
        $userId = Auth::getUserId();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        // Get the alert definition to compute period key
        $stmt = $pdo->prepare("SELECT * FROM CG_AlertDefinitions WHERE alert_id = :id");
        $stmt->execute([':id' => $id]);
        $def = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$def) {
            jsonError('Alert not found', 404);
        }

        $now = new DateTime('now', new DateTimeZone('America/Chicago'));
        $periodKey = $this->computePeriodKey($def, $now);

        // Insert dismissal (IGNORE handles duplicate)
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO CG_AlertDismissals (alert_id, user_id, period_key)
             VALUES (:alert_id, :user_id, :period_key)"
        );
        $ins->execute([
            ':alert_id'   => $id,
            ':user_id'    => $userId,
            ':period_key' => $periodKey,
        ]);

        jsonResponse(['success' => true]);
    }

    // ─── Scroll Ticker ──────────────────────────────────────────

    /**
     * GET /api/alerts/scroll — Get scroll ticker settings.
     */
    public function getScrollSettings(array $params = []): void
    {
        Auth::getUserId(); // require auth
        $pdo = cg_db();

        $row = $pdo->query("SELECT * FROM CG_ScrollSettings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        jsonResponse($row ?: []);
    }

    /**
     * PUT /api/alerts/scroll — Update scroll ticker settings.
     */
    public function updateScrollSettings(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $userId = Auth::getUserId();
        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "UPDATE CG_ScrollSettings SET
                is_enabled = :is_enabled,
                show_scorecard = :show_scorecard,
                show_analytics = :show_analytics,
                show_players = :show_players,
                show_teams = :show_teams,
                scroll_speed = :scroll_speed,
                updated_by = :updated_by
             WHERE setting_id = 1"
        );

        $stmt->execute([
            ':is_enabled'     => (int) ($body['is_enabled'] ?? 0),
            ':show_scorecard' => (int) ($body['show_scorecard'] ?? 1),
            ':show_analytics' => (int) ($body['show_analytics'] ?? 1),
            ':show_players'   => (int) ($body['show_players'] ?? 0),
            ':show_teams'     => (int) ($body['show_teams'] ?? 0),
            ':scroll_speed'   => $body['scroll_speed'] ?? 'medium',
            ':updated_by'     => $userId,
        ]);

        jsonResponse(['success' => true]);
    }

    /**
     * GET /api/alerts/scroll/data — Get current scroll ticker content.
     */
    public function getScrollData(array $params = []): void
    {
        Auth::getUserId(); // require auth
        $pdo = cg_db();

        $settings = $pdo->query("SELECT * FROM CG_ScrollSettings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$settings || !$settings['is_enabled']) {
            jsonResponse(['enabled' => false, 'items' => []]);
            return;
        }

        $items = [];

        if ($settings['show_scorecard']) {
            $items = array_merge($items, $this->getScrollScorecardData($pdo));
        }

        if ($settings['show_analytics']) {
            $items = array_merge($items, $this->getScrollAnalyticsData($pdo));
        }

        if ($settings['show_players']) {
            $items[] = ['type' => 'players', 'label' => 'Player Stats', 'value' => 'Coming soon', 'available' => false];
        }

        if ($settings['show_teams']) {
            $items[] = ['type' => 'teams', 'label' => 'Teams Status', 'value' => 'Coming soon', 'available' => false];
        }

        jsonResponse([
            'enabled' => true,
            'speed'   => $settings['scroll_speed'],
            'items'   => $items,
        ]);
    }

    // ─── Private Helpers ─────────────────────────────────────────

    /**
     * Check if an alert definition is currently triggered.
     * Returns the alert data with display info, or null if not triggered.
     */
    private function checkTrigger(array $def, DateTime $now, PDO $pdo, int $userId): ?array
    {
        $periodKey = $this->computePeriodKey($def, $now);
        if ($periodKey === null) {
            return null; // biweekly skip week
        }

        $periodStart = $this->computePeriodStart($def, $now);
        if ($periodStart === null || $now < $periodStart) {
            return null; // not yet due
        }

        // For alerts with action_check — see if the action has been satisfied
        if ($def['alert_type'] === 'alert' && !empty($def['action_check'])) {
            if ($this->isActionSatisfied($def['action_check'], $periodStart, $pdo)) {
                return null; // action completed, don't show
            }
        }

        // For notifications (or alerts without action_check) — check dismissal
        if ($def['alert_type'] === 'notification' || empty($def['action_check'])) {
            $check = $pdo->prepare(
                "SELECT 1 FROM CG_AlertDismissals
                 WHERE alert_id = :alert_id AND user_id = :user_id AND period_key = :period_key"
            );
            $check->execute([
                ':alert_id'   => $def['alert_id'],
                ':user_id'    => $userId,
                ':period_key' => $periodKey,
            ]);
            if ($check->fetch()) {
                return null; // already dismissed
            }
        }

        return [
            'alert_id'     => (int) $def['alert_id'],
            'title'        => $def['title'],
            'description'  => $def['description'],
            'alert_type'   => $def['alert_type'],
            'frequency'    => $def['frequency'],
            'action_check' => $def['action_check'],
            'period_key'   => $periodKey,
        ];
    }

    /**
     * Compute the period key for an alert definition.
     * Returns null for biweekly alerts on "off" weeks.
     */
    private function computePeriodKey(array $def, DateTime $now): ?string
    {
        $freq = $def['frequency'];

        if ($freq === 'weekly') {
            return $now->format('o') . '-W' . $now->format('W');
        }

        if ($freq === 'biweekly') {
            $anchor = $def['anchor_date']
                ? new DateTime($def['anchor_date'], new DateTimeZone('America/Chicago'))
                : new DateTime('2026-01-05', new DateTimeZone('America/Chicago')); // default: first Monday of 2026
            $diff = $anchor->diff($now);
            $weeksDiff = (int) floor($diff->days / 7);
            if ($weeksDiff % 2 !== 0) {
                return null; // off week
            }
            return $now->format('o') . '-W' . $now->format('W');
        }

        if ($freq === 'monthly') {
            return $now->format('Y-m');
        }

        return null;
    }

    /**
     * Compute the start datetime for the current alert period.
     */
    private function computePeriodStart(array $def, DateTime $now): ?DateTime
    {
        $freq = $def['frequency'];
        $tz = new DateTimeZone('America/Chicago');
        $time = $def['time_of_day'] ?: '14:00:00';

        if ($freq === 'weekly' || $freq === 'biweekly') {
            $dow = (int) ($def['day_of_week'] ?? 1); // default Monday
            // PHP: 0=Sun, 1=Mon ... 6=Sat
            $currentDow = (int) $now->format('w');
            $dayDiff = $currentDow - $dow;
            if ($dayDiff < 0) $dayDiff += 7;

            $start = clone $now;
            $start->modify("-{$dayDiff} days");
            $start->setTime(
                (int) substr($time, 0, 2),
                (int) substr($time, 3, 2),
                (int) substr($time, 6, 2)
            );
            return $start;
        }

        if ($freq === 'monthly') {
            $start = new DateTime($now->format('Y-m-01'), $tz);
            $start->setTime(
                (int) substr($time, 0, 2),
                (int) substr($time, 3, 2),
                (int) substr($time, 6, 2)
            );
            return $start;
        }

        return null;
    }

    /**
     * Check if an action has been satisfied since the period start.
     */
    private function isActionSatisfied(string $actionCheck, DateTime $periodStart, PDO $pdo): bool
    {
        $typeMap = [
            'upload_earnings' => 'earnings',
            'upload_payouts'  => 'payouts',
            'upload_paypal'   => 'paypal',
        ];

        $uploadType = $typeMap[$actionCheck] ?? null;
        if ($uploadType === null) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM CG_UploadLog
             WHERE upload_type = :type
               AND status = 'completed'
               AND uploaded_at >= :since
             LIMIT 1"
        );
        $stmt->execute([
            ':type'  => $uploadType,
            ':since' => $periodStart->format('Y-m-d H:i:s'),
        ]);

        return (bool) $stmt->fetch();
    }

    /**
     * Scorecard data for the scroll ticker.
     */
    private function getScrollScorecardData(PDO $pdo): array
    {
        // Current month scorecard
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS items_sold,
                COALESCE(SUM(CASE WHEN buy_format = 'AUCTION' THEN original_item_price ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(commission_fee + payment_processing_fee + tax_on_commission_fee
                    + tax_on_payment_processing_fee + shipping_fee), 0) AS total_fees,
                COUNT(DISTINCT buyer_id) AS unique_buyers
             FROM CG_AuctionLineItems
             WHERE order_placed_at BETWEEN :start AND :end
               AND transaction_type IN ('ORDER_EARNINGS','SHIPPING_CHARGE','TIP')"
        );
        $stmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Item costs for profit
        $costStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(c.cost_amount), 0) AS item_costs
             FROM CG_ItemCosts c
             JOIN CG_AuctionLineItems a ON a.line_item_id = c.line_item_id
             WHERE a.order_placed_at BETWEEN :start AND :end"
        );
        $costStmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
        $costRow = $costStmt->fetch(PDO::FETCH_ASSOC);

        $totalSales = (float) $row['total_sales'];
        $totalFees = (float) $row['total_fees'];
        $itemCosts = (float) $costRow['item_costs'];
        $profit = $totalSales - $totalFees - $itemCosts;
        $profitPct = $totalSales > 0 ? round($profit / $totalSales * 100, 1) : 0;

        $monthName = date('F');
        $items = [];
        $items[] = ['type' => 'scorecard', 'label' => "{$monthName} Sales", 'value' => '$' . number_format($totalSales, 2)];
        $items[] = ['type' => 'scorecard', 'label' => "{$monthName} Items Sold", 'value' => number_format((int) $row['items_sold'])];
        $items[] = ['type' => 'scorecard', 'label' => "{$monthName} Profit", 'value' => '$' . number_format($profit, 2)];
        $items[] = ['type' => 'scorecard', 'label' => "{$monthName} Profit %", 'value' => $profitPct . '%'];
        $items[] = ['type' => 'scorecard', 'label' => "{$monthName} Buyers", 'value' => number_format((int) $row['unique_buyers'])];

        return $items;
    }

    /**
     * Analytics data for the scroll ticker.
     */
    private function getScrollAnalyticsData(PDO $pdo): array
    {
        $items = [];

        // Active milestones with pacing
        $stmt = $pdo->query(
            "SELECT m.milestone_name, m.target_value, m.window_start, m.window_end,
                    am.metric_key, am.unit_type
             FROM CG_AnalyticsMilestones m
             JOIN CG_AnalyticsMetrics am ON am.metric_id = m.metric_id
             WHERE m.is_active = 1
               AND m.window_end >= CURDATE()
             ORDER BY m.window_end ASC
             LIMIT 5"
        );
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($milestones as $ms) {
            $target = (float) $ms['target_value'];
            $unit = $ms['unit_type'] === '$' ? '$' : '';
            $suffix = $ms['unit_type'] === '%' ? '%' : '';

            $items[] = [
                'type'  => 'analytics',
                'label' => $ms['milestone_name'] . ' Target',
                'value' => $unit . number_format($target, $ms['unit_type'] === '$' ? 2 : 0) . $suffix,
            ];
        }

        return $items;
    }
}
