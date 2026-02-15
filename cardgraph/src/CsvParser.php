<?php
/**
 * Card Graph — Earnings CSV Parser
 *
 * Parses Whatnot earnings CSV files and inserts data into:
 * - CG_EarningsStatements
 * - CG_Livestreams
 * - CG_Buyers
 * - CG_AuctionLineItems
 * - CG_StatusHistory
 */
class CsvParser
{
    // Expected CSV column headers (in order)
    private const EXPECTED_HEADERS = [
        'REPORT_START_DATE', 'WEEK_NUMBER', 'ORDER_PLACED_AT_UTC',
        'TRANSACTION_COMPLETED_AT_UTC', 'SELLER_ID', 'TRANSACTION_TYPE',
        'TRANSACTION_MESSAGE', 'ORDER_ID', 'LISTING_TITLE', 'LISTING_DESCRIPTION',
        'PRODUCT_CATEGORY', 'BUY_FORMAT', 'SALE_TYPE', 'QUANTITY_SOLD',
        'SKU', 'COST_OF_GOODS', 'LIVESTREAM_ID', 'LIVESTREAM_TITLE',
        'BUYER_NAME', 'BUYER_STATE', 'BUYER_COUNTRY', 'SHIPMENT_ID',
        'TRANSACTION_CURRENCY', 'TRANSACTION_AMOUNT', 'BUYER_PAID',
        'ORIGINAL_ITEM_PRICE', 'COUPON_COST', 'POST_COUPON_PRICE',
        'SHIPPING_FEE', 'COMMISSION_FEE', 'PAYMENT_PROCESSING_FEE',
        'TAX_ON_COMMISSION_FEE', 'TAX_ON_PAYMENT_PROCESSING_FEE',
        'LEDGER_TRANSACTION_ID',
    ];

    private PDO $pdo;
    private int $uploadId;
    private int $userId;
    private int $rowsInserted = 0;
    private int $rowsSkipped = 0;
    private int $totalRows = 0;

    // Caches to avoid repeated DB lookups
    private array $buyerCache = [];
    private array $livestreamCache = [];
    private ?int $statementId = null;

    // Status type IDs (loaded once)
    private int $completedStatusId;
    private int $giveawayStatusId;

    public function __construct(PDO $pdo, int $uploadId, int $userId)
    {
        $this->pdo = $pdo;
        $this->uploadId = $uploadId;
        $this->userId = $userId;
        $this->loadStatusIds();
    }

    /**
     * Parse a CSV file and insert all data.
     *
     * @return array Summary stats
     */
    public function parse(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$filePath}");
        }

        try {
            // Validate header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new RuntimeException('CSV file is empty');
            }

            // Strip BOM if present
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
            $headers = array_map('trim', $headers);

            if ($headers !== self::EXPECTED_HEADERS) {
                $missing = array_diff(self::EXPECTED_HEADERS, $headers);
                $extra = array_diff($headers, self::EXPECTED_HEADERS);
                $msg = 'CSV header mismatch.';
                if (!empty($missing)) {
                    $msg .= ' Missing: ' . implode(', ', $missing) . '.';
                }
                if (!empty($extra)) {
                    $msg .= ' Unexpected: ' . implode(', ', $extra) . '.';
                }
                throw new RuntimeException($msg);
            }

            // Process rows within a transaction
            $this->pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count(self::EXPECTED_HEADERS)) {
                    continue; // Skip malformed rows
                }

                $data = array_combine(self::EXPECTED_HEADERS, $row);
                $this->totalRows++;
                $this->processRow($data);
            }

            // Update statement totals
            if ($this->statementId) {
                $this->updateStatementTotals();
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        return [
            'total_rows'    => $this->totalRows,
            'rows_inserted' => $this->rowsInserted,
            'rows_skipped'  => $this->rowsSkipped,
            'statement_id'  => $this->statementId,
        ];
    }

    /**
     * Process a single CSV row.
     */
    private function processRow(array $data): void
    {
        $ledgerId = trim($data['LEDGER_TRANSACTION_ID']);
        if (empty($ledgerId)) {
            $this->rowsSkipped++;
            return;
        }

        // Check for duplicate
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM CG_AuctionLineItems WHERE ledger_transaction_id = :id"
        );
        $stmt->execute([':id' => $ledgerId]);
        if ($stmt->fetch()) {
            $this->rowsSkipped++;
            return;
        }

        // Ensure statement exists (once per file)
        if ($this->statementId === null) {
            $this->statementId = $this->ensureStatement($data);
        }

        // Upsert livestream
        $livestreamId = trim($data['LIVESTREAM_ID']);
        if (!empty($livestreamId)) {
            $this->ensureLivestream($livestreamId, trim($data['LIVESTREAM_TITLE']));
        } else {
            $livestreamId = null;
        }

        // Upsert buyer
        $buyerName = trim($data['BUYER_NAME']);
        $buyerId = null;
        if (!empty($buyerName)) {
            $buyerId = $this->ensureBuyer($buyerName, trim($data['BUYER_STATE']), trim($data['BUYER_COUNTRY']));
        }

        // Determine status
        $buyFormat = strtoupper(trim($data['BUY_FORMAT']));
        $statusId = ($buyFormat === 'GIVEAWAY') ? $this->giveawayStatusId : $this->completedStatusId;

        // Convert timestamps from UTC to CST
        $orderPlacedAt = null;
        if (!empty(trim($data['ORDER_PLACED_AT_UTC']))) {
            $orderPlacedAt = utcToCst(trim($data['ORDER_PLACED_AT_UTC']));
        }
        $completedAt = null;
        if (!empty(trim($data['TRANSACTION_COMPLETED_AT_UTC']))) {
            $completedAt = utcToCst(trim($data['TRANSACTION_COMPLETED_AT_UTC']));
        }

        // Insert line item
        $stmt = $this->pdo->prepare(
            "INSERT INTO CG_AuctionLineItems (
                ledger_transaction_id, statement_id, upload_id, order_id,
                transaction_type, transaction_message, listing_title, listing_description,
                product_category, buy_format, quantity_sold,
                livestream_id, buyer_id, shipment_id, transaction_currency,
                transaction_amount, buyer_paid, original_item_price,
                coupon_cost, post_coupon_price, shipping_fee,
                commission_fee, payment_processing_fee,
                tax_on_commission_fee, tax_on_payment_processing_fee,
                order_placed_at, transaction_completed_at, current_status_id
            ) VALUES (
                :ledger_id, :statement_id, :upload_id, :order_id,
                :txn_type, :txn_msg, :title, :description,
                :category, :buy_format, :qty,
                :livestream_id, :buyer_id, :shipment_id, :currency,
                :txn_amount, :buyer_paid, :item_price,
                :coupon_cost, :post_coupon, :shipping_fee,
                :commission, :processing_fee,
                :tax_commission, :tax_processing,
                :placed_at, :completed_at, :status_id
            )"
        );

        $orderId = trim($data['ORDER_ID']);
        $shipmentId = trim($data['SHIPMENT_ID']);

        $stmt->execute([
            ':ledger_id'     => $ledgerId,
            ':statement_id'  => $this->statementId,
            ':upload_id'     => $this->uploadId,
            ':order_id'      => !empty($orderId) ? $orderId : null,
            ':txn_type'      => trim($data['TRANSACTION_TYPE']),
            ':txn_msg'       => trim($data['TRANSACTION_MESSAGE']),
            ':title'         => normalizeTitle(trim($data['LISTING_TITLE']) ?: null),
            ':description'   => trim($data['LISTING_DESCRIPTION']) ?: null,
            ':category'      => trim($data['PRODUCT_CATEGORY']) ?: null,
            ':buy_format'    => $buyFormat,
            ':qty'           => !empty(trim($data['QUANTITY_SOLD'])) ? (int) $data['QUANTITY_SOLD'] : 1,
            ':livestream_id' => $livestreamId,
            ':buyer_id'      => $buyerId,
            ':shipment_id'   => !empty($shipmentId) ? $shipmentId : null,
            ':currency'      => trim($data['TRANSACTION_CURRENCY']) ?: 'USD',
            ':txn_amount'    => parseDecimal($data['TRANSACTION_AMOUNT']) ?? 0.00,
            ':buyer_paid'    => parseDecimal($data['BUYER_PAID']),
            ':item_price'    => parseDecimal($data['ORIGINAL_ITEM_PRICE']),
            ':coupon_cost'   => parseDecimal($data['COUPON_COST']),
            ':post_coupon'   => parseDecimal($data['POST_COUPON_PRICE']),
            ':shipping_fee'  => parseDecimal($data['SHIPPING_FEE']),
            ':commission'    => parseDecimal($data['COMMISSION_FEE']),
            ':processing_fee' => parseDecimal($data['PAYMENT_PROCESSING_FEE']),
            ':tax_commission' => parseDecimal($data['TAX_ON_COMMISSION_FEE']),
            ':tax_processing' => parseDecimal($data['TAX_ON_PAYMENT_PROCESSING_FEE']),
            ':placed_at'     => $orderPlacedAt,
            ':completed_at'  => $completedAt,
            ':status_id'     => $statusId,
        ]);

        // Record initial status in history
        $histStmt = $this->pdo->prepare(
            "INSERT INTO CG_StatusHistory (ledger_transaction_id, old_status_id, new_status_id, changed_by, change_reason)
             VALUES (:ledger_id, NULL, :status_id, :user_id, 'Initial import from CSV')"
        );
        $histStmt->execute([
            ':ledger_id' => $ledgerId,
            ':status_id' => $statusId,
            ':user_id'   => $this->userId,
        ]);

        $this->rowsInserted++;
    }

    /**
     * Ensure the earnings statement row exists (one per CSV period).
     */
    private function ensureStatement(array $data): int
    {
        $reportDate = parseDatetime(trim($data['REPORT_START_DATE']));
        $reportDateOnly = $reportDate ? substr($reportDate, 0, 10) : null;
        $weekNumber = (int) trim($data['WEEK_NUMBER']);
        $sellerId = trim($data['SELLER_ID']);

        // Check if already exists
        $stmt = $this->pdo->prepare(
            "SELECT statement_id FROM CG_EarningsStatements
             WHERE report_start_date = :report_date AND week_number = :week AND seller_id = :seller"
        );
        $stmt->execute([
            ':report_date' => $reportDateOnly,
            ':week'        => $weekNumber,
            ':seller'      => $sellerId,
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            return (int) $existing['statement_id'];
        }

        // Insert new statement
        $stmt = $this->pdo->prepare(
            "INSERT INTO CG_EarningsStatements (upload_id, report_start_date, week_number, seller_id)
             VALUES (:upload_id, :report_date, :week, :seller)"
        );
        $stmt->execute([
            ':upload_id'   => $this->uploadId,
            ':report_date' => $reportDateOnly,
            ':week'        => $weekNumber,
            ':seller'      => $sellerId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ensure a livestream exists in the lookup table.
     */
    private function ensureLivestream(string $livestreamId, string $title): void
    {
        if (isset($this->livestreamCache[$livestreamId])) {
            return;
        }

        $title = trim($title);
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO CG_Livestreams (livestream_id, livestream_title)
             VALUES (:id, :title)"
        );
        $stmt->execute([':id' => $livestreamId, ':title' => $title]);
        $this->livestreamCache[$livestreamId] = true;
    }

    /**
     * Ensure a buyer exists and return their buyer_id.
     */
    private function ensureBuyer(string $name, string $state, string $country): int
    {
        if (isset($this->buyerCache[$name])) {
            return $this->buyerCache[$name];
        }

        // Try to find existing
        $stmt = $this->pdo->prepare(
            "SELECT buyer_id FROM CG_Buyers WHERE buyer_name = :name"
        );
        $stmt->execute([':name' => $name]);
        $existing = $stmt->fetch();

        if ($existing) {
            $buyerId = (int) $existing['buyer_id'];
            // Update state/country if changed
            $this->pdo->prepare(
                "UPDATE CG_Buyers SET buyer_state = :state, buyer_country = :country
                 WHERE buyer_id = :id AND (buyer_state != :state2 OR buyer_country != :country2)"
            )->execute([
                ':state'    => $state ?: null,
                ':country'  => $country ?: 'US',
                ':id'       => $buyerId,
                ':state2'   => $state ?: null,
                ':country2' => $country ?: 'US',
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO CG_Buyers (buyer_name, buyer_state, buyer_country)
                 VALUES (:name, :state, :country)"
            );
            $stmt->execute([
                ':name'    => $name,
                ':state'   => $state ?: null,
                ':country' => $country ?: 'US',
            ]);
            $buyerId = (int) $this->pdo->lastInsertId();
        }

        $this->buyerCache[$name] = $buyerId;
        return $buyerId;
    }

    /**
     * Update the statement row with totals after all rows are processed.
     */
    private function updateStatementTotals(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE CG_EarningsStatements SET
                total_rows = (SELECT COUNT(*) FROM CG_AuctionLineItems WHERE statement_id = :sid),
                total_earnings = (SELECT COALESCE(SUM(transaction_amount), 0) FROM CG_AuctionLineItems WHERE statement_id = :sid2),
                total_fees = (SELECT COALESCE(SUM(COALESCE(commission_fee,0) + COALESCE(payment_processing_fee,0) + COALESCE(tax_on_commission_fee,0) + COALESCE(tax_on_payment_processing_fee,0)), 0) FROM CG_AuctionLineItems WHERE statement_id = :sid3)
             WHERE statement_id = :sid4"
        );
        $stmt->execute([
            ':sid'  => $this->statementId,
            ':sid2' => $this->statementId,
            ':sid3' => $this->statementId,
            ':sid4' => $this->statementId,
        ]);
    }

    /**
     * Load status type IDs for Completed and Giveaway.
     */
    private function loadStatusIds(): void
    {
        $stmt = $this->pdo->query(
            "SELECT status_type_id, status_name FROM CG_StatusTypes
             WHERE status_name IN ('Completed', 'Giveaway')"
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if ($row['status_name'] === 'Completed') {
                $this->completedStatusId = (int) $row['status_type_id'];
            } elseif ($row['status_name'] === 'Giveaway') {
                $this->giveawayStatusId = (int) $row['status_type_id'];
            }
        }

        if (!isset($this->completedStatusId) || !isset($this->giveawayStatusId)) {
            throw new RuntimeException('Required status types (Completed, Giveaway) not found. Run seed data first.');
        }
    }
}
