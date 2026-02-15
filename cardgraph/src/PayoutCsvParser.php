<?php
/**
 * Card Graph — Payout CSV Parser
 *
 * Parses payout CSV files into CG_Payouts.
 * Expected columns: Amount, Destination, Date Initiated, Arrival Date, Status
 */
class PayoutCsvParser
{
    private PDO $pdo;
    private int $uploadId;
    private int $userId;
    private int $rowsInserted = 0;
    private int $rowsSkipped = 0;
    private int $totalRows = 0;

    // Acceptable header variations (case-insensitive, trimmed)
    private const REQUIRED_HEADERS = ['amount', 'destination', 'date initiated', 'arrival date', 'status'];

    public function __construct(PDO $pdo, int $uploadId, int $userId)
    {
        $this->pdo = $pdo;
        $this->uploadId = $uploadId;
        $this->userId = $userId;
    }

    /**
     * Parse a payout CSV file and insert data.
     */
    public function parse(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$filePath}");
        }

        try {
            // Read and validate headers
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new RuntimeException('CSV file is empty');
            }

            // Strip BOM and normalize
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $headers);

            // Map headers to column indices
            $colMap = [];
            foreach (self::REQUIRED_HEADERS as $required) {
                $idx = array_search($required, $headers);
                if ($idx === false) {
                    throw new RuntimeException("Missing required column: {$required}");
                }
                $colMap[$required] = $idx;
            }

            $this->pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                $this->totalRows++;
                $this->processRow($row, $colMap);
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

    private function processRow(array $row, array $colMap): void
    {
        $amount = parseDecimal($row[$colMap['amount']] ?? '');
        $destination = trim($row[$colMap['destination']] ?? '');
        $dateInitiated = parseDate($row[$colMap['date initiated']] ?? '');
        $arrivalDate = parseDate($row[$colMap['arrival date']] ?? '');
        $statusRaw = trim($row[$colMap['status']] ?? '');

        if ($amount === null || empty($destination) || empty($dateInitiated)) {
            $this->rowsSkipped++;
            return;
        }

        // Normalize status
        $statusMap = [
            'in progress' => 'In Progress',
            'failed'      => 'Failed',
            'completed'   => 'Completed',
        ];
        $status = $statusMap[strtolower($statusRaw)] ?? 'In Progress';

        $stmt = $this->pdo->prepare(
            "INSERT INTO CG_Payouts (amount, destination, date_initiated, arrival_date, status, upload_id, entered_by)
             VALUES (:amount, :dest, :initiated, :arrival, :status, :upload_id, :user_id)"
        );
        $stmt->execute([
            ':amount'    => $amount,
            ':dest'      => $destination,
            ':initiated' => $dateInitiated,
            ':arrival'   => $arrivalDate,
            ':status'    => $status,
            ':upload_id' => $this->uploadId,
            ':user_id'   => $this->userId,
        ]);

        $this->rowsInserted++;
    }
}
