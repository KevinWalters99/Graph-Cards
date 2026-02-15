<?php
/**
 * Card Graph — Upload Controller
 *
 * Handles CSV file uploads for both earnings and payouts.
 */
class UploadController
{
    /**
     * POST /api/uploads/earnings
     */
    public function earnings(array $params = []): void
    {
        $userId = Auth::getUserId();
        $secrets = $GLOBALS['cg_secrets'];

        // Validate file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = $this->getUploadErrorMessage($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
            jsonError("Upload failed: {$errorMsg}", 400);
        }

        $file = $_FILES['file'];
        $this->validateFile($file, $secrets['upload']);

        // Parse dates from filename
        $originalName = $file['name'];
        try {
            $dates = FilenameParser::parse($originalName);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        }

        // Store file with UUID name
        $storedName = uniqid('earnings_', true) . '.csv';
        $storedPath = $secrets['upload']['upload_dir'] . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            jsonError('Failed to store uploaded file', 500);
        }

        // Create upload log entry
        $uploadId = UploadLog::create([
            'uploaded_by'       => $userId,
            'original_filename' => $originalName,
            'stored_filename'   => $storedName,
            'upload_type'       => 'earnings',
            'file_size_bytes'   => $file['size'],
            'parsed_start_date' => $dates['start_date'],
            'parsed_end_date'   => $dates['end_date'],
        ]);

        // Parse and ingest CSV
        try {
            UploadLog::updateStatus($uploadId, 'processing');

            $parser = new CsvParser(cg_db(), $uploadId, $userId);
            $result = $parser->parse($storedPath);

            UploadLog::updateStatus($uploadId, 'completed', [
                'row_count'     => $result['total_rows'],
                'rows_inserted' => $result['rows_inserted'],
                'rows_skipped'  => $result['rows_skipped'],
            ]);

            // Normalize listing titles (pad # numbers to 4 digits)
            $this->normalizeTitles();

            jsonResponse([
                'upload_id'     => $uploadId,
                'filename'      => $originalName,
                'date_range'    => $dates,
                'total_rows'    => $result['total_rows'],
                'rows_inserted' => $result['rows_inserted'],
                'rows_skipped'  => $result['rows_skipped'],
                'statement_id'  => $result['statement_id'],
            ], 201);
        } catch (Exception $e) {
            UploadLog::updateStatus($uploadId, 'failed', null, $e->getMessage());
            jsonError('CSV processing failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/uploads/payouts
     */
    public function payouts(array $params = []): void
    {
        $userId = Auth::getUserId();
        $secrets = $GLOBALS['cg_secrets'];

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = $this->getUploadErrorMessage($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
            jsonError("Upload failed: {$errorMsg}", 400);
        }

        $file = $_FILES['file'];
        $this->validateFile($file, $secrets['upload']);

        $originalName = $file['name'];
        $storedName = uniqid('payouts_', true) . '.csv';
        $storedPath = $secrets['upload']['upload_dir'] . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            jsonError('Failed to store uploaded file', 500);
        }

        $uploadId = UploadLog::create([
            'uploaded_by'       => $userId,
            'original_filename' => $originalName,
            'stored_filename'   => $storedName,
            'upload_type'       => 'payouts',
            'file_size_bytes'   => $file['size'],
            'parsed_start_date' => null,
            'parsed_end_date'   => null,
        ]);

        try {
            UploadLog::updateStatus($uploadId, 'processing');

            $parser = new PayoutCsvParser(cg_db(), $uploadId, $userId);
            $result = $parser->parse($storedPath);

            UploadLog::updateStatus($uploadId, 'completed', [
                'row_count'     => $result['total_rows'],
                'rows_inserted' => $result['rows_inserted'],
                'rows_skipped'  => $result['rows_skipped'],
            ]);

            jsonResponse([
                'upload_id'     => $uploadId,
                'filename'      => $originalName,
                'total_rows'    => $result['total_rows'],
                'rows_inserted' => $result['rows_inserted'],
                'rows_skipped'  => $result['rows_skipped'],
            ], 201);
        } catch (Exception $e) {
            UploadLog::updateStatus($uploadId, 'failed', null, $e->getMessage());
            jsonError('CSV processing failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/uploads
     */
    public function list(array $params = []): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));

        $result = UploadLog::getAll($page, $perPage);
        jsonResponse($result);
    }

    /**
     * Validate an uploaded file.
     */
    private function validateFile(array $file, array $uploadConfig): void
    {
        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $uploadConfig['allowed_extensions'], true)) {
            jsonError("Invalid file type: .{$ext}. Only CSV files are allowed.", 400);
        }

        // Check MIME type
        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            jsonError("Invalid file MIME type: {$mime}", 400);
        }

        // Check file size
        if ($file['size'] > $uploadConfig['max_size_bytes']) {
            $maxMB = round($uploadConfig['max_size_bytes'] / 1048576, 1);
            jsonError("File too large. Maximum size: {$maxMB} MB", 400);
        }
    }

    /**
     * Bulk-normalize listing titles: pad #N to 4 digits.
     * Idempotent — safe to run on every import.
     */
    private function normalizeTitles(): void
    {
        $pdo = cg_db();

        $stmt = $pdo->query(
            "SELECT ledger_transaction_id, listing_title
             FROM CG_AuctionLineItems
             WHERE listing_title REGEXP '#[0-9]{1,3}([^0-9]|$)'"
        );
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return;
        }

        $update = $pdo->prepare(
            "UPDATE CG_AuctionLineItems
             SET listing_title = :title
             WHERE ledger_transaction_id = :id"
        );

        foreach ($rows as $row) {
            $normalized = normalizeTitle($row['listing_title']);
            if ($normalized !== $row['listing_title']) {
                $update->execute([
                    ':title' => $normalized,
                    ':id'    => $row['ledger_transaction_id'],
                ]);
            }
        }
    }

    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
            default               => 'Unknown upload error',
        };
    }
}
