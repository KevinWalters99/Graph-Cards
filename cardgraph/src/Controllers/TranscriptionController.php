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
            'whisper_model'    => ['tiny', 'base'],
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
            'session'  => $session,
            'segments' => $segments,
            'logs'     => $logs,
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

        // Reset status back to scheduled when editing an error/completed/cancelled session
        $resetStatus = ($session['status'] !== 'scheduled') ? 'scheduled' : null;

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
                 SET status = 'recording', actual_start_time = NOW()
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
             SET status = 'recording', actual_start_time = NOW()
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
                    total_segments, total_duration_sec
             FROM CG_TranscriptionSessions WHERE session_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            jsonError('Session not found', 404);
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

        jsonResponse([
            'session_id'    => (int) $session['session_id'],
            'status'        => $session['status'],
            'elapsed_sec'   => $elapsed,
            'segments'      => $segSummary,
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
                    "UPDATE CG_TranscriptionSessions SET status = 'recording', actual_start_time = NOW()
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
                "UPDATE CG_TranscriptionSessions SET status = 'recording', actual_start_time = NOW()
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
