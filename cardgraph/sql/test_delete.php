<?php
require_once __DIR__ . '/../src/bootstrap.php';
header('Content-Type: text/plain');

$pdo = cg_db();

// List all sessions
echo "=== Current Sessions ===\n";
$rows = $pdo->query("SELECT session_id, status, session_dir FROM CG_TranscriptionSessions ORDER BY session_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $segs = $pdo->prepare("SELECT COUNT(*) FROM CG_TranscriptionSegments WHERE session_id = ?");
    $segs->execute([$r['session_id']]);
    $segCount = $segs->fetchColumn();
    $logs = $pdo->prepare("SELECT COUNT(*) FROM CG_TranscriptionLogs WHERE session_id = ?");
    $logs->execute([$r['session_id']]);
    $logCount = $logs->fetchColumn();
    echo "  ID={$r['session_id']} status={$r['status']} segments={$segCount} logs={$logCount} dir={$r['session_dir']}\n";
}

// Try to delete the oldest one (if any)
if (!empty($rows)) {
    $id = $rows[0]['session_id'];
    $dir = $rows[0]['session_dir'];
    echo "\n=== Deleting session {$id} ===\n";

    // Check if dir exists
    if ($dir && is_dir($dir)) {
        echo "Session dir exists: {$dir}\n";
    } else {
        echo "Session dir does not exist or is null: " . var_export($dir, true) . "\n";
    }

    // Delete
    $pdo->prepare("DELETE FROM CG_TranscriptionSegments WHERE session_id = ?")->execute([$id]);
    echo "Segments deleted\n";
    $pdo->prepare("DELETE FROM CG_TranscriptionLogs WHERE session_id = ?")->execute([$id]);
    echo "Logs deleted\n";
    $pdo->prepare("DELETE FROM CG_TranscriptionSessions WHERE session_id = ?")->execute([$id]);
    echo "Session deleted\n";

    // Verify
    $check = $pdo->prepare("SELECT COUNT(*) FROM CG_TranscriptionSessions WHERE session_id = ?");
    $check->execute([$id]);
    echo "Verify (should be 0): " . $check->fetchColumn() . "\n";
}
