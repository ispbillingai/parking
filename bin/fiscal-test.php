<?php
/**
 * Fiscal printer test harness.
 *
 * Sends one test commercial document (fiscal receipt) to the Epson
 * fpmate.cgi service configured in config/config.php → fiscal_printer,
 * then prints the outcome. Use it to confirm the printer is reachable
 * and emitting receipts before relying on the live payment flow.
 *
 * Usage (from the project root):
 *
 *   php bin/fiscal-test.php                  # 1.50 EUR, cash
 *   php bin/fiscal-test.php 250              # 2.50 EUR, cash
 *   php bin/fiscal-test.php 250 card         # 2.50 EUR, card
 *   php bin/fiscal-test.php 500 cash "TEST"  # 5.00 EUR, cash, custom line
 *
 * Args:  [amount_cents] [cash|card] [description]
 *
 * Nothing is written to the parking database — this only talks to the
 * printer. Every step is also recorded in storage/logs/fiscal-*.log.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Receipt;

$amountCents = isset($argv[1]) ? (int) $argv[1] : 150;
$method      = isset($argv[2]) ? strtolower((string) $argv[2]) : 'cash';
$description = isset($argv[3]) ? (string) $argv[3] : 'TEST PARCHEGGIO';

if (!in_array($method, ['cash', 'card'], true)) {
    fwrite(STDERR, "Method must be 'cash' or 'card' (got '{$method}').\n");
    exit(2);
}
if ($amountCents <= 0) {
    fwrite(STDERR, "Amount (cents) must be a positive integer.\n");
    exit(2);
}

$fpCfg  = $cfg['fiscal_printer'] ?? [];
$amount = number_format($amountCents / 100, 2, '.', '');

echo "=== Fiscal printer test ===\n";
echo "  base_url   : " . ($fpCfg['base_url'] ?: '(empty — printer disabled)') . "\n";
echo "  operator   : " . ($fpCfg['operator'] ?? '1') . "\n";
echo "  amount     : {$amount}  ({$amountCents} cents)\n";
echo "  method     : {$method}\n";
echo "  description: {$description}\n";
echo "\n";

$client = new FiscalClient($fpCfg);

if (!$client->enabled()) {
    fwrite(STDERR, "Fiscal printer is DISABLED — fiscal_printer.base_url is empty in config/config.php.\n");
    fwrite(STDERR, "Set it to the printer URL (e.g. http://192.168.1.50) and re-run.\n");
    exit(1);
}

$items = [[
    'description' => $description,
    'quantity'    => '1',
    'unitPrice'   => $amount,
    'department'  => 1,
]];

// Show the exact XML payload that will be POSTed (sans SOAP envelope).
echo "--- Receipt XML ---\n";
echo Receipt::build($items, $amountCents, $method, (string) ($fpCfg['operator'] ?? '1')) . "\n\n";

echo "Sending to printer...\n\n";
$res = $client->emit($items, $amountCents, $method);

if ($res['ok']) {
    echo "RECEIPT PRINTED OK\n";
    echo "  receipt number : " . ($res['receipt_number'] ?: '-') . "\n";
    echo "  receipt date   : " . ($res['receipt_date']   ?: '-') . "\n";
    echo "  receipt time   : " . ($res['receipt_time']   ?: '-') . "\n";
    echo "  Z report number: " . ($res['z_rep_number']   ?: '-') . "\n";
    echo "  printer serial : " . ($res['serial_number']  ?: '-') . "\n";
    echo "\nFull trace: storage/logs/fiscal-" . date('Y-m-d') . ".log\n";
    exit(0);
}

echo "RECEIPT FAILED\n";
echo "  error : " . ($res['error']  ?? '?') . "\n";
if (!empty($res['status'])) echo "  status: " . $res['status'] . "\n";
if (!empty($res['raw']))    echo "  raw   : " . $res['raw'] . "\n";
echo "\nFull trace: storage/logs/fiscal-" . date('Y-m-d') . ".log\n";
exit(1);
