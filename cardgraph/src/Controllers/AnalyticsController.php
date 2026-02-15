<?php
/**
 * Card Graph — Analytics Controller
 * Trend analysis, forecasting, milestones, and pacing.
 */
class AnalyticsController
{
    // =========================================================
    // Metric Definitions (CRUD)
    // =========================================================

    /**
     * GET /api/analytics/metrics
     */
    public function listMetrics(array $params = []): void
    {
        $data = cg_db()->query(
            "SELECT * FROM CG_AnalyticsMetrics ORDER BY display_order"
        )->fetchAll();
        jsonResponse(['data' => $data]);
    }

    /**
     * PUT /api/analytics/metrics/{id}
     */
    public function updateMetric(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid metric ID', 400);

        $body = getJsonBody();
        $sets = [];
        $bind = [':id' => $id];

        $allowed = ['description', 'method', 'metric_name', 'is_active'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[] = "{$field} = :{$field}";
                $bind[":{$field}"] = $field === 'is_active' ? (int) $body[$field] : trim($body[$field]);
            }
        }

        if (empty($sets)) jsonError('No fields to update', 400);

        $sql = "UPDATE CG_AnalyticsMetrics SET " . implode(', ', $sets) . " WHERE metric_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) jsonError('Metric not found', 404);
        jsonResponse(['message' => 'Metric updated']);
    }

    // =========================================================
    // Milestones (CRUD)
    // =========================================================

    /**
     * GET /api/analytics/milestones
     */
    public function listMilestones(array $params = []): void
    {
        $where = [];
        $bind = [];

        if (!empty($_GET['metric_id'])) {
            $where[] = 'm.metric_id = :metric_id';
            $bind[':metric_id'] = (int) $_GET['metric_id'];
        }
        if (!empty($_GET['time_window'])) {
            $where[] = 'm.time_window = :tw';
            $bind[':tw'] = $_GET['time_window'];
        }
        if (!empty($_GET['active_only'])) {
            $where[] = 'm.is_active = 1';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT m.*, am.metric_key, am.metric_name, am.unit_type,
                       u.display_name AS created_by_name
                FROM CG_AnalyticsMilestones m
                JOIN CG_AnalyticsMetrics am ON am.metric_id = m.metric_id
                JOIN CG_Users u ON u.user_id = m.created_by
                {$whereClause}
                ORDER BY m.window_start DESC, am.display_order";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    /**
     * POST /api/analytics/milestones
     */
    public function createMilestone(array $params = []): void
    {
        $user = Auth::getCurrentUser();
        $body = getJsonBody();

        $metricId = (int) ($body['metric_id'] ?? 0);
        $name = trim($body['milestone_name'] ?? '');
        $target = (float) ($body['target_value'] ?? 0);
        $timeWindow = $body['time_window'] ?? '';
        $windowStart = $body['window_start'] ?? '';
        $windowEnd = $body['window_end'] ?? '';

        if (!$metricId || !$name || $target <= 0 || !$timeWindow || !$windowStart || !$windowEnd) {
            jsonError('All fields are required and target must be > 0', 400);
        }

        $validWindows = ['auction','monthly','quarterly','annually','2-year','3-year','4-year','5-year'];
        if (!in_array($timeWindow, $validWindows, true)) {
            jsonError('Invalid time window', 400);
        }

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_AnalyticsMilestones
             (metric_id, milestone_name, target_value, time_window, window_start, window_end, created_by)
             VALUES (:metric_id, :name, :target, :tw, :ws, :we, :uid)"
        );
        $stmt->execute([
            ':metric_id' => $metricId,
            ':name'      => $name,
            ':target'    => $target,
            ':tw'        => $timeWindow,
            ':ws'        => parseDate($windowStart),
            ':we'        => parseDate($windowEnd),
            ':uid'       => $user['user_id'],
        ]);

        jsonResponse(['message' => 'Milestone created', 'id' => (int) $pdo->lastInsertId()], 201);
    }

    /**
     * PUT /api/analytics/milestones/{id}
     */
    public function updateMilestone(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) jsonError('Invalid milestone ID', 400);

        $body = getJsonBody();
        $sets = [];
        $bind = [':id' => $id];

        $fields = [
            'metric_id'      => 'int',
            'milestone_name' => 'string',
            'target_value'   => 'float',
            'time_window'    => 'string',
            'window_start'   => 'date',
            'window_end'     => 'date',
            'is_active'      => 'int',
        ];

        foreach ($fields as $field => $type) {
            if (!array_key_exists($field, $body)) continue;
            $val = $body[$field];
            if ($type === 'int') $val = (int) $val;
            elseif ($type === 'float') $val = (float) $val;
            elseif ($type === 'date') $val = parseDate($val);
            else $val = trim($val);

            $sets[] = "{$field} = :{$field}";
            $bind[":{$field}"] = $val;
        }

        if (empty($sets)) jsonError('No fields to update', 400);

        $sql = "UPDATE CG_AnalyticsMilestones SET " . implode(', ', $sets) . " WHERE milestone_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) jsonError('Milestone not found', 404);
        jsonResponse(['message' => 'Milestone updated']);
    }

    /**
     * DELETE /api/analytics/milestones/{id}
     */
    public function deleteMilestone(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_AnalyticsMilestones WHERE milestone_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) jsonError('Milestone not found', 404);
        jsonResponse(['message' => 'Milestone deleted']);
    }

    // =========================================================
    // Actuals — Monthly aggregated metrics
    // =========================================================

    /**
     * GET /api/analytics/actuals
     */
    public function getActuals(array $params = []): void
    {
        jsonResponse(['monthly' => $this->fetchMonthlyActuals()]);
    }

    // =========================================================
    // Forecast — Weighted linear regression
    // =========================================================

    /**
     * GET /api/analytics/forecast
     *
     * Regression is computed from COMPLETED months only.
     * The current partial month is returned separately so the
     * frontend can render a "current position" flag without
     * dragging the trend line down.
     */
    public function getForecast(array $params = []): void
    {
        $metricKey = $_GET['metric'] ?? 'total_sales';
        $periodsAhead = max(1, min(24, (int) ($_GET['periods_ahead'] ?? 6)));

        $actuals = $this->fetchMonthlyActuals();

        // Separate completed months from current partial month
        $completedValues = [];
        $completedPeriods = [];
        $partialMonth = null;

        foreach ($actuals as $row) {
            if (!empty($row['is_partial'])) {
                $partialMonth = $row;
            } else {
                $completedPeriods[] = $row['period'];
                $completedValues[] = (float) $row[$metricKey];
            }
        }

        $n = count($completedValues);

        // Build historical array (completed months only)
        $historical = [];
        for ($i = 0; $i < $n; $i++) {
            $historical[] = ['period' => $completedPeriods[$i], 'value' => round($completedValues[$i], 2)];
        }

        // Build current_month info
        $currentMonthInfo = null;
        if ($partialMonth) {
            $actual = (float) $partialMonth[$metricKey];
            $daysElapsed = $partialMonth['days_elapsed'];
            $daysInMonth = $partialMonth['days_in_month'];
            $prorated = ($daysElapsed > 0) ? round($actual * $daysInMonth / $daysElapsed, 2) : $actual;
        }

        // Insufficient data — return early
        if ($n < 3) {
            if ($partialMonth) {
                $currentMonthInfo = [
                    'period'        => $partialMonth['period'],
                    'actual'        => round($actual, 2),
                    'prorated'      => $prorated,
                    'forecast'      => $prorated,
                    'lower'         => round($prorated * 0.7, 2),
                    'upper'         => round($prorated * 1.3, 2),
                    'days_elapsed'  => $daysElapsed,
                    'days_in_month' => $daysInMonth,
                    'pct_elapsed'   => round(($daysElapsed / $daysInMonth) * 100, 1),
                ];
            }
            jsonResponse([
                'metric'        => $metricKey,
                'historical'    => $historical,
                'current_month' => $currentMonthInfo,
                'forecast'      => [],
                'trend'         => ['slope' => 0, 'direction' => 'flat', 'r_squared' => 0],
                'message'       => 'Need at least 3 completed months of data for forecasting.',
            ]);
            return;
        }

        // Weighted linear regression on completed months only
        $regression = $this->weightedLinearRegression($completedValues);

        // Current month: regression-projected full-month value
        if ($partialMonth) {
            $predicted = $regression['intercept'] + $regression['slope'] * $n;
            $predicted = max(0, $predicted);
            if ($metricKey === 'profit_percent') $predicted = min(100, $predicted);
            $interval = $this->predictionInterval($completedValues, $regression, $n);

            $currentMonthInfo = [
                'period'        => $partialMonth['period'],
                'actual'        => round($actual, 2),
                'prorated'      => $prorated,
                'forecast'      => round($predicted, 2),
                'lower'         => round(max(0, $predicted - $interval), 2),
                'upper'         => round($predicted + $interval, 2),
                'days_elapsed'  => $daysElapsed,
                'days_in_month' => $daysInMonth,
                'pct_elapsed'   => round(($daysElapsed / $daysInMonth) * 100, 1),
            ];
        }

        // Build forecast for future months
        $startX = $partialMonth ? $n + 1 : $n;
        $lastPeriod = $partialMonth ? $partialMonth['period'] : end($completedPeriods);
        $forecast = [];

        for ($i = 0; $i < $periodsAhead; $i++) {
            $futureX = $startX + $i;
            $predicted = $regression['intercept'] + $regression['slope'] * $futureX;
            $interval = $this->predictionInterval($completedValues, $regression, $futureX);

            $predicted = max(0, $predicted);
            if ($metricKey === 'profit_percent') {
                $predicted = min(100, $predicted);
            }

            $nextDate = date('Y-m', strtotime($lastPeriod . '-01 +' . ($i + 1) . ' months'));
            $forecast[] = [
                'period' => $nextDate,
                'value'  => round($predicted, 2),
                'lower'  => round(max(0, $predicted - $interval), 2),
                'upper'  => round($predicted + $interval, 2),
            ];
        }

        $direction = $regression['slope'] > 0.5 ? 'up' : ($regression['slope'] < -0.5 ? 'down' : 'flat');

        jsonResponse([
            'metric'        => $metricKey,
            'historical'    => $historical,
            'current_month' => $currentMonthInfo,
            'forecast'      => $forecast,
            'trend'         => [
                'slope'     => round($regression['slope'], 4),
                'direction' => $direction,
                'r_squared' => round($regression['r_squared'], 4),
            ],
        ]);
    }

    // =========================================================
    // Pacing — Milestone progress tracking
    // =========================================================

    /**
     * GET /api/analytics/pacing
     */
    public function getPacing(array $params = []): void
    {
        $pdo = cg_db();

        $where = ['m.is_active = 1'];
        $bind = [];
        if (!empty($_GET['metric_id'])) {
            $where[] = 'm.metric_id = :metric_id';
            $bind[':metric_id'] = (int) $_GET['metric_id'];
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT m.*, am.metric_key, am.metric_name, am.unit_type
                FROM CG_AnalyticsMilestones m
                JOIN CG_AnalyticsMetrics am ON am.metric_id = m.metric_id
                {$whereClause}
                ORDER BY m.window_end ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $milestones = $stmt->fetchAll();

        if (empty($milestones)) {
            jsonResponse(['milestones' => []]);
            return;
        }

        // Get all actuals for computing per-window values
        $allActuals = $this->fetchMonthlyActuals();
        $regression = $this->buildRegressions($allActuals);

        $today = date('Y-m-d');
        $results = [];

        foreach ($milestones as $ms) {
            $metricKey = $ms['metric_key'];
            $windowStart = $ms['window_start'];
            $windowEnd = $ms['window_end'];
            $target = (float) $ms['target_value'];

            // Sum actuals within window
            $actual = $this->sumActualsInWindow($allActuals, $metricKey, $windowStart, $windowEnd, $today);

            // Time elapsed
            $totalDays = max(1, (strtotime($windowEnd) - strtotime($windowStart)) / 86400);
            $elapsedDays = max(0, (strtotime(min($today, $windowEnd)) - strtotime($windowStart)) / 86400);
            $pctTimeElapsed = round(($elapsedDays / $totalDays) * 100, 1);
            $daysRemaining = max(0, (int) ceil((strtotime($windowEnd) - strtotime($today)) / 86400));

            // Forecast end value using regression
            $forecastEnd = $this->forecastWindowEnd($regression, $metricKey, $allActuals, $windowStart, $windowEnd, $actual);

            // Pacing status
            $pctComplete = $target > 0 ? round(($actual / $target) * 100, 1) : 0;
            if ($actual >= $target * 1.1) {
                $status = 'exceeded';
            } elseif ($actual >= $target) {
                $status = 'achieved';
            } elseif ($forecastEnd >= $target) {
                $status = 'on_track';
            } elseif ($forecastEnd >= $target * 0.9) {
                $status = 'at_risk';
            } else {
                $status = 'behind';
            }

            $results[] = [
                'milestone_id'         => (int) $ms['milestone_id'],
                'milestone_name'       => $ms['milestone_name'],
                'metric_key'           => $metricKey,
                'metric_name'          => $ms['metric_name'],
                'unit_type'            => $ms['unit_type'],
                'time_window'          => $ms['time_window'],
                'target_value'         => $target,
                'actual_value'         => round($actual, 2),
                'percent_complete'     => $pctComplete,
                'percent_time_elapsed' => $pctTimeElapsed,
                'forecasted_end_value' => round($forecastEnd, 2),
                'pacing_status'        => $status,
                'days_remaining'       => $daysRemaining,
                'window_start'         => $windowStart,
                'window_end'           => $windowEnd,
            ];
        }

        jsonResponse(['milestones' => $results]);
    }

    // =========================================================
    // Private Helpers
    // =========================================================

    /**
     * Fetch monthly actuals for all 6 metrics.
     */
    private function fetchMonthlyActuals(): array
    {
        $pdo = cg_db();

        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $conditions = '';
        $bind = [];
        if ($dateFrom) {
            $conditions .= ' AND a.order_placed_at >= :date_from';
            $bind[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $conditions .= ' AND a.order_placed_at <= :date_to';
            $bind[':date_to'] = $dateTo . ' 23:59:59';
        }

        // Excluded statuses
        $excludedStatuses = $pdo->query(
            "SELECT status_type_id FROM CG_StatusTypes
             WHERE status_name IN ('Cancelled','Refused','Did Not Pay','Returned','Disputed')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $excludeList = implode(',', array_map('intval', $excludedStatuses));
        $statusFilter = $excludeList ? "AND a.current_status_id NOT IN ({$excludeList})" : '';

        $sql = "SELECT
            DATE_FORMAT(a.order_placed_at, '%Y-%m') AS period,
            COALESCE(SUM(CASE WHEN a.buy_format = 'AUCTION' THEN a.original_item_price ELSE 0 END), 0) AS total_sales,
            SUM(CASE WHEN a.buy_format = 'AUCTION' THEN 1 ELSE 0 END) AS items_sold,
            COUNT(DISTINCT a.buyer_id) AS unique_buyers,
            COUNT(DISTINCT a.shipment_id) AS shipments,
            COALESCE(SUM(a.transaction_amount), 0) AS earnings,
            COALESCE(SUM(
                COALESCE(a.commission_fee, 0) + COALESCE(a.payment_processing_fee, 0) +
                COALESCE(a.tax_on_commission_fee, 0) + COALESCE(a.tax_on_payment_processing_fee, 0) +
                COALESCE(a.shipping_fee, 0)
            ), 0) AS fees
        FROM CG_AuctionLineItems a
        WHERE a.order_placed_at IS NOT NULL
          {$statusFilter} {$conditions}
        GROUP BY period
        ORDER BY period";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $monthly = $stmt->fetchAll();

        foreach ($monthly as &$row) {
            $periodStart = $row['period'] . '-01';
            $periodEnd = date('Y-m-t', strtotime($periodStart));

            // Item costs
            $cStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(c.cost_amount), 0) AS item_costs
                 FROM CG_ItemCosts c
                 JOIN CG_AuctionLineItems a ON a.ledger_transaction_id = c.ledger_transaction_id
                 WHERE a.order_placed_at BETWEEN :s AND :e {$statusFilter}"
            );
            $cStmt->execute([':s' => $periodStart . ' 00:00:00', ':e' => $periodEnd . ' 23:59:59']);
            $itemCosts = (float) $cStmt->fetch()['item_costs'];

            // General costs
            $gStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(total), 0) AS gen_costs FROM CG_GeneralCosts WHERE cost_date BETWEEN :s AND :e"
            );
            $gStmt->execute([':s' => $periodStart, ':e' => $periodEnd]);
            $genCosts = (float) $gStmt->fetch()['gen_costs'];

            $totalSales = (float) $row['total_sales'];
            $fees = (float) $row['fees'];
            $totalCosts = $itemCosts + $genCosts;
            $profit = $totalSales - $fees - $totalCosts;
            $profitPct = ($totalSales > 0) ? round(($profit / $totalSales) * 100, 2) : 0;

            $row['total_sales']    = round((float) $row['total_sales'], 2);
            $row['items_sold']     = (int) $row['items_sold'];
            $row['unique_buyers']  = (int) $row['unique_buyers'];
            $row['shipments']      = (int) $row['shipments'];
            $row['profit_amount']  = round($profit, 2);
            $row['profit_percent'] = $profitPct;

            unset($row['earnings'], $row['fees']);
        }
        unset($row);

        // Flag current month as partial (incomplete data)
        $currentYm = date('Y-m');
        foreach ($monthly as &$row) {
            $row['is_partial'] = ($row['period'] === $currentYm);
            if ($row['is_partial']) {
                $row['days_elapsed'] = (int) date('j');
                $row['days_in_month'] = (int) date('t');
            }
        }
        unset($row);

        return $monthly;
    }

    /**
     * Weighted linear regression — recent months weighted more heavily.
     */
    private function weightedLinearRegression(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['slope' => 0, 'intercept' => $values[0] ?? 0, 'r_squared' => 0];
        }

        $alpha = 0.9;
        $sumW = 0; $sumWx = 0; $sumWy = 0; $sumWxy = 0; $sumWxx = 0;

        for ($i = 0; $i < $n; $i++) {
            $w = pow($alpha, $n - 1 - $i);
            $x = $i;
            $y = (float) $values[$i];
            $sumW   += $w;
            $sumWx  += $w * $x;
            $sumWy  += $w * $y;
            $sumWxy += $w * $x * $y;
            $sumWxx += $w * $x * $x;
        }

        $denom = $sumW * $sumWxx - $sumWx * $sumWx;
        if (abs($denom) < 1e-10) {
            return ['slope' => 0, 'intercept' => $sumWy / max($sumW, 1e-10), 'r_squared' => 0];
        }

        $slope = ($sumW * $sumWxy - $sumWx * $sumWy) / $denom;
        $intercept = ($sumWy - $slope * $sumWx) / $sumW;

        // R-squared
        $mean = $sumWy / $sumW;
        $ssTot = 0; $ssRes = 0;
        for ($i = 0; $i < $n; $i++) {
            $w = pow($alpha, $n - 1 - $i);
            $predicted = $intercept + $slope * $i;
            $ssRes += $w * pow((float) $values[$i] - $predicted, 2);
            $ssTot += $w * pow((float) $values[$i] - $mean, 2);
        }
        $rSquared = ($ssTot > 0) ? max(0, 1 - ($ssRes / $ssTot)) : 0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r_squared' => $rSquared];
    }

    /**
     * Compute prediction interval for a future x value.
     */
    private function predictionInterval(array $values, array $regression, int $futureX): float
    {
        $n = count($values);
        if ($n < 3) return 0;

        $alpha = 0.9;
        $ssRes = 0;
        $sumW = 0;
        $sumWx = 0;
        $sumWxx = 0;

        for ($i = 0; $i < $n; $i++) {
            $w = pow($alpha, $n - 1 - $i);
            $predicted = $regression['intercept'] + $regression['slope'] * $i;
            $ssRes += $w * pow((float) $values[$i] - $predicted, 2);
            $sumW += $w;
            $sumWx += $w * $i;
            $sumWxx += $w * $i * $i;
        }

        $meanX = $sumWx / $sumW;
        $sumDevX = $sumWxx - $sumWx * $sumWx / $sumW;
        if ($sumDevX <= 0) return 0;

        $stdError = sqrt($ssRes / max($n - 2, 1));
        $confidence = 1.96; // 95%
        return $confidence * $stdError * sqrt(1 + 1 / $n + pow($futureX - $meanX, 2) / $sumDevX);
    }

    /**
     * Build regression models for all 6 metrics.
     */
    private function buildRegressions(array $actuals): array
    {
        $metrics = ['total_sales', 'items_sold', 'unique_buyers', 'shipments', 'profit_amount', 'profit_percent'];
        $regressions = [];
        foreach ($metrics as $key) {
            $values = array_column($actuals, $key);
            $regressions[$key] = $this->weightedLinearRegression($values);
        }
        return $regressions;
    }

    /**
     * Sum actual metric values within a date window.
     */
    private function sumActualsInWindow(array $actuals, string $metricKey, string $start, string $end, string $today): float
    {
        $startMonth = substr($start, 0, 7);
        $endMonth = substr(min($end, $today), 0, 7);
        $sum = 0;

        foreach ($actuals as $row) {
            if ($row['period'] >= $startMonth && $row['period'] <= $endMonth) {
                $sum += (float) $row[$metricKey];
            }
        }
        return $sum;
    }

    /**
     * Forecast total value at window end using regression.
     */
    private function forecastWindowEnd(array $regressions, string $metricKey, array $actuals, string $windowStart, string $windowEnd, float $currentActual): float
    {
        if (empty($actuals) || !isset($regressions[$metricKey])) {
            return $currentActual;
        }

        $reg = $regressions[$metricKey];
        $n = count($actuals);
        $lastPeriod = end($actuals)['period'];
        $today = date('Y-m-d');

        if ($today >= $windowEnd) {
            return $currentActual;
        }

        // Count remaining months in window
        $currentMonth = date('Y-m');
        $endMonth = substr($windowEnd, 0, 7);

        $remaining = 0;
        $monthCursor = $currentMonth;
        while ($monthCursor <= $endMonth) {
            $remaining++;
            $monthCursor = date('Y-m', strtotime($monthCursor . '-01 +1 month'));
        }

        // Use regression slope to estimate remaining months
        $avgMonthlyFromRegression = $reg['intercept'] + $reg['slope'] * ($n + ($remaining / 2));
        $avgMonthlyFromRegression = max(0, $avgMonthlyFromRegression);

        return $currentActual + $avgMonthlyFromRegression * max(0, $remaining - 1);
    }
}
