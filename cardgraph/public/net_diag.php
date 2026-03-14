<?php
header('Content-Type: text/plain');

// If ?fix=1 is passed, attempt to add the default gateway
if (isset($_GET['fix'])) {
    echo "=== Attempting Gateway Fix ===\n\n";

    $gateway = $_GET['gw'] ?? '192.168.0.1';
    echo "Adding default gateway: $gateway\n";

    $result = shell_exec("ip route add default via $gateway dev ovs_eth1 2>&1");
    echo "Result: " . ($result ?: '(no output - likely success)') . "\n\n";

    echo "New routing table:\n";
    echo shell_exec('ip route show 2>&1') . "\n";

    echo "Testing internet...\n";
    $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 5);
    if ($sock) {
        echo "SUCCESS - Internet is now reachable!\n";
        fclose($sock);
    } else {
        echo "STILL FAILING - errno=$errno: $errstr\n";
        echo "The gateway IP may be wrong. Try ?fix=1&gw=YOUR_ROUTER_IP\n";
    }
    exit;
}

echo "=== Network Diagnostics (PHP) ===\n\n";

// 1. DNS resolution
echo "1. DNS resolve statsapi.mlb.com:\n";
$ips = gethostbynamel('statsapi.mlb.com');
if ($ips) {
    echo "   OK - resolved to: " . implode(', ', $ips) . "\n";
} else {
    echo "   FAILED - cannot resolve hostname\n";
}

// 2. TCP connect test to MLB API
echo "\n2. TCP connect to statsapi.mlb.com:443:\n";
$start = microtime(true);
$sock = @fsockopen('statsapi.mlb.com', 443, $errno, $errstr, 5);
$elapsed = round((microtime(true) - $start) * 1000);
if ($sock) {
    echo "   OK - connected in {$elapsed}ms\n";
    fclose($sock);
} else {
    echo "   FAILED ({$elapsed}ms) - errno=$errno: $errstr\n";
}

// 3. TCP connect to Google DNS (basic internet check)
echo "\n3. TCP connect to 8.8.8.8:53 (internet check):\n";
$start = microtime(true);
$sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 5);
$elapsed = round((microtime(true) - $start) * 1000);
if ($sock) {
    echo "   OK - internet reachable in {$elapsed}ms\n";
    fclose($sock);
} else {
    echo "   FAILED ({$elapsed}ms) - errno=$errno: $errstr\n";
    echo "   ** NO INTERNET - gateway/routing issue **\n";
}

// 4. Routing table
echo "\n4. Routing table:\n";
echo "   " . str_replace("\n", "\n   ", trim(shell_exec('ip route show 2>&1') ?: 'unknown')) . "\n";

echo "\n5. PHP info:\n";
echo "   Server IP: " . ($_SERVER['SERVER_ADDR'] ?? 'unknown') . "\n";
echo "   PHP version: " . PHP_VERSION . "\n";

echo "\n\n=== TO FIX: visit net_diag.php?fix=1 ===\n";
echo "(assumes router is 192.168.0.1 — use ?fix=1&gw=X.X.X.X for different)\n";
