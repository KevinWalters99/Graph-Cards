<?php
header('Content-Type: text/plain');

// Check who we're running as
echo "Web server user: " . exec('whoami') . "\n";
echo "Web server groups: " . exec('id') . "\n\n";

// Check if /volume1/auction_archive exists
$dir = '/volume1/auction_archive';
echo "Directory exists: " . (is_dir($dir) ? 'yes' : 'no') . "\n";
echo "Parent /volume1/ writable: " . (is_writable('/volume1/') ? 'yes' : 'no') . "\n";

// Try creating under a path the web server should have access to
$altDir = '/volume1/web/cardgraph/archive';
echo "\nAlternative: /volume1/web/cardgraph/archive\n";
echo "Parent writable: " . (is_writable('/volume1/web/cardgraph/') ? 'yes' : 'no') . "\n";

if (!is_dir($altDir)) {
    $ok = @mkdir($altDir, 0775, true);
    echo "mkdir result: " . ($ok ? 'success' : 'failed') . "\n";
} else {
    echo "Already exists\n";
}
echo "Writable: " . (is_writable($altDir) ? 'yes' : 'no') . "\n";

// Also try the original path
if (!is_dir($dir)) {
    $ok = @mkdir($dir, 0775, true);
    echo "\nmkdir $dir: " . ($ok ? 'success' : 'failed (' . error_get_last()['message'] . ')') . "\n";
}
