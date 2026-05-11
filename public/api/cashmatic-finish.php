<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Cashmatic\SessionClient;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Log as FiscalLog;
use Parking\Payment\Confirmer;

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

header('Content-Type: application/json');

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));
$amount = (int) ($body['amount_cents'] ?? 0);

if (strlen($pin) !== 6 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'missing pin or amount']);
    exit;
}

$client = new SessionClient($cfg['cashmatic']);

$r = $client->lastTransaction();
if (($r['code'] ?? -1) !== 0) {
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'LastTransaction failed',
    ]);
    exit;
}

$d   = $r['data'] ?? [];
$end = $d['end'] ?? '?';

if ($end !== 'normal') {
    echo json_encode([
        'ok'    => false,
        'error' => "payment ended as '{$end}'",
        'end'   => $end,
    ]);
    exit;
}

$cmTxId      = isset($d['id']) ? (int) $d['id'] : null;
$notDisp     = (int) ($d['notDispensed'] ?? 0);

$res = (new Confirmer($cfg))->confirm($pin, $amount, $cmTxId);

if (!$res['ok']) {
    echo json_encode([
        'ok'    => false,
        'error' => $res['error'] ?? 'confirm failed',
    ]);
    exit;
}

// Emit the fiscal receipt for the cash payment. Failure is logged but
// does not roll back the confirmation — the customer already paid.
$receipt = null;
$fiscal  = new FiscalClient($cfg['fiscal_printer'] ?? []);

FiscalLog::info('fiscal_attempt', [
    'endpoint'     => 'cashmatic-finish',
    'method'       => 'cash',
    'pin'          => $pin,
    'amount_cents' => $amount,
    'cashmatic_id' => $cmTxId,
    'enabled'      => $fiscal->enabled(),
]);

if (!$fiscal->enabled()) {
    FiscalLog::warn('fiscal_skipped_not_enabled', [
        'endpoint'     => 'cashmatic-finish',
        'pin'          => $pin,
        'amount_cents' => $amount,
    ]);
} else {
    $emit = $fiscal->emit(
        [[
            'description' => 'PARCHEGGIO',
            'quantity'    => '1',
            'unitPrice'   => number_format($amount / 100, 2, '.', ''),
            'department'  => 1,
        ]],
        $amount,
        'cash'
    );
    if ($emit['ok']) {
        $receipt = $emit;
        FiscalLog::info('fiscal_result', [
            'endpoint'       => 'cashmatic-finish',
            'pin'            => $pin,
            'ok'             => true,
            'receipt_number' => $emit['receipt_number'] ?? '',
            'receipt_date'   => $emit['receipt_date']   ?? '',
            'z_rep_number'   => $emit['z_rep_number']   ?? '',
        ]);
    } else {
        FiscalLog::error('fiscal_result', [
            'endpoint'     => 'cashmatic-finish',
            'pin'          => $pin,
            'amount_cents' => $amount,
            'method'       => 'cash',
            'error'        => $emit['error'] ?? '?',
            'note'         => 'cash already collected — issue manual receipt before next Z-report',
        ]);
        Db::logEvent($pdo, null, $pin, 'payment_fail', [
            'stage' => 'fiscal_receipt',
            'error' => $emit['error'] ?? '?',
        ]);
    }
}

echo json_encode([
    'ok'           => true,
    'end'          => 'normal',
    'amount_cents' => $amount,
    'notDispensed' => $notDisp,
    'cashmatic_id' => $cmTxId,
    'receipt'      => $receipt,
]);
