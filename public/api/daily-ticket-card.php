<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Log as FiscalLog;
use Parking\Notify\Mailer;
use Parking\Pin\Generator;
use Parking\Pos\Client as PosClient;
use Parking\Subscription\DailyTicket;

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
        'endpoint' => 'daily-ticket-card',
        'note'     => 'endpoint refused — card flow needs a configured POS at pos.host/pos.port',
    ]);
    echo json_encode(['ok' => false, 'error' => 'pos_not_configured']);
    exit;
}

$body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
$phone = preg_replace('/[^\d+]/', '', (string) ($body['phone'] ?? '')) ?: null;
$email = trim((string) ($body['email'] ?? '')) ?: null;
$name  = trim((string) ($body['name']  ?? '')) ?: null;

if (!$phone) {
    echo json_encode(['ok' => false, 'error' => 'phone_required']);
    exit;
}
if (!$email || !Mailer::isValid($email)) {
    echo json_encode(['ok' => false, 'error' => 'email_required']);
    exit;
}

$plan = $pdo->query(
    "SELECT id, name, price_cents FROM subscription_plans
     WHERE period = 'daily' AND active = 1
     ORDER BY id LIMIT 1"
)->fetch();
if (!$plan || (int) $plan['price_cents'] <= 0) {
    echo json_encode(['ok' => false, 'error' => 'no_active_daily_plan']);
    exit;
}

$pin    = Generator::unique($pdo);
$amount = (int) $plan['price_cents'];

// Customer taps card. $pos->pay() opens a TCP socket to the iPP320 over
// Tailscale and blocks until the terminal returns (approved or declined)
// or read_timeout fires.
FiscalLog::info('pos_attempt', [
    'endpoint'     => 'daily-ticket-card',
    'pin'          => $pin,
    'plan_id'      => (int) $plan['id'],
    'amount_cents' => $amount,
    'phone'        => $phone,
    'email'        => $email,
]);

$auth = $pos->pay($amount);
if (!$auth['ok']) {
    FiscalLog::error('pos_result', [
        'endpoint'     => 'daily-ticket-card',
        'pin'          => $pin,
        'plan_id'      => (int) $plan['id'],
        'amount_cents' => $amount,
        'error'        => $auth['error']  ?? '?',
        'status'       => $auth['status'] ?? null,
        'note'         => 'card NOT charged — subscription not issued; customer can retry',
    ]);
    Db::logEvent($pdo, null, $pin, 'payment_fail', [
        'stage'        => 'pos_pay',
        'method'       => 'card',
        'error'        => $auth['error'] ?? '?',
        'amount_cents' => $amount,
    ]);
    echo json_encode([
        'ok'    => false,
        'error' => $auth['error'] ?? 'card_declined',
        'stage' => 'authorize',
    ]);
    exit;
}

FiscalLog::info('pos_ok', [
    'endpoint'   => 'daily-ticket-card',
    'pin'        => $pin,
    'auth_code'  => $auth['auth_code'] ?? null,
    'slip_lines' => count($auth['lines'] ?? []),
]);

// Card approved → issue the subscription and deliver the PIN.
$result = DailyTicket::issue(
    $pdo, $cfg, $pin,
    (int) $plan['id'],
    $amount,
    $phone, $email, $name,
    'card'
);
if (!$result['ok']) {
    echo json_encode($result + ['stage' => 'issue']);
    exit;
}

// Fiscal receipt is currently only attempted when a printer is configured.
// The Custom Print!F at .100 doesn't speak our Epson-XML protocol, so this
// will fail-loudly via logging until either (a) we swap to Epson or (b) we
// rewrite Fiscal\Client for Custom.
$receipt = null;
if ($fiscal->enabled()) {
    FiscalLog::info('fiscal_attempt', [
        'endpoint'        => 'daily-ticket-card',
        'stage'           => 'emit',
        'method'          => 'card',
        'pin'             => $pin,
        'plan_id'         => (int) $plan['id'],
        'plan_name'       => (string) $plan['name'],
        'amount_cents'    => $amount,
        'subscription_id' => $result['subscription_id'] ?? null,
    ]);

    $emit = $fiscal->emit(
        [[
            'description' => substr((string) $plan['name'], 0, 38),
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
            'endpoint'        => 'daily-ticket-card',
            'pin'             => $pin,
            'subscription_id' => $result['subscription_id'] ?? null,
            'ok'              => true,
            'receipt_number'  => $emit['receipt_number'] ?? '',
            'receipt_date'    => $emit['receipt_date']   ?? '',
            'z_rep_number'    => $emit['z_rep_number']   ?? '',
        ]);
    } else {
        FiscalLog::error('fiscal_result', [
            'endpoint'        => 'daily-ticket-card',
            'stage'           => 'emit',
            'pin'             => $pin,
            'subscription_id' => $result['subscription_id'] ?? null,
            'amount_cents'    => $amount,
            'method'          => 'card',
            'error'           => $emit['error'] ?? '?',
            'note'            => 'card charged + subscription issued but receipt NOT emitted — issue manual receipt before next Z-report',
        ]);
        Db::logEvent($pdo, null, $pin, 'payment_fail', [
            'stage' => 'fiscal_receipt',
            'error' => $emit['error'] ?? '?',
        ], $result['subscription_id'] ?? null);
    }
} else {
    FiscalLog::warn('fiscal_skipped_not_enabled', [
        'endpoint'        => 'daily-ticket-card',
        'pin'             => $pin,
        'subscription_id' => $result['subscription_id'] ?? null,
        'amount_cents'    => $amount,
        'note'            => 'card charged on POS but no fiscal printer configured — manual receipt required',
    ]);
}

echo json_encode([
    'ok'              => true,
    'pin'             => $result['pin'],
    'qr_url'          => $result['qr_url'],
    'expires_at'      => $result['expires_at'],
    'expires_human'   => $result['expires_human'],
    'amount_cents'    => $amount,
    'delivered'       => $result['delivered'],
    'receipt'         => $receipt,
]);
