<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Cashmatic\SessionClient;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Log as FiscalLog;
use Parking\Subscription\DailyTicket;

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$ctx = $_SESSION['daily_ticket'] ?? null;
if (!$ctx || empty($ctx['pin']) || empty($ctx['plan_id']) || empty($ctx['amount_cents'])) {
    echo json_encode(['ok' => false, 'error' => 'no_pending_purchase']);
    exit;
}

$pin       = (string) $ctx['pin'];
$phone     = $ctx['phone']     ?? null;
$email     = $ctx['email']     ?? null;
$name      = $ctx['name']      ?? null;
$planId    = (int)    $ctx['plan_id'];
$planName  = (string) ($ctx['plan_name'] ?? 'Daily ticket');
$amount    = (int)    $ctx['amount_cents'];

// Confirm the Cashmatic transaction wrapped up cleanly before issuing anything.
$cash = new SessionClient($cfg['cashmatic']);
$r = $cash->lastTransaction();
if (($r['code'] ?? -1) !== 0) {
    echo json_encode(['ok' => false, 'error' => $r['message'] ?? 'LastTransaction failed']);
    exit;
}
$d   = $r['data'] ?? [];
$end = (string) ($d['end'] ?? '?');
if ($end !== 'normal') {
    echo json_encode(['ok' => false, 'error' => "payment ended as '{$end}'", 'end' => $end]);
    exit;
}

$result = DailyTicket::issue($pdo, $cfg, $pin, $planId, $amount, $phone, $email, $name, 'cash');
if (!$result['ok']) {
    echo json_encode($result);
    exit;
}

// Print the fiscal receipt. Failure here is logged but does not roll back
// the subscription — the customer has paid and is entitled to the pass.
$receipt = null;
$fiscal  = new FiscalClient($cfg['fiscal_printer'] ?? []);

FiscalLog::info('fiscal_attempt', [
    'endpoint'        => 'daily-ticket-finish',
    'method'          => 'cash',
    'pin'             => $pin,
    'plan_id'         => $planId,
    'plan_name'       => $planName,
    'amount_cents'    => $amount,
    'subscription_id' => $result['subscription_id'] ?? null,
    'enabled'         => $fiscal->enabled(),
]);

if (!$fiscal->enabled()) {
    FiscalLog::warn('fiscal_skipped_not_enabled', [
        'endpoint'        => 'daily-ticket-finish',
        'pin'             => $pin,
        'subscription_id' => $result['subscription_id'] ?? null,
        'amount_cents'    => $amount,
    ]);
} else {
    $unitPrice = number_format($amount / 100, 2, '.', '');
    $emit = $fiscal->emit(
        [['description' => substr($planName, 0, 38), 'quantity' => '1', 'unitPrice' => $unitPrice, 'department' => 1]],
        $amount,
        'cash'
    );
    if ($emit['ok']) {
        $receipt = $emit;
        FiscalLog::info('fiscal_result', [
            'endpoint'        => 'daily-ticket-finish',
            'pin'             => $pin,
            'subscription_id' => $result['subscription_id'] ?? null,
            'ok'              => true,
            'receipt_number'  => $emit['receipt_number'] ?? '',
            'receipt_date'    => $emit['receipt_date']   ?? '',
            'z_rep_number'    => $emit['z_rep_number']   ?? '',
        ]);
    } else {
        FiscalLog::error('fiscal_result', [
            'endpoint'        => 'daily-ticket-finish',
            'stage'           => 'emit',
            'pin'             => $pin,
            'subscription_id' => $result['subscription_id'] ?? null,
            'amount_cents'    => $amount,
            'method'          => 'cash',
            'error'           => $emit['error'] ?? '?',
            'note'            => 'cash collected + subscription issued but receipt NOT emitted — issue manual receipt before next Z-report',
        ]);
        Db::logEvent($pdo, null, $pin, 'payment_fail', [
            'stage' => 'fiscal_receipt',
            'error' => $emit['error'] ?? '?',
        ], $result['subscription_id'] ?? null);
    }
}

unset($_SESSION['daily_ticket']);

echo json_encode([
    'ok'              => true,
    'pin'             => $result['pin'],
    'qr_url'          => $result['qr_url'],
    'expires_at'      => $result['expires_at'],
    'expires_human'   => $result['expires_human'],
    'amount_cents'    => $amount,
    'notDispensed'    => (int) ($d['notDispensed'] ?? 0),
    'delivered'       => $result['delivered'],
    'receipt'         => $receipt,
]);
