<?php
/**
 * Card Graph — Scheduler Tick (HTTP-triggered)
 *
 * Called by the cron wrapper via: curl http://localhost/scheduler_tick.php
 * Only accepts requests from localhost.
 */

// Restrict to localhost only
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain');

$pdo = cg_db();

// Find sessions that are due to start
$stmt = $pdo->prepare(
    "SELECT session_id, auction_name, scheduled_start, override_acquisition_mode
     FROM CG_TranscriptionSessions
     WHERE status = 'scheduled' AND scheduled_start <= NOW()
     ORDER BY scheduled_start ASC"
);
$stmt->execute();
$dueSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($dueSessions)) {
    echo "No sessions due\n";
    exit(0);
}

$toolsDir = realpath(__DIR__ . '/../tools');
$managerScript = $toolsDir . '/transcription_manager.py';

if (!file_exists($managerScript)) {
    echo "ERROR: transcription_manager.py not found at {$managerScript}\n";
    exit(1);
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

// Check for Docker pre-flight (for browser_automation mode)
$globalSettings = $pdo->query("SELECT acquisition_mode FROM CG_TranscriptionSettings WHERE setting_id = 1")
                      ->fetch(PDO::FETCH_ASSOC);

foreach ($dueSessions as $session) {
    $id = (int) $session['session_id'];
    $lockFile = $toolsDir . '/transcription_session_' . $id . '.lock';
    $outputFile = $toolsDir . '/transcription_session_' . $id . '.out';

    // Skip if already running
    if (file_exists($lockFile)) {
        echo "Session {$id} skipped (lock file exists)\n";
        continue;
    }

    // Determine acquisition mode
    $acqMode = $session['override_acquisition_mode'] ?: ($globalSettings['acquisition_mode'] ?? 'direct_stream');

    // Pre-flight check for browser_automation
    if ($acqMode === 'browser_automation') {
        exec('docker info 2>&1', $dkOut, $dkRet);
        if ($dkRet !== 0) {
            echo "Session {$id} skipped (Docker not available for browser_automation)\n";
            $pdo->prepare(
                "UPDATE CG_TranscriptionSessions SET status = 'error', stop_reason = 'Docker not available'
                 WHERE session_id = :id"
            )->execute([':id' => $id]);
            continue;
        }
    }

    // Launch manager via nohup
    $cmd = 'touch ' . escapeshellarg($lockFile) . ' && '
         . escapeshellcmd($pythonBin) . ' ' . escapeshellarg($managerScript) . ' --session-id ' . $id
         . ' > ' . escapeshellarg($outputFile) . ' 2>&1'
         . '; rm -f ' . escapeshellarg($lockFile);

    shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');

    // Update session status
    $pdo->prepare(
        "UPDATE CG_TranscriptionSessions SET status = 'recording', actual_start_time = NOW()
         WHERE session_id = :id"
    )->execute([':id' => $id]);

    // Log the auto-start
    $pdo->prepare(
        "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message)
         VALUES (:id, 'info', 'auto_started', 'Session auto-started by scheduler')"
    )->execute([':id' => $id]);

    echo "Session {$id} ({$session['auction_name']}) auto-started\n";
}
