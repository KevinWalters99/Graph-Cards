<?php
require_once __DIR__ . '/../src/bootstrap.php';
header('Content-Type: text/plain');

$pdo = cg_db();
$results = [];

// Test 1: Settings exist
$row = $pdo->query("SELECT * FROM CG_TranscriptionSettings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC);
$results[] = $row ? "OK: Settings row exists (segment_length={$row['segment_length_minutes']}, whisper_model={$row['whisper_model']})" : "ERR: Settings row missing";

// Test 2: Sessions table exists
try {
    $pdo->query("SELECT COUNT(*) FROM CG_TranscriptionSessions")->fetchColumn();
    $results[] = "OK: CG_TranscriptionSessions accessible";
} catch (Exception $e) {
    $results[] = "ERR: " . $e->getMessage();
}

// Test 3: Segments table exists
try {
    $pdo->query("SELECT COUNT(*) FROM CG_TranscriptionSegments")->fetchColumn();
    $results[] = "OK: CG_TranscriptionSegments accessible";
} catch (Exception $e) {
    $results[] = "ERR: " . $e->getMessage();
}

// Test 4: Logs table exists
try {
    $pdo->query("SELECT COUNT(*) FROM CG_TranscriptionLogs")->fetchColumn();
    $results[] = "OK: CG_TranscriptionLogs accessible";
} catch (Exception $e) {
    $results[] = "ERR: " . $e->getMessage();
}

// Test 5: Round-trip session create + read + delete
try {
    $pdo->exec("INSERT INTO CG_TranscriptionSessions (auction_name, auction_url, scheduled_start, created_by)
                VALUES ('Test Auction', 'https://example.com', '2026-03-01 19:00:00', 1)");
    $newId = $pdo->lastInsertId();
    $check = $pdo->query("SELECT session_id, auction_name, status FROM CG_TranscriptionSessions WHERE session_id = {$newId}")->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("DELETE FROM CG_TranscriptionSessions WHERE session_id = {$newId}");
    $results[] = $check ? "OK: Round-trip session (id={$newId}, name={$check['auction_name']}, status={$check['status']})" : "ERR: Session not found after insert";
} catch (Exception $e) {
    $results[] = "ERR: Session round-trip: " . $e->getMessage();
}

// Test 6: TranscriptionController autoload
try {
    $ctrl = new TranscriptionController();
    $results[] = "OK: TranscriptionController class loads";
} catch (Error $e) {
    $results[] = "ERR: TranscriptionController: " . $e->getMessage();
}

echo "Migration 007 Verification:\n\n";
echo implode("\n", $results) . "\n";
