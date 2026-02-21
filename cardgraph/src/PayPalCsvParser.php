<?php
/**
 * Card Graph — PayPal CSV Parser
 *
 * Parses PayPal transaction download CSV files and inserts into CG_PayPalTransactions.
 * Skips Pending rows (authorization holds), deduplicates by paypal_txn_id,
 * and classifies each row into a charge_category.
 */
class PayPalCsvParser
{
    private const EXPECTED_HEADERS = [
        'Date', 'Time', 'TimeZone', 'Name', 'Type', 'Status',
        'Currency', 'Amount', 'Fees', 'Total', 'Exchange Rate',
        'Receipt ID', 'Balance', 'Transaction ID', 'Item Title',
    ];

    private PDO $pdo;
    private int $uploadId;
    private int $rowsInserted = 0;
    private int $rowsSkipped = 0;
    private int $totalRows = 0;

    public function __construct(PDO $pdo, int $uploadId)
    {
        $this->pdo = $pdo;
        $this->uploadId = $uploadId;
    }

    /**
     * Parse a PayPal CSV and insert all valid rows.
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
            // Skip UTF-8 BOM if present (must happen before fgetcsv
            // so quoted headers like "Date" are parsed correctly)
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Validate header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new RuntimeException('CSV file is empty');
            }

            $headers = array_map('trim', $headers);

            // Validate headers (allow extra trailing columns but require our 15)
            $headerCount = count(self::EXPECTED_HEADERS);
            $actualSlice = array_slice($headers, 0, $headerCount);
            if ($actualSlice !== self::EXPECTED_HEADERS) {
                $missing = array_diff(self::EXPECTED_HEADERS, $actualSlice);
                $msg = 'PayPal CSV header mismatch.';
                if (!empty($missing)) {
                    $msg .= ' Missing: ' . implode(', ', $missing) . '.';
                }
                throw new RuntimeException($msg);
            }

            // Prepare insert statement
            $insertStmt = $this->pdo->prepare(
                "INSERT INTO CG_PayPalTransactions (
                    paypal_txn_id, transaction_date, transaction_time, timezone,
                    name, type, status, currency, amount, fees, net_amount,
                    balance, receipt_id, item_title, order_number,
                    charge_category, upload_id
                ) VALUES (
                    :txn_id, :txn_date, :txn_time, :tz,
                    :name, :type, :status, :currency, :amount, :fees, :net_amount,
                    :balance, :receipt_id, :item_title, :order_number,
                    :category, :upload_id
                )"
            );

            // Prepare dedup check
            $dedupStmt = $this->pdo->prepare(
                "SELECT 1 FROM CG_PayPalTransactions WHERE paypal_txn_id = :txn_id"
            );

            $this->pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (count($row) < $headerCount) {
                    continue;
                }

                $data = array_combine(
                    array_slice(self::EXPECTED_HEADERS, 0, $headerCount),
                    array_slice($row, 0, $headerCount)
                );
                $this->totalRows++;
                $this->processRow($data, $insertStmt, $dedupStmt);
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
        ];
    }

    /**
     * Process a single CSV row.
     */
    private function processRow(array $data, PDOStatement $insertStmt, PDOStatement $dedupStmt): void
    {
        $status = trim($data['Status']);

        // Skip Pending rows — these are temporary authorization holds
        if ($status === 'Pending') {
            $this->rowsSkipped++;
            return;
        }

        $txnId = trim($data['Transaction ID']);
        if (empty($txnId)) {
            $this->rowsSkipped++;
            return;
        }

        // Dedup check
        $dedupStmt->execute([':txn_id' => $txnId]);
        if ($dedupStmt->fetch()) {
            $this->rowsSkipped++;
            return;
        }

        // Parse amounts
        $amount = $this->parseAmount($data['Amount']);
        $fees = $this->parseAmount($data['Fees']);
        $total = $this->parseAmount($data['Total']);
        $balance = $this->parseAmount($data['Balance']);

        // Parse date
        $dateStr = trim($data['Date']);
        $txnDate = $this->parseDate($dateStr);
        if (!$txnDate) {
            $this->rowsSkipped++;
            return;
        }

        $type = trim($data['Type']);
        $category = $this->classifyType($type, $amount);

        // Extract order number from Item Title
        $itemTitle = trim($data['Item Title']);
        $orderNumber = null;
        if (!empty($itemTitle) && preg_match('/Order Number\s*:\s*([\d-]+)/', $itemTitle, $m)) {
            $orderNumber = $m[1];
        }

        $insertStmt->execute([
            ':txn_id'       => $txnId,
            ':txn_date'     => $txnDate,
            ':txn_time'     => trim($data['Time']) ?: '00:00:00',
            ':tz'           => trim($data['TimeZone']) ?: 'PST',
            ':name'         => trim($data['Name']) ?: null,
            ':type'         => $type,
            ':status'       => $status,
            ':currency'     => trim($data['Currency']) ?: 'USD',
            ':amount'       => $amount,
            ':fees'         => $fees,
            ':net_amount'   => $total,
            ':balance'      => $balance,
            ':receipt_id'   => trim($data['Receipt ID']) ?: null,
            ':item_title'   => !empty($itemTitle) ? $itemTitle : null,
            ':order_number' => $orderNumber,
            ':category'     => $category,
            ':upload_id'    => $this->uploadId,
        ]);

        $this->rowsInserted++;
    }

    /**
     * Classify a PayPal transaction type into a charge category.
     */
    private function classifyType(string $type, float $amount): string
    {
        $map = [
            'PreApproved Payment Bill User Payment' => 'purchase',
            'Express Checkout Payment'              => 'purchase',
            'Website Payment'                       => 'purchase',
            'Postage Payment'                       => 'purchase',
            'Payment Refund'                        => 'refund',
            'Mass Pay Payment'                      => 'income',
            'General Card Deposit'                  => 'offset',
            'Bank Deposit to PP Account'            => 'offset',
            'General Authorization'                 => 'auth',
            'General Card Withdrawal'               => 'withdrawal',
            'User Initiated Withdrawal'             => 'withdrawal',
        ];

        if ($type === 'Mobile Payment') {
            return $amount < 0 ? 'purchase' : 'income';
        }

        return $map[$type] ?? 'offset';
    }

    /**
     * Parse a PayPal amount string (handles quotes, $, commas, negatives).
     */
    private function parseAmount(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $cleaned = str_replace(['"', '$', ',', ' '], '', trim($value));
        if ($cleaned === '') {
            return null;
        }
        return (float) $cleaned;
    }

    /**
     * Parse a date string in MM/DD/YYYY format to Y-m-d.
     */
    private function parseDate(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        if (empty($dateStr)) {
            return null;
        }

        // PayPal uses MM/DD/YYYY
        $parts = explode('/', $dateStr);
        if (count($parts) === 3) {
            $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];
            return "{$year}-{$month}-{$day}";
        }

        // Fallback: try standard parsing
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
