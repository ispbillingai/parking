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

error_log('[cashmatic-finish] REQUEST pin=' . $pin . ' amount_cents=' . $amount);

if (strlen($pin) !== 6 || $amount <= 0) {
    error_log('[cashmatic-finish] REJECTED bad request pin=' . $pin . ' amount=' . $amount);
    echo json_encode(['ok' => false, 'error' => 'missing pin or amount']);
    exit;
}

$client = new SessionClient($cfg['cashmatic']);

$r = $client->lastTransaction();
error_log('[cashmatic-finish] LastTransaction RAW: '
    . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (($r['code'] ?? -1) !== 0) {
    error_log('[cashmatic-finish] LastTransaction FAILED code=' . ($r['code'] ?? -1)
        . ' message=' . ($r['message'] ?? '?'));
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'LastTransaction failed',
    ]);
    exit;
}

$d   = is_array($r['data'] ?? null) ? $r['data'] : [];
$end = $d['end'] ?? '?';

error_log('[cashmatic-finish] data keys=[' . implode(',', array_keys($d)) . ']'
    . ' end=' . var_export($end, true)
    . ' id=' . var_export($d['id'] ?? null, true)
    . ' notDispensed=' . var_export($d['notDispensed'] ?? null, true));

if ($end !== 'normal') {
    error_log('[cashmatic-finish] ABORT — end is not "normal": ' . var_export($end, true));
    echo json_encode([
        'ok'    => false,
        'error' => "payment ended as '{$end}'",
        'end'   => $end,
    ]);
    exit;
}

$cmTxId  = isset($d['id']) ? (int) $d['id'] : null;
$notDisp = (int) ($d['notDispensed'] ?? 0);

$res = (new Confirmer($cfg))->confirm($pin, $amount, $cmTxId);
error_log('[cashmatic-finish] Confirmer result: ' . json_encode($res, JSON_UNESCAPED_SLASHES));

if (!$res['ok']) {
    error_log('[cashmatic-finish] Confirmer FAILED: ' . ($res['error'] ?? '?'));
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

error_log('[cashmatic-finish] fiscal emit attempt — enabled=' . ($fiscal->enabled() ? 'yes' : 'no')
    . ' pin=' . $pin . ' amount_cents=' . $amount);

FiscalLog::info('fiscal_attempt', [
    'endpoint'     => 'cashmatic-finish',
    'method'       => 'cash',
    'pin'          => $pin,
    'amount_cents' => $amount,
    'cashmatic_id' => $cmTxId,
    'enabled'      => $fiscal->enabled(),
]);

if (!$fiscal->enabled()) {
    error_log('[cashmatic-finish] fiscal SKIPPED — printer not configured');
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
        error_log('[cashmatic-finish] fiscal OK receipt_number=' . ($emit['receipt_number'] ?? ''));
        FiscalLog::info('fiscal_result', [
            'endpoint'       => 'cashmatic-finish',
            'pin'            => $pin,
            'ok'             => true,
            'receipt_number' => $emit['receipt_number'] ?? '',
            'receipt_date'   => $emit['receipt_date']   ?? '',
            'z_rep_number'   => $emit['z_rep_number']   ?? '',
        ]);
    } else {
        error_log('[cashmatic-finish] fiscal FAILED error=' . ($emit['error'] ?? '?'));
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

error_log('[cashmatic-finish] DONE ok pin=' . $pin . ' amount_cents=' . $amount
    . ' receipt=' . ($receipt ? 'emitted' : 'none'));

echo json_encode([
    'ok'           => true,
    'end'          => 'normal',
    'amount_cents' => $amount,
    'notDispensed' => $notDisp,
    'cashmatic_id' => $cmTxId,
    'receipt'      => $receipt,
]);
