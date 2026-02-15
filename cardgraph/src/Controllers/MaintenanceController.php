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
}
