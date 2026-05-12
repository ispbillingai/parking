<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Log as FiscalLog;
use Parking\Payment\Confirmer;
use Parking\Pos\Client as PosClient;

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$pos    = new PosClient($cfg['pos'] ?? []);
$fiscal = new FiscalClient($cfg['fiscal_printer'] ?? []);

if (!$pos->enabled()) {
    FiscalLog::error('pos_disabled', [
        'endpoint' => 'card-pay-cashier',
        'note'     => 'endpoint refused — card flow needs a configured POS at pos.host/pos.port',
    ]);
    echo json_encode(['ok' => false, 'error' => 'pos_not_configured']);
    exit;
}

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));
$amount = (int) ($body['amount_cents'] ?? 0);

if (strlen($pin) !== 6 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

// Make sure the session still exists and is unpaid before we charge the card.
$stmt = $pdo->prepare(
    'SELECT id, status FROM parking_sessions WHERE pin = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$pin]);
$session = $stmt->fetch();
if (!$session) {
    echo json_encode(['ok' => false, 'error' => 'session_not_found']);
    exit;
}
if ($session['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => 'session_not_active', 'status' => $session['status']]);
    exit;
}

Db::logEvent($pdo, (int) $session['id'], $pin, 'payment_start', [
    'method' => 'card',
    'amount_cents' => $amount,
]);

FiscalLog::info('pos_attempt', [
    'endpoint'     => 'card-pay-cashier',
    'pin'          => $pin,
    'session_id'   => (int) $session['id'],
    'amount_cents' => $amount,
]);

$auth = $pos->pay($amount);
if (!$auth['ok']) {
    FiscalLog::error('pos_result', [
        'endpoint'     => 'card-pay-cashier',
        'pin'          => $pin,
        'session_id'   => (int) $session['id'],
        'amount_cents' => $amount,
        'error'        => $auth['error']  ?? '?',
        'status'       => $auth['status'] ?? null,
        'note'         => 'card NOT charged — safe to retry or fall back to cash',
    ]);
    Db::logEvent($pdo, (int) $session['id'], $pin, 'payment_fail', [
        'stage'  => 'pos_pay',
        'method' => 'card',
        'error'  => $auth['error'] ?? '?',
    ]);
    echo json_encode([
        'ok'    => false,
        'error' => $auth['error'] ?? 'card_declined',
        'stage' => 'authorize',
    ]);
    exit;
}

FiscalLog::info('pos_ok', [
    'endpoint'   => 'card-pay-cashier',
    'pin'        => $pin,
    'session_id' => (int) $session['id'],
    'auth_code'  => $auth['auth_code'] ?? null,
    'slip_lines' => count($auth['lines'] ?? []),
]);

$confirm = (new Confirmer($cfg))->confirm($pin, $amount, null);
if (!$confirm['ok']) {
    Db::logEvent($pdo, (int) $session['id'], $pin, 'payment_fail', [
        'stage'  => 'confirm',
        'method' => 'card',
        'error'  => $confirm['error'] ?? '?',
    ]);
    echo json_encode(['ok' => false, 'error' => $confirm['error'] ?? 'confirm_failed', 'stage' => 'confirm']);
    exit;
}

$receipt = null;
if ($fiscal->enabled()) {
    FiscalLog::info('fiscal_attempt', [
        'endpoint'     => 'card-pay-cashier',
        'stage'        => 'emit',
        'method'       => 'card',
        'pin'          => $pin,
        'session_id'   => (int) $session['id'],
        'amount_cents' => $amount,
    ]);

    $emit = $fiscal->emit(
        [[
            'description' => 'PARCHEGGIO',
            'quantity'    => '1',
            'unitPrice'   => number_format($amount / 100, 2, '.', ''),
            'department'  => 1,
        ]],
        $amount,
        'card'
    );
    if ($emit['ok']) {
        $receipt = $emit;
        FiscalLog::info('fiscal_result', [
            'endpoint'       => 'card-pay-cashier',
            'pin'            => $pin,
            'session_id'     => (int) $session['id'],
            'ok'             => true,
            'receipt_number' => $emit['receipt_number'] ?? '',
            'receipt_date'   => $emit['receipt_date']   ?? '',
            'z_rep_number'   => $emit['z_rep_number']   ?? '',
        ]);
    } else {
        FiscalLog::error('fiscal_result', [
            'endpoint'     => 'card-pay-cashier',
            'stage'        => 'emit',
            'pin'          => $pin,
            'session_id'   => (int) $session['id'],
            'amount_cents' => $amount,
            'method'       => 'card',
            'error'        => $emit['error'] ?? '?',
            'note'         => 'card already charged but receipt NOT emitted — issue manual receipt before next Z-report',
        ]);
        Db::logEvent($pdo, (int) $session['id'], $pin, 'payment_fail', [
            'stage' => 'fiscal_receipt',
            'error' => $emit['error'] ?? '?',
        ]);
    }
} else {
    FiscalLog::warn('fiscal_skipped_not_enabled', [
        'endpoint'     => 'card-pay-cashier',
        'pin'          => $pin,
        'session_id'   => (int) $session['id'],
        'amount_cents' => $amount,
        'note'         => 'card charged on POS but no fiscal printer configured — manual receipt required',
    ]);
}

echo json_encode([
    'ok'           => true,
    'amount_cents' => $amount,
    'receipt'      => $receipt,
]);
