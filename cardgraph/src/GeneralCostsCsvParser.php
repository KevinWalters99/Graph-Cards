<?php
/**
 * Card Graph — General Costs CSV Parser
 *
 * Parses a CSV with Date, Description, Amount fields.
 * Each row is inserted as a lump-sum cost with quantity=1.
 */
class GeneralCostsCsvParser
{
    private PDO $pdo;
    private int $userId;
    private int $rowsInserted = 0;
    private int $rowsSkipped = 0;
    private int $totalRows = 0;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    /**
     * Parse a general costs CSV and insert all valid rows.
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
            // Skip UTF-8 BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Read and validate header row
            $headers = fgetcsv($handle);
            if ($headers === false || count($headers) < 3) {
                throw new RuntimeException('CSV must have at least 3 columns: Date, Description, Amount');
            }

            // Normalize headers (trim whitespace, lowercase)
            $normalizedHeaders = array_map(function ($h) {
                return strtolower(trim($h, " \t\n\r\0\x0B\""));
            }, $headers);

            // Find column indices
            $dateIdx = $this->findColumn($normalizedHeaders, ['date', 'cost_date', 'transaction_date']);
            $descIdx = $this->findColumn($normalizedHeaders, ['description', 'desc', 'item', 'name', 'memo']);
            $amtIdx  = $this->findColumn($normalizedHeaders, ['amount', 'cost', 'total', 'price']);

            if ($dateIdx === null || $descIdx === null || $amtIdx === null) {
                throw new RuntimeException(
                    'CSV must contain Date, Description, and Amount columns. ' .
                    'Found headers: ' . implode(', ', $headers)
                );
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO CG_GeneralCosts (cost_date, description, amount, quantity, total, distribute, entered_by)
                 VALUES (:cost_date, :description, :amount, 1, :total, 0, :entered_by)"
            );

            $this->pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                $this->totalRows++;

                // Skip empty rows
                if (count($row) < 3 || (empty(trim($row[$dateIdx] ?? '')) && empty(trim($row[$descIdx] ?? '')))) {
                    $this->rowsSkipped++;
                    continue;
                }

                $dateRaw = trim($row[$dateIdx] ?? '');
                $desc = trim($row[$descIdx] ?? '');
                $amountRaw = trim($row[$amtIdx] ?? '');

                // Parse date — support MM/DD/YYYY, YYYY-MM-DD, M/D/YYYY
                $date = $this->parseDate($dateRaw);
                if (!$date) {
                    $this->rowsSkipped++;
                    continue;
                }

                // Parse amount — remove $, commas, quotes
                $amount = $this->parseAmount($amountRaw);
                if ($amount === null || $amount == 0) {
                    $this->rowsSkipped++;
                    continue;
                }

                // Skip if no description
                if (empty($desc)) {
                    $this->rowsSkipped++;
                    continue;
                }

                // Amount should be positive (it's a cost)
                $amount = abs($amount);
                $total = round($amount, 2);

                $stmt->execute([
                    ':cost_date'   => $date,
                    ':description' => $desc,
                    ':amount'      => $total,
                    ':total'       => $total,
                    ':entered_by'  => $this->userId,
                ]);

                $this->rowsInserted++;
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
     * Find a column index by checking multiple possible header names.
     */
    private function findColumn(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $name) {
            $idx = array_search($name, $headers, true);
            if ($idx !== false) {
                return $idx;
            }
        }
        return null;
    }

    /**
     * Parse a date string into Y-m-d format.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw, " \t\"");
        if (empty($raw)) return null;

        // Try MM/DD/YYYY or M/D/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
        }

        // Try YYYY-MM-DD
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Try strtotime as fallback
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * Parse an amount string, removing currency symbols and commas.
     */
    private function parseAmount(string $raw): ?float
    {
        $raw = trim($raw, " \t\"");
        if (empty($raw)) return null;

        // Remove $, commas, spaces
        $clean = str_replace(['$', ',', ' '], '', $raw);

        // Handle parentheses as negative: (123.45) → -123.45
        if (preg_match('/^\((.+)\)$/', $clean, $m)) {
            $clean = '-' . $m[1];
        }

        if (!is_numeric($clean)) return null;

        return (float) $clean;
    }
}
