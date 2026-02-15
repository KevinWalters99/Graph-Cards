<?php
/**
 * Card Graph — UploadLog Model
 */
class UploadLog
{
    public static function create(array $data): int
    {
        $stmt = cg_db()->prepare(
            "INSERT INTO CG_UploadLog
                (uploaded_by, original_filename, stored_filename, upload_type, file_size_bytes, parsed_start_date, parsed_end_date, status)
             VALUES
                (:user_id, :original, :stored, :type, :size, :start_date, :end_date, 'uploaded')"
        );
        $stmt->execute([
            ':user_id'    => $data['uploaded_by'],
            ':original'   => $data['original_filename'],
            ':stored'     => $data['stored_filename'],
            ':type'       => $data['upload_type'],
            ':size'       => $data['file_size_bytes'],
            ':start_date' => $data['parsed_start_date'] ?? null,
            ':end_date'   => $data['parsed_end_date'] ?? null,
        ]);
        return (int) cg_db()->lastInsertId();
    }

    public static function updateStatus(int $uploadId, string $status, ?array $stats = null, ?string $error = null): void
    {
        $sets = ['status = :status'];
        $params = [':status' => $status, ':id' => $uploadId];

        if ($stats) {
            if (isset($stats['row_count'])) {
                $sets[] = 'row_count = :row_count';
                $params[':row_count'] = $stats['row_count'];
            }
            if (isset($stats['rows_inserted'])) {
                $sets[] = 'rows_inserted = :inserted';
                $params[':inserted'] = $stats['rows_inserted'];
            }
            if (isset($stats['rows_skipped'])) {
                $sets[] = 'rows_skipped = :skipped';
                $params[':skipped'] = $stats['rows_skipped'];
            }
        }

        if ($error) {
            $sets[] = 'error_message = :error';
            $params[':error'] = $error;
        }

        if ($status === 'completed' || $status === 'failed') {
            $sets[] = 'completed_at = NOW()';
        }

        $sql = "UPDATE CG_UploadLog SET " . implode(', ', $sets) . " WHERE upload_id = :id";
        cg_db()->prepare($sql)->execute($params);
    }

    public static function getAll(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = cg_db()->query("SELECT COUNT(*) as total FROM CG_UploadLog");
        $total = (int) $countStmt->fetch()['total'];

        $stmt = cg_db()->prepare(
            "SELECT ul.*, u.username as uploaded_by_name
             FROM CG_UploadLog ul
             JOIN CG_Users u ON u.user_id = ul.uploaded_by
             ORDER BY ul.uploaded_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
