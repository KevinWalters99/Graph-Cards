<?php
/**
 * Card Graph — Maintenance Controller
 */
class MaintenanceController
{
    /**
     * GET /api/health (no auth)
     */
    public function health(array $params = []): void
    {
        try {
            $pdo = cg_db();
            $pdo->query("SELECT 1");
            jsonResponse([
                'status'    => 'ok',
                'database'  => 'connected',
                'timestamp' => date('Y-m-d H:i:s'),
                'timezone'  => date_default_timezone_get(),
            ]);
        } catch (Exception $e) {
            jsonResponse([
                'status'   => 'error',
                'database' => 'disconnected',
                'message'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/maintenance/upload-log
     */
    public function uploadLog(array $params = []): void
    {
        Auth::requireAdmin();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));

        $result = UploadLog::getAll($page, $perPage);
        jsonResponse($result);
    }

    /**
     * GET /api/maintenance/table-structures
     * Returns all CG_ tables with column info and row counts.
     */
    public function tableStructures(array $params = []): void
    {
        Auth::requireAdmin();

        try {
            $pdo = cg_db();
            $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();

            // Table descriptions and feature mappings — all 36 CG_ tables
            $meta = [
                // Core tables (001_create_tables.sql)
                'CG_Users'                   => ['desc' => 'User accounts and authentication', 'feature' => 'Maintenance > Users'],
                'CG_Sessions'                => ['desc' => 'Database-backed session management', 'feature' => 'Auth (system)'],
                'CG_UploadLog'               => ['desc' => 'Audit trail for all CSV file uploads', 'feature' => 'Maintenance > Upload History'],
                'CG_EarningsStatements'      => ['desc' => 'Weekly earnings statement headers from CSV imports', 'feature' => 'Items & Costs'],
                'CG_Livestreams'             => ['desc' => 'Normalized livestream lookup (id, title, date)', 'feature' => 'Items & Costs, Dashboard'],
                'CG_Buyers'                  => ['desc' => 'Normalized buyer lookup (username, display name)', 'feature' => 'Top Buyers, Items & Costs'],
                'CG_StatusTypes'             => ['desc' => 'Lookup table for order status types', 'feature' => 'Maintenance > Status Types'],
                'CG_AuctionLineItems'        => ['desc' => 'Core transaction data — one row per earnings CSV line', 'feature' => 'Items & Costs, Dashboard, Analytics'],
                'CG_ItemCosts'               => ['desc' => 'Per-item cost-basis entries linked to auction items', 'feature' => 'Items & Costs, Financial Summary'],
                'CG_StatusHistory'           => ['desc' => 'Full audit trail of order status changes', 'feature' => 'Items & Costs'],
                'CG_Payouts'                 => ['desc' => 'Platform payout/withdrawal records', 'feature' => 'Payouts, Financial Summary'],
                'CG_GeneralCosts'            => ['desc' => 'General business costs (supplies, equipment, etc.)', 'feature' => 'Financial Summary > General Costs'],
                // Cost Matrix (002)
                'CG_CostMatrixRules'         => ['desc' => 'Rules for automated cost assignment by livestream', 'feature' => 'Maintenance > Cost Matrix'],
                // Analytics (003-004)
                'CG_AnalyticsMetrics'        => ['desc' => 'Metric definitions for analytics tracking', 'feature' => 'Analytics, Maintenance > Analytics Standards'],
                'CG_AnalyticsMilestones'     => ['desc' => 'Goal targets per metric and time window', 'feature' => 'Analytics'],
                // PayPal (005)
                'CG_PayPalTransactions'      => ['desc' => 'PayPal transaction records from CSV import', 'feature' => 'PayPal'],
                'CG_PayPalAllocations'       => ['desc' => 'PayPal cost assignments to sales sources', 'feature' => 'PayPal > Assignment'],
                // Alerts (006)
                'CG_AlertDefinitions'        => ['desc' => 'User-configured alert and notification rules', 'feature' => 'Maintenance > Alerts'],
                'CG_AlertDismissals'         => ['desc' => 'Tracks which alerts each user has dismissed', 'feature' => 'Alerts (system)'],
                'CG_ScrollSettings'          => ['desc' => 'Scroll ticker display configuration', 'feature' => 'Maintenance > Alerts'],
                // Transcription (007-009)
                'CG_TranscriptionSettings'   => ['desc' => 'Global recording/transcription config (model, retention)', 'feature' => 'Maintenance > Transcription'],
                'CG_TranscriptionSessions'   => ['desc' => 'Auction recording/transcription job records', 'feature' => 'Maintenance > Transcription'],
                'CG_TranscriptionSegments'   => ['desc' => 'Audio segments within transcription sessions', 'feature' => 'Maintenance > Transcription'],
                'CG_TranscriptionLogs'       => ['desc' => 'Per-session event and error logs', 'feature' => 'Maintenance > Transcription'],
                // Parser Support (010-013)
                'CG_Players'                 => ['desc' => 'Player registry (MLB, prospects, legends) for card parsing', 'feature' => 'Maintenance > Parser'],
                'CG_PlayerNicknames'         => ['desc' => 'Alternate names/nicknames for player lookup', 'feature' => 'Maintenance > Parser'],
                'CG_PlayerStatistics'        => ['desc' => 'Player stats stored as JSON per season', 'feature' => 'Maintenance > Parser'],
                'CG_Teams'                   => ['desc' => 'Baseball team registry for card parsing', 'feature' => 'Maintenance > Parser'],
                'CG_TeamAliases'             => ['desc' => 'Alternate names/abbreviations for teams', 'feature' => 'Maintenance > Parser'],
                'CG_TeamStatistics'          => ['desc' => 'Team stats stored as JSON per season', 'feature' => 'Maintenance > Parser'],
                'CG_CardMakers'              => ['desc' => 'Card manufacturers (Topps, Bowman, Panini, etc.)', 'feature' => 'Maintenance > Parser'],
                'CG_CardStyles'              => ['desc' => 'Card product lines and variants', 'feature' => 'Maintenance > Parser'],
                'CG_CardSpecialties'         => ['desc' => 'Special card types (Autograph, Relic, Rookie, etc.)', 'feature' => 'Maintenance > Parser'],
                'CG_DataRefreshLog'          => ['desc' => 'Tracks data refresh runs (rosters, standings, etc.)', 'feature' => 'Maintenance > Parser'],
                // Table Transcriptions (014)
                'CG_TranscriptionParseRuns'  => ['desc' => 'Parse run history for transcript-to-card extraction', 'feature' => 'Maintenance > Transcription'],
                'CG_TranscriptionRecords'    => ['desc' => 'Extracted card records from parsed transcriptions', 'feature' => 'Maintenance > Transcription'],
                // eBay (Email Transactions)
                'CG_EbayOrders'              => ['desc' => 'eBay order records imported from email', 'feature' => 'Email Transactions'],
                // Tax Prep (015)
                'CG_TaxRecords'              => ['desc' => 'Locked-in tax preparation records by period', 'feature' => 'Financial Summary > Tax Prep'],
            ];

            // Get tables
            $stmt = $pdo->prepare(
                "SELECT TABLE_NAME, TABLE_ROWS
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE 'CG_%'
                 ORDER BY TABLE_NAME"
            );
            $stmt->execute([':db' => $dbName]);
            $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get columns for all CG_ tables
            $colStmt = $pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE 'CG_%'
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );
            $colStmt->execute([':db' => $dbName]);
            $allColumns = $colStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Group columns by table
            $columnsByTable = [];
            foreach ($allColumns as $col) {
                $columnsByTable[$col['TABLE_NAME']][] = [
                    'name'     => $col['COLUMN_NAME'],
                    'type'     => $col['COLUMN_TYPE'],
                    'nullable' => $col['IS_NULLABLE'] === 'YES',
                    'key'      => $col['COLUMN_KEY'],
                    'default'  => $col['COLUMN_DEFAULT'],
                    'extra'    => $col['EXTRA'],
                ];
            }

            // Build result
            $result = [];
            foreach ($tables as $tbl) {
                $name = $tbl['TABLE_NAME'];
                $info = $meta[$name] ?? ['desc' => '', 'feature' => ''];
                $result[] = [
                    'table_name'  => $name,
                    'row_count'   => (int) $tbl['TABLE_ROWS'],
                    'description' => $info['desc'],
                    'feature'     => $info['feature'],
                    'columns'     => $columnsByTable[$name] ?? [],
                ];
            }

            jsonResponse(['tables' => $result]);
        } catch (\Throwable $e) {
            jsonError('Table structures error: ' . $e->getMessage(), 500);
        }
    }
}
