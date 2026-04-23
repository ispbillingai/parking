<?php
declare(strict_types=1);

// Manual smoke-test for the Cashmatic REST simulator.
// Usage (from the project root):
//   php bin/cashmatic-test.php                 # charge 100 cents ($1.00), wait for manual commit
//   php bin/cashmatic-test.php 250             # charge 250 cents ($2.50)
//   php bin/cashmatic-test.php 100 --commit    # auto-commit after 2s (hands-free happy path)
//
// Prereqs:
//   1. Cashmatic REST Simulator is running on https://127.0.0.1:50301
//   2. In the simulator's "Testing Area", pick the outcome you want to test
//      (Dispense all change / partial / not dispensed / device error)
//   3. config/config.php has the correct base_url, username, password

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Cashmatic\Client;

$args = array_slice($argv, 1);
$autoCommit = in_array('--commit', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--commit'));
$amount = isset($args[0]) ? (int) $args[0] : 100;
$ref    = 'smoke-' . date('His');

$step = function (string $label) { echo "\n▶ $label\n"; };
$dump = function (array $r) {
    echo "   code={$r['code']}  msg=" . ($r['message'] ?? '') . "\n";
    if (isset($r['data'])) {
        echo "   data=" . json_encode($r['data'], JSON_UNESCAPED_SLASHES) . "\n";
    }
};
$die = function (string $where, array $r) {
    echo "\n✗ FAILED at {$where}: " . ($r['message'] ?? 'unknown') . "\n";
    exit(1);
};

$client = new Client($cfg['cashmatic']);

$step('1. Login');
$r = $client->login();
$dump($r);
if (($r['code'] ?? -1) !== 0) $die('Login', $r);

$step('1b. Cleanup: cancel any stuck transaction from a prior run');
$r = $client->activeTransaction();
$op = $r['data']['operation'] ?? 'idle';
echo "   current op={$op}\n";
if ($op !== 'idle') {
    $c = $client->cancelPayment();
    echo "   CancelPayment  code={$c['code']}  msg=" . ($c['message'] ?? '') . "\n";
    for ($i = 0; $i < 20; $i++) {
        usleep(200_000);
        $r = $client->activeTransaction();
        if (($r['data']['operation'] ?? 'idle') === 'idle') break;
    }
}

$step("2. StartPayment  amount={$amount} cents  reference={$ref}");
$r = $client->startPayment($amount, $ref, 'parking-test');
$dump($r);
if (($r['code'] ?? -1) !== 0) $die('StartPayment', $r);

$step('3. Poll ActiveTransaction until idle' . ($autoCommit ? ' (auto-commit after 2s)' : ''));
$tries = 0;
$maxTries = 200; // ~60s at 300ms
$committed = false;
while ($tries++ < $maxTries) {
    $r = $client->activeTransaction();
    if (($r['code'] ?? -1) !== 0) $die('ActiveTransaction', $r);
    $d  = $r['data'] ?? [];
    $op = $d['operation'] ?? '?';
    $elapsed = $tries * 0.3;
    printf(
        "   [%4.1fs] op=%-10s requested=%-6s inserted=%-6s dispensed=%-6s notDispensed=%s\n",
        $elapsed,
        $op,
        number_format(($d['requested']    ?? 0) / 100, 2),
        number_format(($d['inserted']     ?? 0) / 100, 2),
        number_format(($d['dispensed']    ?? 0) / 100, 2),
        number_format(($d['notDispensed'] ?? 0) / 100, 2)
    );
    if ($op === 'idle') break;
    if ($autoCommit && !$committed && $elapsed >= 2.0 && $op === 'payment') {
        $c = $client->commitPayment();
        echo "   → CommitPayment  code={$c['code']}  msg=" . ($c['message'] ?? '') . "\n";
        $committed = true;
    }
    usleep(300_000);
}
if ($tries >= $maxTries) {
    echo "\n✗ Timed out waiting for idle.\n";
    echo "  Tip: run with --commit to auto-close, or click CommitPayment in the simulator.\n";
    exit(1);
}

$step('4. LastTransaction');
$r = $client->lastTransaction();
$dump($r);
if (($r['code'] ?? -1) !== 0) $die('LastTransaction', $r);

$last = $r['data'] ?? [];
$end  = $last['end'] ?? '?';
echo "\n";
if ($end === 'normal') {
    echo "✓ Payment OK. id={$last['id']}  inserted=" .
         number_format(($last['inserted'] ?? 0) / 100, 2) .
         "  notDispensed=" .
         number_format(($last['notDispensed'] ?? 0) / 100, 2) . "\n";
    if (($last['notDispensed'] ?? 0) > 0) {
        echo "⚠ Change not fully dispensed — your UI must warn the customer.\n";
    }
} else {
    echo "✗ Transaction ended as '{$end}' — treat as failure.\n";
    exit(1);
}
