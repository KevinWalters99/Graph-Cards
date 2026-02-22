<?php
/**
 * Card Graph — Transcription Controller
 *
 * Manages audio recording & transcription sessions for live auctions.
 * Settings (single-row config), session CRUD, lifecycle control,
 * status polling, logs, and environment check.
 */
class TranscriptionController
{
    // ─── Settings ─────────────────────────────────────────────────

    /**
     * GET /api/transcription/settings — Fetch global config.
     */
    public function getSettings(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $row = $pdo->query("SELECT * FROM CG_TranscriptionSettings WHERE setting_id = 1")
                    ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            jsonError('Settings not initialized', 500);
        }

        jsonResponse(['data' => $row]);
    }

    /**
     * PUT /api/transcription/settings — Update global config with validation.
     */
    public function updateSettings(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $userId = Auth::getUserId();
        $pdo = cg_db();

        // Validate ranges
        $rules = [
            'segment_length_minutes'  => [5, 60],
            'silence_threshold_dbfs'  => [-60, -30],
            'silence_timeout_minutes' => [1, 30],
            'max_session_hours'       => [1, 24],
            'max_cpu_cores'           => [1, 3],
            'min_free_disk_gb'        => [1, 50],
            'audio_retention_days'    => [7, 365],
        ];

        foreach ($rules as $field => [$min, $max]) {
            if (isset($body[$field])) {
                $val = (int) $body[$field];
                if ($val < $min || $val > $max) {
                    jsonError("{$field} must be between {$min} and {$max}", 400);
                }
            }
        }

        // Validate ENUMs
        $enums = [
            'sample_rate'      => ['8000', '16000', '22050'],
            'audio_channels'   => ['mono', 'stereo'],
            'audio_format'     => ['wav', 'flac'],
            'whisper_model'    => ['tiny', 'base', 'small', 'medium', 'large'],
            'priority_mode'    => ['low', 'normal'],
            'folder_structure' => ['year-based', 'flat'],
            'acquisition_mode' => ['direct_stream', 'browser_automation'],
        ];

        foreach ($enums as $field => $allowed) {
            if (isset($body[$field]) && !in_array($body[$field], $allowed, true)) {
                jsonError("{$field} must be one of: " . implode(', ', $allowed), 400);
            }
        }

        $stmt = $pdo->prepare(
            "UPDATE CG_TranscriptionSettings SET
                segment_length_minutes  = :segment_length,
                sample_rate             = :sample_rate,
                audio_channels          = :audio_channels,
                audio_format            = :audio_format,
                silence_threshold_dbfs  = :silence_threshold,
                silence_timeout_minutes = :silence_timeout,
                max_session_hours       = :max_hours,
                max_cpu_cores           = :max_cpu,
                whisper_model           = :whisper_model,
                priority_mode           = :priority_mode,
                base_archive_dir        = :archive_dir,
                folder_structure        = :folder_structure,
                min_free_disk_gb        = :min_disk,
                acquisition_mode        = :acquisition_mode,
                audio_retention_days    = :retention_days,
                updated_by              = :updated_by
             WHERE setting_id = 1"
        );

        $stmt->execute([
            ':segment_length'    => (int) ($body['segment_length_minutes'] ?? 15),
            ':sample_rate'       => $body['sample_rate'] ?? '16000',
            ':audio_channels'    => $body['audio_channels'] ?? 'mono',
            ':audio_format'      => $body['audio_format'] ?? 'wav',
            ':silence_threshold' => (int) ($body['silence_threshold_dbfs'] ?? -48),
            ':silence_timeout'   => (int) ($body['silence_timeout_minutes'] ?? 10),
            ':max_hours'         => (int) ($body['max_session_hours'] ?? 10),
            ':max_cpu'           => (int) ($body['max_cpu_cores'] ?? 2),
            ':whisper_model'     => $body['whisper_model'] ?? 'base',
            ':priority_mode'     => $body['priority_mode'] ?? 'low',
            ':archive_dir'       => trim($body['base_archive_dir'] ?? '/volume1/auction_archive/'),
            ':folder_structure'  => $body['folder_structure'] ?? 'year-based',
            ':min_disk'          => (int) ($body['min_free_disk_gb'] ?? 5),
            ':acquisition_mode'  => $body['acquisition_mode'] ?? 'direct_stream',
            ':retention_days'    => (int) ($body['audio_retention_days'] ?? 30),
            ':updated_by'        => $userId,
        ]);

        jsonResponse(['success' => true]);
    }

    // ─── Session CRUD ─────────────────────────────────────────────

    /**
     * GET /api/transcription/sessions — Paginated session list.
     */
    public function listSessions(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int) ($_GET['per_page'] ?? 20)));
        $status  = $_GET['status'] ?? null;

        $conditions = [];
        if ($status) {
            $conditions[] = [
                'clause' => 'status = :status',
                'param'  => ':status',
                'value'  => $status,
            ];
        }

        $paged = buildPaginatedQuery(
            "SELECT * FROM CG_TranscriptionSessions",
            $conditions,
            'scheduled_start DESC',
            $page,
            $perPage
        );

        // Count
        $countStmt = $pdo->prepare($paged['countQuery']);
        $countStmt->execute($paged['params']);
        $total = (int) $countStmt->fetchColumn();

        // Data
        $dataStmt = $pdo->prepare($paged['query']);
        $dataStmt->execute($paged['params']);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with per-session segment transcription counts
        if (!empty($rows)) {
            $ids = array_map(fn($r) => (int) $r['session_id'], $rows);
            $placeholders = implode(',', $ids);
            $segStats = $pdo->query(
                "SELECT session_id,
                        SUM(CASE WHEN transcription_status = 'complete' THEN 1 ELSE 0 END) AS tx_complete,
                        SUM(CASE WHEN transcription_status = 'pending' THEN 1 ELSE 0 END) AS tx_pending,
                        SUM(CASE WHEN transcription_status = 'transcribing' THEN 1 ELSE 0 END) AS tx_active
                 FROM CG_TranscriptionSegments
                 WHERE session_id IN ({$placeholders})
                 GROUP BY session_id"
            )->fetchAll(PDO::FETCH_ASSOC);

            $statsMap = [];
            foreach ($segStats as $st) {
                $statsMap[(int) $st['session_id']] = $st;
            }

            foreach ($rows as &$row) {
                $sid = (int) $row['session_id'];
                $row['tx_complete'] = (int) ($statsMap[$sid]['tx_complete'] ?? 0);
                $row['tx_pending']  = (int) ($statsMap[$sid]['tx_pending'] ?? 0);
                $row['tx_active']   = (int) ($statsMap[$sid]['tx_active'] ?? 0);
            }
            unset($row);
        }

        jsonResponse([
            'data'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * POST /api/transcription/sessions — Create a new session.
     */
    public function createSession(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $userId = Auth::getUserId();
        $pdo = cg_db();

        $required = ['auction_name', 'auction_url', 'scheduled_start'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                jsonError("Missing required field: {$field}", 400);
            }
        }

        $scheduledStart = parseDatetime($body['scheduled_start']);
        if (!$scheduledStart) {
            jsonError('Invalid scheduled_start datetime', 400);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO CG_TranscriptionSessions (
                auction_name, auction_url, scheduled_start,
                override_segment_length, override_silence_timeout,
                override_max_duration, override_cpu_limit, override_acquisition_mode,
                created_by
            ) VALUES (
                :name, :url, :start,
                :seg_len, :silence_to, :max_dur, :cpu_limit, :acq_mode,
                :created_by
            )"
        );

        $stmt->execute([
            ':name'       => trim($body['auction_name']),
            ':url'        => trim($body['auction_url']),
            ':start'      => $scheduledStart,
            ':seg_len'    => isset($body['override_segment_length'])   ? (int) $body['override_segment_length']   : null,
            ':silence_to' => isset($body['override_silence_timeout'])  ? (int) $body['override_silence_timeout']  : null,
            ':max_dur'    => isset($body['override_max_duration'])     ? (int) $body['override_max_duration']     : null,
            ':cpu_limit'  => isset($body['override_cpu_limit'])        ? (int) $body['override_cpu_limit']        : null,
            ':acq_mode'   => !empty($body['override_acquisition_mode']) ? $body['override_acquisition_mode']      : null,
            ':created_by' => $userId,
        ]);

        jsonResponse(['session_id' => (int) $pdo->lastInsertId()], 201);
    }

    /**
     * GET /api/transcription/sessions/{id} — Session detail with segments and recent logs.
     */
    public function getSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        // Session
        $stmt = $pdo->prepare("SELECT * FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }

        // Effective segment length (override or global)
        $segLen = $session['override_segment_length'];
        if (!$segLen) {
            $global = $pdo->query("SELECT segment_length_minutes FROM CG_TranscriptionSettings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC);
            $segLen = (int) ($global['segment_length_minutes'] ?? 15);
        }

        // Segments
        $segStmt = $pdo->prepare(
            "SELECT * FROM CG_TranscriptionSegments
             WHERE session_id = :id
             ORDER BY segment_number ASC"
        );
        $segStmt->execute([':id' => $id]);
        $segments = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent logs (last 50)
        $logStmt = $pdo->prepare(
            "SELECT * FROM CG_TranscriptionLogs
             WHERE session_id = :id
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $logStmt->execute([':id' => $id]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'session'            => $session,
            'segments'           => $segments,
            'logs'               => $logs,
            'segment_length_min' => (int) $segLen,
        ]);
    }

    /**
     * PUT /api/transcription/sessions/{id} — Update session (only if scheduled).
     */
    public function updateSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $body = getJsonBody();
        $pdo = cg_db();

        // Verify session exists and is editable
        $stmt = $pdo->prepare("SELECT status FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }
        if (!in_array($session['status'], ['scheduled', 'error', 'complete', 'stopped'], true)) {
            jsonError('Cannot edit a session that is currently recording or processing', 400);
        }

        $scheduledStart = !empty($body['scheduled_start']) ? parseDatetime($body['scheduled_start']) : null;

        // Only reset status to 'scheduled' if the scheduled start time is being changed
        // (editing just the name should NOT reset a completed/stopped session)
        $resetStatus = null;
        if (!empty($body['scheduled_start']) && in_array($session['status'], ['error', 'stopped'], true)) {
            $resetStatus = 'scheduled';
        }

        $stmt = $pdo->prepare(
            "UPDATE CG_TranscriptionSessions SET
                auction_name = COALESCE(:name, auction_name),
                auction_url  = COALESCE(:url, auction_url),
                scheduled_start = COALESCE(:start, scheduled_start),
                override_segment_length   = :seg_len,
                override_silence_timeout  = :silence_to,
                override_max_duration     = :max_dur,
                override_cpu_limit        = :cpu_limit,
                override_acquisition_mode = :acq_mode,
                status = COALESCE(:reset_status, status),
                stop_reason = CASE WHEN :reset_status2 IS NOT NULL THEN NULL ELSE stop_reason END,
                actual_start_time = CASE WHEN :reset_status3 IS NOT NULL THEN NULL ELSE actual_start_time END,
                end_time = CASE WHEN :reset_status4 IS NOT NULL THEN NULL ELSE end_time END
             WHERE session_id = :id"
        );

        // Extra binds for the CASE expressions (PDO can't reuse named params)
        $execParams = [
            ':name'       => isset($body['auction_name']) ? trim($body['auction_name']) : null,
            ':url'        => isset($body['auction_url'])  ? trim($body['auction_url'])  : null,
            ':start'      => $scheduledStart,
            ':seg_len'    => isset($body['override_segment_length'])   ? (int) $body['override_segment_length']   : null,
            ':silence_to' => isset($body['override_silence_timeout'])  ? (int) $body['override_silence_timeout']  : null,
            ':max_dur'    => isset($body['override_max_duration'])     ? (int) $body['override_max_duration']     : null,
            ':cpu_limit'  => isset($body['override_cpu_limit'])        ? (int) $body['override_cpu_limit']        : null,
            ':acq_mode'   => !empty($body['override_acquisition_mode']) ? $body['override_acquisition_mode']      : null,
            ':reset_status'  => $resetStatus,
            ':reset_status2' => $resetStatus,
            ':reset_status3' => $resetStatus,
            ':reset_status4' => $resetStatus,
            ':id'         => $id,
        ];

        $stmt->execute($execParams);

        jsonResponse(['success' => true]);
    }

    /**
     * DELETE /api/transcription/sessions/{id} — Delete session and clean up files.
     */
    public function deleteSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT status, session_dir FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }

        // If session is actively running, stop it first
        if (in_array($session['status'], ['recording', 'processing'], true)) {
            $toolsDir = realpath(__DIR__ . '/../../tools');
            $cancelFile = $toolsDir . '/transcription_cancel_' . $id . '.signal';
            @touch($cancelFile);
            // Brief pause to let processes see the signal
            usleep(500000);
        }

        // Clean up archive files on disk
        $sessionDir = $session['session_dir'] ?? '';
        $filesDeleted = 0;
        if ($sessionDir !== '' && is_dir($sessionDir)) {
            $filesDeleted = $this->removeDirectory($sessionDir);
        }

        // Delete DB records (segments, logs, session)
        $pdo->prepare("DELETE FROM CG_TranscriptionSegments WHERE session_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM CG_TranscriptionLogs WHERE session_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM CG_TranscriptionSessions WHERE session_id = :id")->execute([':id' => $id]);

        // Clean up any leftover signal/lock files
        $toolsDir = realpath(__DIR__ . '/../../tools');
        foreach (['stop', 'cancel'] as $sig) {
            @unlink($toolsDir . '/transcription_' . $sig . '_' . $id . '.signal');
        }
        @unlink($toolsDir . '/transcription_session_' . $id . '.lock');

        jsonResponse(['success' => true, 'files_deleted' => $filesDeleted]);
    }

    /**
     * Recursively remove a directory and all its contents. Returns count of files removed.
     */
    private function removeDirectory(string $dir): int
    {
        $count = 0;
        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += $this->removeDirectory($path);
            } else {
                if (@unlink($path)) {
                    $count++;
                }
            }
        }
        @rmdir($dir);
        return $count;
    }

    // ─── Session Lifecycle ────────────────────────────────────────

    /**
     * POST /api/transcription/sessions/{id}/start — Launch recording via Python manager.
     */
    public function startSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        // Verify session exists and is scheduled
        $stmt = $pdo->prepare("SELECT * FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }
        if ($session['status'] !== 'scheduled') {
            jsonError('Session must be in "scheduled" status to start', 400);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $lockFile = $toolsDir . '/transcription_session_' . $id . '.lock';
        $outputFile = $toolsDir . '/transcription_session_' . $id . '.out';
        $script = $toolsDir . '/transcription_manager.py';

        if (file_exists($lockFile)) {
            jsonError('Session already running (lock file exists)', 409);
        }

        if (!file_exists($script)) {
            jsonError('transcription_manager.py not found', 500);
        }

        // Determine acquisition mode
        $globalSettings = $pdo->query("SELECT acquisition_mode FROM CG_TranscriptionSettings WHERE setting_id = 1")
                              ->fetch(PDO::FETCH_ASSOC);
        $acqMode = $session['override_acquisition_mode'] ?: ($globalSettings['acquisition_mode'] ?? 'direct_stream');

        if ($acqMode === 'browser_automation') {
            // Browser automation needs Docker (root access).
            // Write a start-request flag — the cron wrapper (running as root) picks it up.
            $requestFile = $toolsDir . '/start_session_' . $id . '.request';
            file_put_contents($requestFile, date('Y-m-d H:i:s'));

            // Update session status to pending_start
            $upd = $pdo->prepare(
                "UPDATE CG_TranscriptionSessions
                 SET status = 'recording'
                 WHERE session_id = :id"
            );
            $upd->execute([':id' => $id]);

            $this->insertLog($pdo, $id, 'info', 'session_queued',
                'Session queued for browser_automation start (cron will launch within 60s)');

            jsonResponse(['status' => 'queued', 'message' => 'Browser automation session queued — starts within 60 seconds']);
        }

        // direct_stream mode — launch directly (no Docker needed)
        // Find python binary
        $pythonBin = 'python3';
        exec('which python3 2>/dev/null', $testOut, $testRet);
        if ($testRet !== 0) {
            exec('which python 2>/dev/null', $testOut2, $testRet2);
            if ($testRet2 === 0) {
                $pythonBin = 'python';
            }
        }

        // Build background command
        $cmd = 'touch ' . escapeshellarg($lockFile) . ' && '
             . escapeshellcmd($pythonBin) . ' ' . escapeshellarg($script) . ' --session-id ' . (int) $id
             . ' > ' . escapeshellarg($outputFile) . ' 2>&1'
             . '; rm -f ' . escapeshellarg($lockFile);

        shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');

        // Update session status
        $upd = $pdo->prepare(
            "UPDATE CG_TranscriptionSessions
             SET status = 'recording'
             WHERE session_id = :id"
        );
        $upd->execute([':id' => $id]);

        $this->insertLog($pdo, $id, 'info', 'session_started', 'Recording session started');

        jsonResponse(['status' => 'started']);
    }

    /**
     * POST /api/transcription/sessions/{id}/stop — Signal to stop recording (transcription continues).
     */
    public function stopSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT status FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }
        if (!in_array($session['status'], ['recording', 'processing'], true)) {
            jsonError('Session must be recording or processing to stop', 400);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $signalFile = $toolsDir . '/transcription_stop_' . $id . '.signal';

        touch($signalFile);

        $this->insertLog($pdo, $id, 'info', 'stop_requested', 'User requested stop — recording will stop, transcription continues');

        jsonResponse(['status' => 'stop_signaled']);
    }

    /**
     * POST /api/transcription/sessions/{id}/cancel — Signal to cancel everything.
     */
    public function cancelSession(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT status FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }
        if (!in_array($session['status'], ['recording', 'processing'], true)) {
            jsonError('Session must be recording or processing to cancel', 400);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $signalFile = $toolsDir . '/transcription_cancel_' . $id . '.signal';

        touch($signalFile);

        $this->insertLog($pdo, $id, 'warning', 'cancel_requested', 'User requested cancel — all processes will stop');

        jsonResponse(['status' => 'cancel_signaled']);
    }

    // ─── Status & Logs ────────────────────────────────────────────

    /**
     * GET /api/transcription/sessions/{id}/status — Lightweight poll endpoint.
     */
    public function getSessionStatus(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "SELECT session_id, status, actual_start_time, end_time,
                    total_segments, total_duration_sec, override_segment_length
             FROM CG_TranscriptionSessions WHERE session_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
        }

        // Effective segment length
        $segLen = $session['override_segment_length'];
        if (!$segLen) {
            $global = $pdo->query("SELECT segment_length_minutes FROM CG_TranscriptionSettings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC);
            $segLen = (int) ($global['segment_length_minutes'] ?? 15);
        }

        // Segment summary
        $segStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_segments,
                SUM(CASE WHEN recording_status = 'complete' THEN 1 ELSE 0 END) AS rec_complete,
                SUM(CASE WHEN recording_status = 'recording' THEN 1 ELSE 0 END) AS rec_active,
                SUM(CASE WHEN transcription_status = 'complete' THEN 1 ELSE 0 END) AS tx_complete,
                SUM(CASE WHEN transcription_status = 'transcribing' THEN 1 ELSE 0 END) AS tx_active,
                SUM(CASE WHEN transcription_status = 'pending' THEN 1 ELSE 0 END) AS tx_pending,
                SUM(duration_seconds) AS total_duration,
                SUM(file_size_bytes) AS total_size
             FROM CG_TranscriptionSegments WHERE session_id = :id"
        );
        $segStmt->execute([':id' => $id]);
        $segSummary = $segStmt->fetch(PDO::FETCH_ASSOC);

        // Elapsed time
        $elapsed = 0;
        if ($session['actual_start_time']) {
            $start = new DateTime($session['actual_start_time']);
            $end = $session['end_time'] ? new DateTime($session['end_time']) : new DateTime();
            $elapsed = $end->getTimestamp() - $start->getTimestamp();
        }

        // Active segment started_at (for real-time progress bar)
        $activeSegStmt = $pdo->prepare(
            "SELECT started_at FROM CG_TranscriptionSegments
             WHERE session_id = :id AND recording_status = 'recording'
             ORDER BY segment_number DESC LIMIT 1"
        );
        $activeSegStmt->execute([':id' => $id]);
        $activeSeg = $activeSegStmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse([
            'session_id'        => (int) $session['session_id'],
            'status'            => $session['status'],
            'elapsed_sec'       => $elapsed,
            'segments'          => $segSummary,
            'segment_length_min'=> (int) $segLen,
            'active_seg_started'=> $activeSeg ? $activeSeg['started_at'] : null,
            'server_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/transcription/sessions/{id}/logs — Paginated log entries.
     */
    public function getSessionLogs(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));
        $level   = $_GET['level'] ?? null;

        $conditions = [
            ['clause' => 'session_id = :sid', 'param' => ':sid', 'value' => $id],
        ];
        if ($level) {
            $conditions[] = [
                'clause' => 'log_level = :level',
                'param'  => ':level',
                'value'  => $level,
            ];
        }

        $paged = buildPaginatedQuery(
            "SELECT * FROM CG_TranscriptionLogs",
            $conditions,
            'created_at DESC',
            $page,
            $perPage
        );

        $countStmt = $pdo->prepare($paged['countQuery']);
        $countStmt->execute($paged['params']);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = $pdo->prepare($paged['query']);
        $dataStmt->execute($paged['params']);
        $logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'data'  => $logs,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * GET /api/transcription/env-check — Check NAS capabilities.
     */
    public function envCheck(array $params = []): void
    {
        Auth::requireAdmin();
        $checks = [];

        // Python
        $pythonVersion = null;
        exec('python3 --version 2>&1', $pyOut, $pyRet);
        if ($pyRet === 0) {
            $pythonVersion = trim(implode(' ', $pyOut));
        } else {
            exec('python --version 2>&1', $pyOut2, $pyRet2);
            if ($pyRet2 === 0) {
                $pythonVersion = trim(implode(' ', $pyOut2));
            }
        }
        $checks['python'] = [
            'available' => $pythonVersion !== null,
            'version'   => $pythonVersion,
        ];

        // ffmpeg
        $ffmpegVersion = null;
        exec('ffmpeg -version 2>&1 | head -1', $ffOut, $ffRet);
        if ($ffRet === 0 && !empty($ffOut)) {
            $ffmpegVersion = trim($ffOut[0]);
        }
        $checks['ffmpeg'] = [
            'available' => $ffmpegVersion !== null,
            'version'   => $ffmpegVersion,
        ];

        // Whisper (Python module)
        $whisperAvailable = false;
        $whisperVersion = null;
        exec('python3 -c "import whisper; print(whisper.__version__)" 2>&1', $wOut, $wRet);
        if ($wRet === 0 && !empty($wOut)) {
            $whisperAvailable = true;
            $whisperVersion = trim($wOut[0]);
        }
        $checks['whisper'] = [
            'available' => $whisperAvailable,
            'version'   => $whisperVersion,
        ];

        // pymysql (bundled or system)
        $pymysqlAvailable = false;
        exec('python3 -c "import pymysql; print(pymysql.__version__)" 2>&1', $pmOut, $pmRet);
        if ($pmRet === 0 && !empty($pmOut)) {
            $pymysqlAvailable = true;
        }
        $checks['pymysql'] = [
            'available' => $pymysqlAvailable,
            'version'   => $pymysqlAvailable ? trim($pmOut[0]) : null,
        ];

        // Disk space
        $pdo = cg_db();
        $settings = $pdo->query("SELECT base_archive_dir, min_free_disk_gb FROM CG_TranscriptionSettings WHERE setting_id = 1")
                        ->fetch(PDO::FETCH_ASSOC);
        $archiveDir = $settings['base_archive_dir'] ?? '/volume1/auction_archive/';
        $minFreeGb = (int) ($settings['min_free_disk_gb'] ?? 5);

        $diskFreeBytes = @disk_free_space($archiveDir) ?: @disk_free_space('/volume1/');
        $diskFreeGb = $diskFreeBytes ? round($diskFreeBytes / 1073741824, 1) : null;
        $checks['disk'] = [
            'available'   => $diskFreeGb !== null,
            'free_gb'     => $diskFreeGb,
            'min_free_gb' => $minFreeGb,
            'sufficient'  => $diskFreeGb !== null && $diskFreeGb >= $minFreeGb,
            'path'        => $archiveDir,
        ];

        // CPU info
        $cpuCount = null;
        exec('nproc 2>/dev/null', $cpuOut, $cpuRet);
        if ($cpuRet === 0 && !empty($cpuOut)) {
            $cpuCount = (int) trim($cpuOut[0]);
        }
        $checks['cpu'] = [
            'available' => $cpuCount !== null,
            'cores'     => $cpuCount,
        ];

        // Tools directory
        $toolsDir = realpath(__DIR__ . '/../../tools');
        $checks['scripts'] = [
            'manager'          => file_exists($toolsDir . '/transcription_manager.py'),
            'recorder'         => file_exists($toolsDir . '/transcription_recorder.py'),
            'worker'           => file_exists($toolsDir . '/transcription_worker.py'),
            'browser_recorder' => is_dir($toolsDir . '/docker'),
            'tools_dir'        => $toolsDir,
        ];

        // Docker (required for browser_automation mode)
        // Note: PHP runs as http user which can't access Docker socket.
        // Check for Docker by looking for the binary and the build log.
        $dockerAvailable = file_exists('/usr/bin/docker') || file_exists('/usr/local/bin/docker');
        $dockerVersion = 'installed (run as root)';
        if (!$dockerAvailable) {
            exec('which docker 2>/dev/null', $dkWhich, $dkRet);
            $dockerAvailable = ($dkRet === 0);
        }
        $checks['docker'] = [
            'available' => $dockerAvailable,
            'version'   => $dockerAvailable ? $dockerVersion : null,
        ];

        // Browser recorder Docker image — check via build log (can't query Docker as http user)
        $buildLog = $toolsDir . '/docker_build.log';
        $imageAvailable = false;
        if (file_exists($buildLog)) {
            $logContent = file_get_contents($buildLog);
            if (strpos($logContent, 'Successfully tagged cg-browser-recorder:latest') !== false
                && strpos($logContent, 'EXIT_CODE=0') !== false) {
                $imageAvailable = true;
            }
        }
        $checks['browser_recorder_image'] = [
            'available' => $imageAvailable,
            'image'     => 'cg-browser-recorder:latest',
        ];

        jsonResponse(['checks' => $checks]);
    }

    // ─── Scheduler Tick (cron-triggered) ────────────────────────────

    /**
     * POST /api/transcription/scheduler-tick — Auto-start due sessions.
     * No auth required but protected by a shared secret key.
     * Called by cron: curl -X POST -d 'key=...' http://localhost/api/transcription/scheduler-tick
     */
    public function schedulerTick(array $params = []): void
    {
        // Verify scheduler key (simple shared secret)
        // Accept key from form data (curl -d) or JSON body
        $key = $_POST['key'] ?? '';
        if ($key === '') {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $body = json_decode($raw, true);
                $key = $body['key'] ?? '';
            }
        }
        if ($key !== 'cg_sched_2026') {
            jsonError('Forbidden', 403);
        }

        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "SELECT session_id, auction_name, scheduled_start, override_acquisition_mode
             FROM CG_TranscriptionSessions
             WHERE status = 'scheduled' AND scheduled_start <= NOW()
             ORDER BY scheduled_start ASC"
        );
        $stmt->execute();
        $dueSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dueSessions)) {
            jsonResponse(['message' => 'No sessions due', 'started' => 0]);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $managerScript = $toolsDir . '/transcription_manager.py';

        if (!file_exists($managerScript)) {
            jsonError('transcription_manager.py not found', 500);
        }

        // Find python binary
        $pythonBin = 'python3';
        exec('which python3 2>/dev/null', $testOut, $testRet);
        if ($testRet !== 0) {
            exec('which python 2>/dev/null', $testOut2, $testRet2);
            if ($testRet2 === 0) {
                $pythonBin = 'python';
            }
        }

        $globalSettings = $pdo->query("SELECT acquisition_mode FROM CG_TranscriptionSettings WHERE setting_id = 1")
                              ->fetch(PDO::FETCH_ASSOC);

        $started = [];
        foreach ($dueSessions as $session) {
            $id = (int) $session['session_id'];
            $lockFile = $toolsDir . '/transcription_session_' . $id . '.lock';
            $outputFile = $toolsDir . '/transcription_session_' . $id . '.out';

            if (file_exists($lockFile)) {
                continue;
            }

            $acqMode = $session['override_acquisition_mode'] ?: ($globalSettings['acquisition_mode'] ?? 'direct_stream');

            if ($acqMode === 'browser_automation') {
                // Write a start-request flag — the cron wrapper (root) will launch it
                $requestFile = $toolsDir . '/start_session_' . $id . '.request';
                file_put_contents($requestFile, date('Y-m-d H:i:s'));

                $pdo->prepare(
                    "UPDATE CG_TranscriptionSessions SET status = 'recording'
                     WHERE session_id = :id"
                )->execute([':id' => $id]);

                $this->insertLog($pdo, $id, 'info', 'auto_started',
                    'Session auto-started by scheduler (browser_automation — cron will launch)');
                $started[] = $id;
                continue;
            }

            // direct_stream — launch directly
            $cmd = 'touch ' . escapeshellarg($lockFile) . ' && '
                 . escapeshellcmd($pythonBin) . ' ' . escapeshellarg($managerScript) . ' --session-id ' . $id
                 . ' > ' . escapeshellarg($outputFile) . ' 2>&1'
                 . '; rm -f ' . escapeshellarg($lockFile);

            shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');

            $pdo->prepare(
                "UPDATE CG_TranscriptionSessions SET status = 'recording'
                 WHERE session_id = :id"
            )->execute([':id' => $id]);

            $this->insertLog($pdo, $id, 'info', 'auto_started', 'Session auto-started by scheduler');
            $started[] = $id;
        }

        jsonResponse(['message' => 'Scheduler tick complete', 'started' => $started]);
    }

    // ─── Retention Cleanup ─────────────────────────────────────

    /**
     * POST /api/transcription/cleanup — Delete expired sessions.
     * No auth required but protected by shared secret key.
     * Self-throttled to run at most once per hour.
     */
    public function cleanupExpired(array $params = []): void
    {
        $key = $_POST['key'] ?? '';
        if ($key === '') {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $body = json_decode($raw, true);
                $key = $body['key'] ?? '';
            }
        }
        if ($key !== 'cg_sched_2026') {
            jsonError('Forbidden', 403);
        }

        // Throttle: only run once per hour
        $toolsDir = realpath(__DIR__ . '/../../tools');
        $tsFile = $toolsDir . '/last_cleanup.timestamp';
        if (file_exists($tsFile) && (time() - filemtime($tsFile)) < 3600) {
            jsonResponse(['message' => 'Skipped — last cleanup less than 1 hour ago', 'cleaned' => 0]);
        }
        @touch($tsFile);

        $pdo = cg_db();

        // Get retention setting
        $settings = $pdo->query("SELECT audio_retention_days FROM CG_TranscriptionSettings WHERE setting_id = 1")
                        ->fetch(PDO::FETCH_ASSOC);
        $days = (int) ($settings['audio_retention_days'] ?? 30);

        if ($days < 1) {
            jsonResponse(['message' => 'Retention disabled (days < 1)', 'cleaned' => 0]);
        }

        // Find expired sessions (finished and older than retention period)
        $stmt = $pdo->prepare(
            "SELECT session_id, session_dir
             FROM CG_TranscriptionSessions
             WHERE status IN ('complete', 'stopped', 'error')
               AND end_time IS NOT NULL
               AND end_time < NOW() - INTERVAL :days DAY"
        );
        $stmt->execute([':days' => $days]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cleaned = 0;
        foreach ($expired as $session) {
            $id = (int) $session['session_id'];
            $dir = $session['session_dir'] ?? '';

            // Delete files on disk
            if ($dir !== '' && is_dir($dir)) {
                $this->removeDirectory($dir);
            }

            // Delete DB records
            $pdo->prepare("DELETE FROM CG_TranscriptionSegments WHERE session_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM CG_TranscriptionLogs WHERE session_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM CG_TranscriptionSessions WHERE session_id = :id")->execute([':id' => $id]);

            // Clean up signal/lock files
            foreach (['stop', 'cancel'] as $sig) {
                @unlink($toolsDir . '/transcription_' . $sig . '_' . $id . '.signal');
            }
            @unlink($toolsDir . '/transcription_session_' . $id . '.lock');

            $cleaned++;
        }

        jsonResponse(['message' => 'Cleanup complete', 'cleaned' => $cleaned, 'retention_days' => $days]);
    }

    // ─── Docker Build (temp utility) ────────────────────────────

    /**
     * POST /api/transcription/docker-build — Kick off Docker image build in background.
     */
    public function dockerBuild(array $params = []): void
    {
        $key = $_POST['key'] ?? '';
        if ($key === '') {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $body = json_decode($raw, true);
                $key = $body['key'] ?? '';
            }
        }
        if ($key !== 'cg_sched_2026') {
            jsonError('Forbidden', 403);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $dockerDir = $toolsDir . '/docker';
        $buildLog = $toolsDir . '/docker_build.log';

        if (!is_dir($dockerDir)) {
            jsonError('Docker directory not found', 500);
        }

        // Check if build is already running
        if (file_exists($toolsDir . '/docker_build.lock')) {
            jsonError('Build already in progress — check status endpoint', 409);
        }

        // Write a request flag — the cron wrapper (running as root) picks it up
        $requestFile = $toolsDir . '/docker_build_request';
        file_put_contents($requestFile, date('Y-m-d H:i:s'));

        jsonResponse(['message' => 'Docker build requested — cron will pick it up within 1 minute', 'log' => $buildLog]);
    }

    /**
     * GET /api/transcription/docker-build-status — Check build progress.
     */
    public function dockerBuildStatus(array $params = []): void
    {
        $key = $_GET['key'] ?? '';
        if ($key !== 'cg_sched_2026') {
            jsonError('Forbidden', 403);
        }

        $toolsDir = realpath(__DIR__ . '/../../tools');
        $buildLog = $toolsDir . '/docker_build.log';
        $lockFile = $toolsDir . '/docker_build.lock';

        $running = file_exists($lockFile);
        $log = file_exists($buildLog) ? file_get_contents($buildLog) : '';

        // Check if image exists
        $imageAvailable = false;
        $imgOut = [];
        exec('docker images cg-browser-recorder --format "{{.Repository}}:{{.Tag}}" 2>&1', $imgOut, $imgRet);
        if ($imgRet === 0) {
            foreach ($imgOut as $line) {
                if (strpos($line, 'cg-browser-recorder') !== false) {
                    $imageAvailable = true;
                    break;
                }
            }
        }

        // Get last 30 lines of log for display
        $logLines = explode("\n", $log);
        $tail = implode("\n", array_slice($logLines, -30));

        jsonResponse([
            'building'        => $running,
            'image_available'  => $imageAvailable,
            'log_tail'        => $tail,
            'log_lines_total' => count($logLines),
        ]);
    }

    // ─── Table Transcriptions (Parse transcript text → card records) ─────

    /**
     * POST /api/transcription/sessions/{id}/parse — Parse transcript text into card records.
     */
    public function parseSession(array $params = []): void
    {
        Auth::requireAdmin();
        $userId = Auth::getUserId();
        $sessionId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        // Verify session exists
        $stmt = $pdo->prepare("SELECT session_id, session_dir, status FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            jsonError('Session not found', 404);
        }

        // Load segment transcripts from disk
        $segStmt = $pdo->prepare(
            "SELECT segment_id, segment_number, filename_transcript,
                    started_at, duration_seconds
             FROM CG_TranscriptionSegments
             WHERE session_id = :id AND transcription_status = 'complete'
             ORDER BY segment_number ASC"
        );
        $segStmt->execute([':id' => $sessionId]);
        $segments = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($segments)) {
            jsonError('No completed transcript segments found for this session', 400);
        }

        // Read and concatenate transcript text
        $fullText = '';
        $segmentBoundaries = []; // [char_offset => segment_id, segment_number, timing]
        $sessionDir = rtrim($session['session_dir'], '/');

        foreach ($segments as $seg) {
            $filePath = $sessionDir . '/transcripts/' . $seg['filename_transcript'];
            if (!file_exists($filePath)) {
                continue;
            }
            $text = file_get_contents($filePath);
            if ($text === false || trim($text) === '') {
                continue;
            }

            $offset = strlen($fullText);
            $textLen = strlen($text) + 1; // +1 for the space we prepend
            $segmentBoundaries[] = [
                'offset'           => $offset,
                'text_length'      => $textLen,
                'segment_id'       => (int) $seg['segment_id'],
                'segment_number'   => (int) $seg['segment_number'],
                'started_at'       => $seg['started_at'],
                'duration_seconds' => (int) ($seg['duration_seconds'] ?? 0),
            ];

            $fullText .= ' ' . $text;
        }

        $fullText = trim($fullText);
        if (strlen($fullText) < 50) {
            jsonError('Transcript text too short to parse', 400);
        }

        // Create parse run
        $runStmt = $pdo->prepare(
            "INSERT INTO CG_TranscriptionParseRuns (session_id, status, run_by)
             VALUES (:sid, 'running', :uid)"
        );
        $runStmt->execute([':sid' => $sessionId, ':uid' => $userId]);
        $runId = (int) $pdo->lastInsertId();

        try {
            // Load reference data
            $refData = $this->loadReferenceData($pdo);

            // Run player-anchored extraction
            $records = $this->extractCardRecords($fullText, $refData, $segmentBoundaries);

            // Use transaction for bulk delete + insert (avoids per-row fsync)
            $pdo->beginTransaction();

            // Delete previous records for this session (fresh parse)
            $pdo->prepare("DELETE FROM CG_TranscriptionRecords WHERE session_id = :sid AND run_id != :rid")
                ->execute([':sid' => $sessionId, ':rid' => $runId]);

            // Insert records
            $insertStmt = $pdo->prepare(
                "INSERT INTO CG_TranscriptionRecords (
                    run_id, session_id, sequence_number,
                    player_id, team_id, maker_id, style_id, specialty_id,
                    raw_player, raw_team, raw_maker, raw_style, raw_specialty,
                    raw_parallel, raw_card_number,
                    lot_number, is_rookie, is_autograph, is_relic, is_giveaway,
                    confidence, raw_text_excerpt, segment_id, segment_number,
                    text_position, estimated_at
                ) VALUES (
                    :run_id, :session_id, :seq,
                    :player_id, :team_id, :maker_id, :style_id, :specialty_id,
                    :raw_player, :raw_team, :raw_maker, :raw_style, :raw_specialty,
                    :raw_parallel, :raw_card_number,
                    :lot_number, :is_rookie, :is_autograph, :is_relic, :is_giveaway,
                    :confidence, :excerpt, :segment_id, :segment_number,
                    :text_position, :estimated_at
                )"
            );

            $seq = 0;
            $highConf = 0;
            $lowConf = 0;
            foreach ($records as $rec) {
                $seq++;
                $conf = (float) $rec['confidence'];
                if ($conf >= 0.7) { $highConf++; } else { $lowConf++; }

                // Find segment for this text position and calculate estimated timestamp
                $segId = null;
                $segNum = null;
                $estimatedAt = null;
                foreach (array_reverse($segmentBoundaries) as $boundary) {
                    if ($rec['text_position'] >= $boundary['offset']) {
                        $segId = $boundary['segment_id'];
                        $segNum = $boundary['segment_number'];

                        // Interpolate timestamp within segment
                        if ($boundary['started_at'] && $boundary['duration_seconds'] > 0 && $boundary['text_length'] > 0) {
                            $posInSegment = $rec['text_position'] - $boundary['offset'];
                            $fraction = $posInSegment / $boundary['text_length'];
                            $fraction = max(0, min(1, $fraction)); // clamp 0-1
                            $offsetSec = (int) round($fraction * $boundary['duration_seconds']);
                            $ts = new \DateTime($boundary['started_at']);
                            $ts->modify("+{$offsetSec} seconds");
                            $estimatedAt = $ts->format('Y-m-d H:i:s');
                        }
                        break;
                    }
                }

                $insertStmt->execute([
                    ':run_id'       => $runId,
                    ':session_id'   => $sessionId,
                    ':seq'          => $seq,
                    ':player_id'    => $rec['player_id'],
                    ':team_id'      => $rec['team_id'],
                    ':maker_id'     => $rec['maker_id'],
                    ':style_id'     => $rec['style_id'],
                    ':specialty_id' => $rec['specialty_id'],
                    ':raw_player'   => $rec['raw_player'],
                    ':raw_team'     => $rec['raw_team'],
                    ':raw_maker'    => $rec['raw_maker'],
                    ':raw_style'    => $rec['raw_style'],
                    ':raw_specialty'=> $rec['raw_specialty'],
                    ':raw_parallel' => $rec['raw_parallel'],
                    ':raw_card_number' => $rec['raw_card_number'],
                    ':lot_number'   => $rec['lot_number'],
                    ':is_rookie'    => $rec['is_rookie'] ? 1 : 0,
                    ':is_autograph' => $rec['is_autograph'] ? 1 : 0,
                    ':is_relic'     => $rec['is_relic'] ? 1 : 0,
                    ':is_giveaway'  => $rec['is_giveaway'] ? 1 : 0,
                    ':confidence'   => $conf,
                    ':excerpt'      => $rec['excerpt'],
                    ':segment_id'   => $segId,
                    ':segment_number' => $segNum,
                    ':text_position' => $rec['text_position'],
                    ':estimated_at' => $estimatedAt,
                ]);
            }

            $pdo->commit();

            // Update parse run
            $pdo->prepare(
                "UPDATE CG_TranscriptionParseRuns
                 SET status = 'complete', total_records = :total,
                     high_confidence = :high, low_confidence = :low,
                     completed_at = NOW()
                 WHERE run_id = :rid"
            )->execute([
                ':total' => $seq,
                ':high'  => $highConf,
                ':low'   => $lowConf,
                ':rid'   => $runId,
            ]);

            jsonResponse([
                'run_id'          => $runId,
                'total_records'   => $seq,
                'high_confidence' => $highConf,
                'low_confidence'  => $lowConf,
            ]);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare(
                "UPDATE CG_TranscriptionParseRuns
                 SET status = 'error', error_message = :msg, completed_at = NOW()
                 WHERE run_id = :rid"
            )->execute([':msg' => $e->getMessage(), ':rid' => $runId]);

            jsonError('Parse failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transcription/sessions/{id}/records — List parsed card records.
     */
    public function listRecords(array $params = []): void
    {
        Auth::getUserId();
        $sessionId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $runId = !empty($_GET['run_id']) ? (int) $_GET['run_id'] : null;
        $minConf = isset($_GET['min_confidence']) ? (float) $_GET['min_confidence'] : null;

        // Default to latest run if not specified
        if (!$runId) {
            $latest = $pdo->prepare(
                "SELECT run_id FROM CG_TranscriptionParseRuns
                 WHERE session_id = :sid AND status = 'complete'
                 ORDER BY run_id DESC LIMIT 1"
            );
            $latest->execute([':sid' => $sessionId]);
            $row = $latest->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonResponse(['data' => [], 'total' => 0, 'run_id' => null]);
                return;
            }
            $runId = (int) $row['run_id'];
        }

        $sql = "SELECT r.*,
                    CONCAT(p.first_name, ' ', p.last_name) AS player_name,
                    t.team_name, t.abbreviation AS team_abbr, t.mlb_id AS team_mlb_id,
                    m.name AS maker_name,
                    s.style_name,
                    sp.name AS specialty_name
                FROM CG_TranscriptionRecords r
                LEFT JOIN CG_Players p ON p.player_id = r.player_id
                LEFT JOIN CG_Teams t ON t.team_id = r.team_id
                LEFT JOIN CG_CardMakers m ON m.maker_id = r.maker_id
                LEFT JOIN CG_CardStyles s ON s.style_id = r.style_id
                LEFT JOIN CG_CardSpecialties sp ON sp.specialty_id = r.specialty_id
                WHERE r.run_id = :rid AND r.session_id = :sid";

        $execParams = [':rid' => $runId, ':sid' => $sessionId];

        if ($minConf !== null) {
            $sql .= " AND r.confidence >= :minconf";
            $execParams[':minconf'] = $minConf;
        }

        $sql .= " ORDER BY r.sequence_number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($execParams);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'data'   => $records,
            'total'  => count($records),
            'run_id' => $runId,
        ]);
    }

    /**
     * PUT /api/transcription/records/{id} — Update a parsed record (inline edit).
     */
    public function updateRecord(array $params = []): void
    {
        Auth::requireAdmin();
        $userId = Auth::getUserId();
        $recordId = (int) ($params['id'] ?? 0);
        $body = getJsonBody();
        $pdo = cg_db();

        // Verify record exists
        $stmt = $pdo->prepare("SELECT record_id FROM CG_TranscriptionRecords WHERE record_id = :id");
        $stmt->execute([':id' => $recordId]);
        if (!$stmt->fetch()) {
            jsonError('Record not found', 404);
        }

        // Allowed update fields
        $allowed = [
            'player_id', 'team_id', 'maker_id', 'style_id', 'specialty_id',
            'raw_parallel', 'raw_card_number',
            'lot_number', 'is_rookie', 'is_autograph', 'is_relic', 'is_giveaway',
            'is_verified', 'notes',
        ];

        $sets = [];
        $execParams = [':id' => $recordId];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = :$field";
                $val = $body[$field];
                // Handle nullable FK fields
                if (in_array($field, ['player_id', 'team_id', 'maker_id', 'style_id', 'specialty_id', 'lot_number'])) {
                    $val = ($val === '' || $val === null) ? null : (int) $val;
                }
                if (in_array($field, ['is_rookie', 'is_autograph', 'is_relic', 'is_giveaway', 'is_verified'])) {
                    $val = $val ? 1 : 0;
                }
                $execParams[":$field"] = $val;
            }
        }

        if (empty($sets)) {
            jsonError('No valid fields to update', 400);
        }

        // Set verified_by when marking as verified
        if (isset($body['is_verified']) && $body['is_verified']) {
            $sets[] = "verified_by = :vby";
            $execParams[':vby'] = $userId;
        }

        $sql = "UPDATE CG_TranscriptionRecords SET " . implode(', ', $sets) . " WHERE record_id = :id";
        $pdo->prepare($sql)->execute($execParams);

        jsonResponse(['success' => true]);
    }

    /**
     * DELETE /api/transcription/records/{id} — Delete a parsed record.
     */
    public function deleteRecord(array $params = []): void
    {
        Auth::requireAdmin();
        $recordId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("DELETE FROM CG_TranscriptionRecords WHERE record_id = :id");
        $stmt->execute([':id' => $recordId]);

        if ($stmt->rowCount() === 0) {
            jsonError('Record not found', 404);
        }

        jsonResponse(['success' => true]);
    }

    /**
     * GET /api/transcription/sessions/{id}/transcript-text — Full concatenated transcript.
     */
    public function getTranscriptText(array $params = []): void
    {
        Auth::getUserId();
        $sessionId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare("SELECT session_dir FROM CG_TranscriptionSessions WHERE session_id = :id");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            jsonError('Session not found', 404);
        }

        $segStmt = $pdo->prepare(
            "SELECT segment_number, filename_transcript
             FROM CG_TranscriptionSegments
             WHERE session_id = :id AND transcription_status = 'complete'
             ORDER BY segment_number ASC"
        );
        $segStmt->execute([':id' => $sessionId]);
        $segments = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessionDir = rtrim($session['session_dir'], '/');
        $parts = [];
        foreach ($segments as $seg) {
            $filePath = $sessionDir . '/transcripts/' . $seg['filename_transcript'];
            if (!file_exists($filePath)) continue;
            $text = file_get_contents($filePath);
            if ($text === false || trim($text) === '') continue;
            $parts[] = [
                'segment_number' => (int) $seg['segment_number'],
                'text' => trim($text),
            ];
        }

        jsonResponse([
            'session_id' => $sessionId,
            'segments'   => $parts,
            'total_chars' => array_sum(array_map(fn($p) => strlen($p['text']), $parts)),
        ]);
    }

    /**
     * GET /api/transcription/sessions/{id}/parse-runs — List all parse runs for a session.
     */
    public function listParseRuns(array $params = []): void
    {
        Auth::getUserId();
        $sessionId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        $stmt = $pdo->prepare(
            "SELECT pr.*, u.display_name AS run_by_name
             FROM CG_TranscriptionParseRuns pr
             LEFT JOIN CG_Users u ON u.user_id = pr.run_by
             WHERE pr.session_id = :sid
             ORDER BY pr.run_id DESC"
        );
        $stmt->execute([':sid' => $sessionId]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(['data' => $runs]);
    }

    /**
     * POST /api/transcription/sessions/{id}/transcribe — Launch Whisper worker for pending segments.
     */
    public function transcribeSession(array $params = []): void
    {
        Auth::requireAdmin();
        $sessionId = (int) ($params['id'] ?? 0);
        $pdo = cg_db();

        // Verify session exists and get session_dir
        $stmt = $pdo->prepare(
            "SELECT session_id, session_dir, status FROM CG_TranscriptionSessions WHERE session_id = :id"
        );
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            jsonError('Session not found', 404);
        }

        // Reset skipped/error segments back to pending so they can be retried
        $reset = $pdo->prepare(
            "UPDATE CG_TranscriptionSegments
             SET transcription_status = 'pending', error_message = NULL
             WHERE session_id = :id AND recording_status = 'complete'
               AND transcription_status IN ('skipped', 'error')"
        );
        $reset->execute([':id' => $sessionId]);
        $resetCount = $reset->rowCount();

        // Count pending segments (includes freshly reset ones)
        $pending = $pdo->prepare(
            "SELECT COUNT(*) FROM CG_TranscriptionSegments
             WHERE session_id = :id AND recording_status = 'complete'
               AND transcription_status = 'pending'"
        );
        $pending->execute([':id' => $sessionId]);
        $pendingCount = (int) $pending->fetchColumn();

        if ($pendingCount === 0) {
            jsonError('No pending segments to transcribe', 400);
        }

        // Find Whisper python binary (venv first, then system)
        $toolsDir = realpath(__DIR__ . '/../../tools');
        $venvPy = $toolsDir . '/whisper_venv/bin/python3';
        $workerScript = $toolsDir . '/transcription_worker.py';

        if (!file_exists($workerScript)) {
            jsonError('transcription_worker.py not found', 500);
        }

        // Determine python binary (venv has Whisper installed)
        $pythonBin = file_exists($venvPy) ? $venvPy : null;
        if (!$pythonBin) {
            // Fallback: check system python
            exec('python3 -c "import whisper" 2>/dev/null', $out, $ret);
            if ($ret === 0) {
                $pythonBin = 'python3';
            }
        }
        if (!$pythonBin) {
            jsonError('Whisper not installed on server. Use the PC Worker instead.', 500);
        }

        // Pick Whisper model: request body > settings > 'base'
        $body = getJsonBody();
        $preferredModel = !empty($body['model']) ? $body['model'] : null;
        if (!$preferredModel) {
            $settings = $pdo->query(
                "SELECT whisper_model FROM CG_TranscriptionSettings WHERE setting_id = 1"
            )->fetch(PDO::FETCH_ASSOC);
            $preferredModel = $settings['whisper_model'] ?? 'base';
        }
        $modelCache = $toolsDir . '/whisper_models';

        // Check if preferred model is available, otherwise use what we have
        $model = $preferredModel;
        if (is_dir($modelCache) && !file_exists($modelCache . '/' . $preferredModel . '.pt')) {
            // Find any available model, prefer larger ones
            $available = [];
            foreach (['large', 'medium', 'small', 'base', 'tiny'] as $m) {
                if (file_exists($modelCache . '/' . $m . '.pt')) {
                    $available[] = $m;
                }
            }
            $model = !empty($available) ? $available[0] : 'base';
        }

        // Launch worker in background
        $lockFile = $toolsDir . '/transcription_session_' . $sessionId . '.lock';
        $outputFile = $toolsDir . '/transcription_session_' . $sessionId . '.out';

        if (file_exists($lockFile)) {
            jsonError('Transcription already in progress for this session', 409);
        }

        $cmd = $pythonBin . ' ' . escapeshellarg($workerScript)
             . ' --session-id ' . $sessionId
             . ' --session-dir ' . escapeshellarg($session['session_dir'])
             . ' --model ' . escapeshellarg($model)
             . ' > ' . escapeshellarg($outputFile) . ' 2>&1'
             . '; rm -f ' . escapeshellarg($lockFile);

        shell_exec('touch ' . escapeshellarg($lockFile));
        shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');

        $this->insertLog($pdo, $sessionId, 'info', 'transcribe_triggered',
            "Manual transcription triggered ($pendingCount pending segments, model: $model)");

        jsonResponse([
            'ok'             => true,
            'pending_count'  => $pendingCount,
            'model'          => $model,
            'message'        => "Transcription started for $pendingCount pending segment(s)",
        ]);
    }

    // ─── Parsing Engine Helpers ──────────────────────────────────

    /**
     * Load all reference data for fuzzy matching.
     */
    private function loadReferenceData(PDO $pdo): array
    {
        // Players: all active + inactive with popularity (catches retired stars like Kershaw)
        $players = $pdo->query(
            "SELECT p.player_id, p.first_name, p.last_name,
                    p.current_team_id AS team_id,
                    COALESCE(p.popularity_score, 0) AS popularity
             FROM CG_Players p
             WHERE p.is_active = 1 OR p.popularity_score > 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $nicknames = $pdo->query(
            "SELECT n.player_id, n.nickname FROM CG_PlayerNicknames n"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Common words that appear in auction talk but are also player last names.
        // These are excluded from single-word last-name matching to reduce false positives.
        $lastNameStopWords = array_flip([
            'black', 'green', 'blue', 'gold', 'rose', 'white', 'brown', 'gray', 'grey',
            'long', 'short', 'young', 'best', 'king', 'love', 'cash', 'ball', 'page',
            'hope', 'holiday', 'price', 'rich', 'hand', 'hall', 'bell', 'lamb',
            'field', 'reed', 'dean', 'dale', 'lane', 'star', 'ford',
        ]);

        // Name suffixes to strip for alternate indexing (e.g. "Harris II" → also index "Harris")
        $nameSuffixes = [' ii', ' iii', ' iv', ' jr', ' jr.', ' sr', ' sr.'];

        // Build name dictionary: normalized_name => [player_id, team_id, match_type, popularity]
        $nameDict = [];
        foreach ($players as $p) {
            $first = mb_strtolower(trim($p['first_name']));
            $last  = mb_strtolower(trim($p['last_name']));
            $full  = $first . ' ' . $last;
            $pop   = (int) $p['popularity'];
            $entry = [
                'player_id'  => (int) $p['player_id'],
                'team_id'    => $p['team_id'] ? (int) $p['team_id'] : null,
                'display'    => $p['first_name'] . ' ' . $p['last_name'],
                'popularity' => $pop,
            ];

            // Full name: prefer higher popularity when names collide
            if (!isset($nameDict[$full]) || $pop > ($nameDict[$full]['popularity'] ?? 0)) {
                $nameDict[$full] = $entry + ['type' => 'full'];
            }

            // Also index without suffix (e.g. "michael harris" for "Michael Harris II")
            $lastBase = $last;
            foreach ($nameSuffixes as $sfx) {
                if (str_ends_with($last, $sfx)) {
                    $lastBase = rtrim(substr($last, 0, -strlen($sfx)));
                    $altFull = $first . ' ' . $lastBase;
                    if (!isset($nameDict[$altFull]) || $pop > ($nameDict[$altFull]['popularity'] ?? 0)) {
                        $nameDict[$altFull] = $entry + ['type' => 'full'];
                    }
                    break;
                }
            }

            // Last name index: skip stop words, prefer highest popularity
            if (strlen($lastBase) >= 4 && !isset($lastNameStopWords[$lastBase])) {
                $key = '_last_' . $lastBase;
                if (!isset($nameDict[$key]) || $pop > ($nameDict[$key]['popularity'] ?? 0)) {
                    $nameDict[$key] = $entry + ['type' => 'last'];
                }
            }

            // First name index for popular players — catches cases where Whisper
            // mangles the last name but first name is distinctive (e.g. "Clayton Curzong" → Kershaw)
            if ($pop > 0 && strlen($first) >= 4 && !isset($lastNameStopWords[$first])) {
                $key = '_last_' . $first;
                if (!isset($nameDict[$key]) || $pop > ($nameDict[$key]['popularity'] ?? 0)) {
                    $nameDict[$key] = $entry + ['type' => 'last'];
                }
            }
        }

        // Add nicknames
        $nicknameMap = [];
        foreach ($nicknames as $n) {
            $nicknameMap[mb_strtolower(trim($n['nickname']))] = (int) $n['player_id'];
        }

        // Makers
        $makers = [];
        foreach ($pdo->query("SELECT maker_id, name FROM CG_CardMakers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $makers[mb_strtolower($m['name'])] = (int) $m['maker_id'];
        }
        // Common Whisper misspellings for makers
        $makerAliases = [
            'tops'     => 'topps',
            'topped'   => 'topps',
            "topped's" => 'topps',
            "topp's"   => 'topps',
            'topscale' => 'topps',
            'bowmen'   => 'bowman',
            'bow man'  => 'bowman',
            'donrus'   => 'donruss',
            'panany'   => 'panini',
            'pennini'  => 'panini',
        ];

        // Styles
        $styles = [];
        foreach ($pdo->query("SELECT style_id, style_name FROM CG_CardStyles WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $styles[mb_strtolower($s['style_name'])] = (int) $s['style_id'];
        }
        // Common Whisper misspellings for styles
        $styleAliases = [
            'chroma'    => 'chrome',
            'krome'     => 'chrome',
            'saphire'   => 'sapphire',
            'saphier'   => 'sapphire',
            'prism'     => 'prizm',
            'prison'    => 'prizm',
        ];

        // Specialties
        $specialties = [];
        foreach ($pdo->query("SELECT specialty_id, name FROM CG_CardSpecialties WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            $specialties[mb_strtolower($sp['name'])] = (int) $sp['specialty_id'];
        }

        // Teams (for team_id lookup by abbreviation/name)
        $teams = [];
        foreach ($pdo->query("SELECT team_id, team_name, abbreviation FROM CG_Teams WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if ($t['abbreviation']) {
                $teams[mb_strtolower($t['abbreviation'])] = (int) $t['team_id'];
            }
            $teams[mb_strtolower($t['team_name'])] = (int) $t['team_id'];
        }

        // Build prefix index for fuzzy matching (first 2 chars of first name → list of full names)
        $prefixIndex = [];
        foreach ($nameDict as $name => $entry) {
            if ($entry['type'] !== 'full') continue;
            $prefix = substr($name, 0, 2);
            if (!isset($prefixIndex[$prefix])) {
                $prefixIndex[$prefix] = [];
            }
            $prefixIndex[$prefix][$name] = $entry;
        }

        // Build player lookup by ID for fast nickname resolution
        $playerById = [];
        foreach ($players as $p) {
            $playerById[(int)$p['player_id']] = $p;
        }

        return [
            'nameDict'       => $nameDict,
            'nicknameMap'    => $nicknameMap,
            'players'        => $players,
            'playerById'     => $playerById,
            'prefixIndex'    => $prefixIndex,
            'makers'         => $makers,
            'makerAliases'   => $makerAliases,
            'styles'         => $styles,
            'styleAliases'   => $styleAliases,
            'specialties'    => $specialties,
            'teams'          => $teams,
        ];
    }

    /**
     * Extract card records from transcript text using player-anchored approach.
     */
    private function extractCardRecords(string $fullText, array $ref, array $segBounds): array
    {
        $textLower = mb_strtolower($fullText);
        $textLen = strlen($fullText);

        // Tokenize into words with positions
        $words = [];
        preg_match_all('/[a-zA-Z\'\-]+/', $textLower, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $m) {
            $words[] = ['word' => $m[0], 'pos' => $m[1]];
        }

        $wordCount = count($words);
        $playerMatches = [];

        // Scan for player name matches (2-word and 3-word windows)
        for ($i = 0; $i < $wordCount; $i++) {
            // Try 2-word combo (first last)
            if ($i + 1 < $wordCount) {
                $twoWord = $words[$i]['word'] . ' ' . $words[$i + 1]['word'];
                $match = $this->matchPlayerName($twoWord, $ref, $words[$i]['pos']);
                if ($match) {
                    $playerMatches[] = $match;
                    $i++; // skip next word
                    continue;
                }
            }

            // Try 3-word combo (for names like "Juan De Leon")
            if ($i + 2 < $wordCount) {
                $threeWord = $words[$i]['word'] . ' ' . $words[$i + 1]['word'] . ' ' . $words[$i + 2]['word'];
                $match = $this->matchPlayerName($threeWord, $ref, $words[$i]['pos']);
                if ($match) {
                    $playerMatches[] = $match;
                    $i += 2;
                    continue;
                }
            }

            // Try single word (last name match for 4+ char names)
            if (strlen($words[$i]['word']) >= 4) {
                $key = '_last_' . $words[$i]['word'];
                if (isset($ref['nameDict'][$key])) {
                    $entry = $ref['nameDict'][$key];
                    $playerMatches[] = [
                        'player_id'  => $entry['player_id'],
                        'team_id'    => $entry['team_id'],
                        'raw_player' => $entry['display'],
                        'position'   => $words[$i]['pos'],
                        'score'      => 0.7,  // last-name-only match
                    ];
                }
                // Check nicknames
                if (isset($ref['nicknameMap'][$words[$i]['word']])) {
                    $pid = $ref['nicknameMap'][$words[$i]['word']];
                    if (isset($ref['playerById'][$pid])) {
                        $p = $ref['playerById'][$pid];
                        $playerMatches[] = [
                            'player_id'  => $pid,
                            'team_id'    => $p['team_id'] ? (int)$p['team_id'] : null,
                            'raw_player' => $p['first_name'] . ' ' . $p['last_name'],
                            'position'   => $words[$i]['pos'],
                            'score'      => 0.9,
                        ];
                    }
                }
            }
        }

        // Deduplicate: if same player within 100 chars, keep the one with higher score
        $deduped = [];
        foreach ($playerMatches as $match) {
            $dominated = false;
            foreach ($deduped as $k => $existing) {
                if ($existing['player_id'] === $match['player_id']
                    && abs($existing['position'] - $match['position']) < 100) {
                    // Keep better one
                    if ($match['score'] > $existing['score']) {
                        $deduped[$k] = $match;
                    }
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $deduped[] = $match;
            }
        }

        // Sort by position
        usort($deduped, fn($a, $b) => $a['position'] - $b['position']);

        // For each player match, extract context and build record
        $records = [];
        $currentLot = null;

        foreach ($deduped as $match) {
            $pos = $match['position'];

            // Context window: 200 chars before and after
            $ctxStart = max(0, $pos - 200);
            $ctxEnd = min($textLen, $pos + 200);
            $context = substr($fullText, $ctxStart, $ctxEnd - $ctxStart);
            $contextLower = mb_strtolower($context);

            // Extract card attributes from context
            $rec = [
                'player_id'    => $match['player_id'],
                'team_id'      => $match['team_id'],
                'raw_player'   => $match['raw_player'],
                'raw_team'     => null,
                'maker_id'     => null,
                'raw_maker'    => null,
                'style_id'     => null,
                'raw_style'    => null,
                'specialty_id' => null,
                'raw_specialty'=> null,
                'raw_parallel' => null,
                'raw_card_number' => null,
                'lot_number'   => null,
                'is_rookie'    => false,
                'is_autograph' => false,
                'is_relic'     => false,
                'is_giveaway'  => false,
                'confidence'   => 0.30,  // base: player matched
                'excerpt'      => preg_replace('/[\x80-\xFF]/', '', $context),
                'text_position'=> $pos,
            ];

            // --- Maker detection ---
            foreach ($ref['makers'] as $name => $id) {
                if (strpos($contextLower, $name) !== false) {
                    $rec['maker_id'] = $id;
                    $rec['raw_maker'] = $name;
                    break;
                }
            }
            if (!$rec['maker_id']) {
                foreach ($ref['makerAliases'] as $alias => $canonical) {
                    if (strpos($contextLower, $alias) !== false && isset($ref['makers'][$canonical])) {
                        $rec['maker_id'] = $ref['makers'][$canonical];
                        $rec['raw_maker'] = $alias . ' → ' . $canonical;
                        break;
                    }
                }
            }

            // --- Style detection ---
            foreach ($ref['styles'] as $name => $id) {
                if (strpos($contextLower, $name) !== false) {
                    $rec['style_id'] = $id;
                    $rec['raw_style'] = $name;
                    break;
                }
            }
            if (!$rec['style_id']) {
                foreach ($ref['styleAliases'] as $alias => $canonical) {
                    if (strpos($contextLower, $alias) !== false && isset($ref['styles'][$canonical])) {
                        $rec['style_id'] = $ref['styles'][$canonical];
                        $rec['raw_style'] = $alias . ' → ' . $canonical;
                        break;
                    }
                }
            }

            // --- Specialty detection ---
            foreach ($ref['specialties'] as $name => $id) {
                if (strpos($contextLower, $name) !== false) {
                    $rec['specialty_id'] = $id;
                    $rec['raw_specialty'] = $name;
                    break;
                }
            }

            // --- Attribute flags ---
            if (preg_match('/\brookie\b/', $contextLower)) {
                $rec['is_rookie'] = true;
            }
            if (preg_match('/\b(autograph|auto(?:graph)?|signed)\b/', $contextLower)) {
                $rec['is_autograph'] = true;
            }
            if (preg_match('/\b(relic|game[\s-]?used|patch|jersey|memorabilia)\b/', $contextLower)) {
                $rec['is_relic'] = true;
            }
            if (preg_match('/\b(give\s*away|giveaway)\b/', $contextLower)) {
                $rec['is_giveaway'] = true;
            }

            // --- Parallel/color detection ---
            $colors = ['blue', 'gold', 'red', 'green', 'black', 'pink', 'purple', 'orange',
                       'silver', 'white', 'yellow', 'aqua', 'teal', 'platinum', 'sapphire'];
            foreach ($colors as $color) {
                if (preg_match('/\b' . $color . '\b/', $contextLower)) {
                    $rec['raw_parallel'] = $color;
                    break;
                }
            }

            // --- Card numbering ---
            if (preg_match('/(?:number(?:ed)?|#)\s*(?:to\s+)?(\d{1,4})\s*(?:\/\s*(\d{1,4}))?/', $contextLower, $numMatch)) {
                $rec['raw_card_number'] = $numMatch[0];
            } elseif (preg_match('/(?:to|of)\s+(\d{1,4})\b/', $contextLower, $numMatch)) {
                // "to 199", "to 25"
                $num = (int) $numMatch[1];
                if ($num > 0 && $num <= 9999 && !in_array($num, [1, 2, 3, 4, 5])) {
                    $rec['raw_card_number'] = '/' . $num;
                }
            } elseif (preg_match('/\/\s*(\d{1,4})\b/', $contextLower, $numMatch)) {
                $rec['raw_card_number'] = $numMatch[0];
            }

            // --- Lot number ---
            if (preg_match('/\b(?:lot)\s*(?:#|number)?\s*(\d{1,5})\b/', $contextLower, $lotMatch)) {
                $currentLot = (int) $lotMatch[1];
            }
            // Check for standalone number patterns that look like lot announcements
            // e.g., "one seventy", "171", "lot 172"
            if (preg_match('/\b(\d{2,5})\b/', $contextLower, $numOnly)) {
                $n = (int) $numOnly[1];
                // Only treat as lot number if it's in a plausible range
                if ($n >= 100 && $n <= 99999 && $currentLot !== null && abs($n - $currentLot) <= 5) {
                    $currentLot = $n;
                }
            }

            $rec['lot_number'] = $currentLot;

            // --- Confidence scoring ---
            $conf = 0.30;  // base: player matched
            $conf += $match['score'] * 0.1; // quality of player match
            if ($rec['maker_id'] || $rec['style_id']) { $conf += 0.20; }
            if ($rec['specialty_id'] || $rec['raw_parallel']) { $conf += 0.10; }
            if ($rec['lot_number']) { $conf += 0.10; }
            if ($rec['raw_card_number']) { $conf += 0.10; }
            if ($rec['is_rookie'] || $rec['is_autograph'] || $rec['is_relic']) { $conf += 0.05; }
            $rec['confidence'] = min(1.00, round($conf, 2));

            $records[] = $rec;
        }

        return $records;
    }

    /**
     * Try to match a text fragment against the player name dictionary.
     * Returns match info or null.
     */
    private function matchPlayerName(string $text, array $ref, int $position): ?array
    {
        $textLower = mb_strtolower(trim($text));

        // Exact match
        if (isset($ref['nameDict'][$textLower])) {
            $entry = $ref['nameDict'][$textLower];
            if ($entry['type'] === 'full') {
                return [
                    'player_id'  => $entry['player_id'],
                    'team_id'    => $entry['team_id'],
                    'raw_player' => $entry['display'],
                    'position'   => $position,
                    'score'      => 1.0,
                ];
            }
        }

        // Nickname exact match
        if (isset($ref['nicknameMap'][$textLower])) {
            $pid = $ref['nicknameMap'][$textLower];
            if (isset($ref['playerById'][$pid])) {
                $p = $ref['playerById'][$pid];
                return [
                    'player_id'  => $pid,
                    'team_id'    => $p['team_id'] ? (int)$p['team_id'] : null,
                    'raw_player' => $p['first_name'] . ' ' . $p['last_name'],
                    'position'   => $position,
                    'score'      => 0.9,
                ];
            }
        }

        // Fuzzy matching disabled for now (too slow with 4500+ players).
        // Exact + last-name + nickname matching covers most cases.
        // TODO: Add targeted fuzzy pass on unmatched regions in v2.

        return null;
    }

    // ─── Private Helpers ──────────────────────────────────────────

    /**
     * Insert a log entry for a session.
     */
    private function insertLog(PDO $pdo, int $sessionId, string $level, string $eventType, string $message): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message)
             VALUES (:sid, :level, :event, :msg)"
        );
        $stmt->execute([
            ':sid'   => $sessionId,
            ':level' => $level,
            ':event' => $eventType,
            ':msg'   => $message,
        ]);
    }
}
