<?php
/**
 * Card Graph — Transcription Scheduler (Cron Job)
 *
 * Checks for sessions with status='scheduled' whose scheduled_start <= NOW(),
 * and launches the transcription manager for each one.
 *
 * Setup on Synology:
 *   DSM → Control Panel → Task Scheduler → Create → Scheduled Task → User-defined Script
 *   - User: root (or http if it has shell access)
 *   - Schedule: Every 1 minute
 *   - Command: php /volume1/web/cardgraph/tools/transcription_scheduler.php
 *
 * Can also be run manually: php /volume1/web/cardgraph/tools/transcription_scheduler.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

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
    exit(0);
}

$toolsDir = realpath(__DIR__);
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
            // Mark as error so it doesn't retry every minute
            $pdo->prepare(
                "UPDATE CG_TranscriptionSessions SET status = 'error', stop_reason = 'Docker not available'
                 WHERE session_id = :id"
            )->execute([':id' => $id]);
            continue;
        }
    }

    // Launch manager via nohup (same pattern as TranscriptionController::startSession)
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
