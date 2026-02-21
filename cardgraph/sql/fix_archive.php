<?php
require_once __DIR__ . '/../src/bootstrap.php';
header('Content-Type: text/plain');

$pdo = cg_db();

// Update default archive dir to writable location
$stmt = $pdo->prepare(
    "UPDATE CG_TranscriptionSettings SET base_archive_dir = :dir WHERE setting_id = 1"
);
$stmt->execute([':dir' => '/volume1/web/cardgraph/archive/']);

echo "Updated base_archive_dir to /volume1/web/cardgraph/archive/\n";

// Verify
$row = $pdo->query("SELECT base_archive_dir FROM CG_TranscriptionSettings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC);
echo "Current value: " . $row['base_archive_dir'] . "\n";

// Also reset the errored session so it can be retried
$stmt = $pdo->prepare(
    "UPDATE CG_TranscriptionSessions SET status = 'scheduled', actual_start_time = NULL, end_time = NULL, stop_reason = NULL
     WHERE status = 'error'"
);
$stmt->execute();
echo "Reset " . $stmt->rowCount() . " errored session(s) back to 'scheduled'\n";
