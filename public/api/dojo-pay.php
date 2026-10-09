<?php
declare(strict_types=1);

/**
 * Card payment via a Dojo terminal (Dojo Cloud API "Pay at Counter"), with the
 * same rules as the Focacciami POS (F:\pub\api\dojo-pay.php). Sibling of
 * card-pay-cashier.php / daily-ticket-card.php (Ingenico), but driven by the
 * kiosk's poll loop like the Cashmatic flow, so the screen can show the
 * terminal's live prompt, offer Cancel, and handle signature verification.
 *
 * Body: { kind, action, … }
 *   kind  'session'  pay a parking session   { pin, amount_cents }
 *         'daily'    buy a daily ticket      { phone, email, name }
 *   action
 *     start      create payment intent + terminal session (resumes an in-flight
 *                session instead of charging twice)
 *     poll       one status check; on Captured/Authorized records the payment
 *                (session paid / daily ticket issued) and emits the fiscal receipt
 *     signature  { accepted: bool } — answer to SignatureVerificationRequired
 *     cancel     cancel the session (Dojo refuses once a card is presented)
 *
 * The in-flight intent/session ids live in $_SESSION['dojo'][key] and are also
 * written to the events log, so a charge can always be reconciled.
 */

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\Fiscal\Log as FiscalLog;
use Parking\Notify\Mailer;
use Parking\Payment\Confirmer;
use Parking\Pin\Generator;
use Parking\Pos\DojoClient;
use Parking\Pos\Gateway;
use Parking\Subscription\DailyTicket;

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
if (session_status() === PHP_SESSION_NONE) session_start();

$reply = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$kind   = ($body['kind'] ?? '') === 'daily' ? 'daily' : 'session';
$action = (string) ($body['action'] ?? 'start');
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));

if ($kind === 'session' && strlen($pin) !== 6) {
    $reply(['ok' => false, 'error' => 'bad_request']);
}

$key    = $kind === 'daily' ? 'daily' : 'pin:' . $pin;
$dojo   = new DojoClient($cfg['dojo'] ?? []);
$fiscal = new FiscalClient($cfg['fiscal_printer'] ?? []);
$state  = $_SESSION['dojo'][$key] ?? null;

$forget = static function () use ($key): void {
    unset($_SESSION['dojo'][$key]);
};

/** Parking session row for the PIN (latest), or null. */
$sessionRow = static function () use ($pdo, $pin): ?array {
    $stmt = $pdo->prepare('SELECT id, status FROM parking_sessions WHERE pin = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$pin]);
    return $stmt->fetch() ?: null;
};

/** Fiscal receipt after the card was charged (Dojo → "card" payment on the RT). */
$emitFiscal = static function (string $description, int $amount, array $ctx) use ($fiscal, $pdo): ?array {
    if (!$fiscal->enabled()) {
        FiscalLog::warn('fiscal_skipped_not_enabled', $ctx + [
            'endpoint' => 'dojo-pay', 'amount_cents' => $amount,
            'note'     => 'card charged on Dojo but no fiscal printer configured — manual receipt required',
        ]);
        return null;
    }
    $emit = $fiscal->emit([[
        'description' => substr($description, 0, 38),
        'quantity'    => '1',
        'unitPrice'   => number_format($amount / 100, 2, '.', ''),
        'department'  => 1,
    ]], $amount, 'card');
    if ($emit['ok']) {
        FiscalLog::info('fiscal_result', $ctx + [
            'endpoint' => 'dojo-pay', 'ok' => true,
            'receipt_number' => $emit['receipt_number'] ?? '',
            'z_rep_number'   => $emit['z_rep_number'] ?? '',
        ]);
        return $emit;
    }
    FiscalLog::error('fiscal_result', $ctx + [
        'endpoint' => 'dojo-pay', 'stage' => 'emit', 'amount_cents' => $amount, 'method' => 'card',
        'error'    => $emit['error'] ?? '?',
        'note'     => 'card already charged on Dojo but receipt NOT emitted — issue manual receipt before next Z-report',
    ]);
    Db::logEvent($pdo, $ctx['session_id'] ?? null, $ctx['pin'] ?? null, 'payment_fail', [
        'stage' => 'fiscal_receipt', 'provider' => 'dojo', 'error' => $emit['error'] ?? '?',
    ]);
    return null;
};

/**
 * Card captured: record the payment, emit the fiscal receipt.
 * Serialised with a DB lock so two overlapping polls can't record it twice.
 */
$complete = static function (array $session) use (
    $pdo, $cfg, $dojo, $kind, $key, $pin, $state, $forget, $reply, $sessionRow, $emitFiscal
): void {
    $lock = 'dojo_' . md5($key . '|' . ($state['payment_intent_id'] ?? ''));
    $pdo->query("SELECT GET_LOCK('{$lock}', 10)");
    try {
        $amount = (int) $state['amount'];
        $card   = $dojo->paymentDetails((string) $state['payment_intent_id']);
        $meta   = [
            'provider'          => 'dojo',
            'payment_intent_id' => $state['payment_intent_id'],
            'session_id'        => $state['session_id'],
            'auth_code'         => $card['auth_code'],
            'card_pan_masked'   => $card['pan'],
            'transaction_id'    => $card['transaction_id'] ?: $state['session_id'],
            'card_type'         => $card['card_type'],
            'session_status'    => $session['status'] ?? '',
        ];

        if ($kind === 'session') {
            $row = $sessionRow();
            if ($row && $row['status'] !== 'active') {
                $forget();
                $reply(['ok' => true, 'state' => 'done', 'already' => true, 'amount_cents' => $amount]);
            }
            $conf = (new Confirmer($cfg))->confirm($pin, $amount, null);
            if (!$conf['ok']) {
                // Card WAS charged but we couldn't record it — log loudly for reconciliation.
                error_log('[dojo-pay] CARD CHARGED but confirm failed for pin ' . $pin
                    . ' intent=' . $state['payment_intent_id'] . ' error=' . ($conf['error'] ?? '?'));
                FiscalLog::error('confirm_failed_after_charge', $meta + ['endpoint' => 'dojo-pay', 'pin' => $pin, 'error' => $conf['error'] ?? '?']);
                Db::logEvent($pdo, $row ? (int) $row['id'] : null, $pin, 'payment_fail', $meta + [
                    'stage' => 'confirm_failed_after_charge', 'error' => $conf['error'] ?? '?',
                ]);
                $reply(['ok' => false, 'state' => 'failed', 'error' => $conf['error'] ?? 'confirm_failed', 'stage' => 'confirm']);
            }
            $forget();
            FiscalLog::info('dojo_captured', $meta + ['endpoint' => 'dojo-pay', 'pin' => $pin, 'amount_cents' => $amount]);
            $receipt = $emitFiscal('PARCHEGGIO', $amount, ['pin' => $pin, 'session_id' => $row ? (int) $row['id'] : null]);
            $reply([
                'ok'           => true,
                'state'        => 'done',
                'amount_cents' => $amount,
                'auth_code'    => $card['auth_code'],
                'receipt'      => $receipt,
            ]);
        }

        // Daily ticket: issue the subscription with the PIN reserved at start.
        $result = DailyTicket::issue(
            $pdo, $cfg, (string) $state['pin'],
            (int) $state['plan_id'], $amount,
            $state['phone'], $state['email'], $state['name'],
            'card'
        );
        if (!$result['ok']) {
            error_log('[dojo-pay] CARD CHARGED but daily ticket not issued, pin ' . $state['pin']
                . ' intent=' . $state['payment_intent_id'] . ' error=' . ($result['error'] ?? '?'));
            FiscalLog::error('confirm_failed_after_charge', $meta + ['endpoint' => 'dojo-pay', 'pin' => $state['pin'], 'error' => $result['error'] ?? '?']);
            Db::logEvent($pdo, null, (string) $state['pin'], 'payment_fail', $meta + [
                'stage' => 'confirm_failed_after_charge', 'error' => $result['error'] ?? '?',
            ]);
            $reply(['ok' => false, 'state' => 'failed', 'error' => $result['error'] ?? 'issue_failed', 'stage' => 'issue']);
        }
        $forget();
        FiscalLog::info('dojo_captured', $meta + ['endpoint' => 'dojo-pay', 'pin' => $state['pin'], 'amount_cents' => $amount,
            'subscription_id' => $result['subscription_id'] ?? null]);
        $receipt = $emitFiscal((string) $state['plan_name'], $amount, ['pin' => $state['pin'], 'subscription_id' => $result['subscription_id'] ?? null]);
        $done = [
            'ok'            => true,
            'state'         => 'done',
            'pin'           => $result['pin'],
            'qr_url'        => $result['qr_url'],
            'expires_at'    => $result['expires_at'],
            'expires_human' => $result['expires_human'],
            'amount_cents'  => $amount,
            'delivered'     => $result['delivered'],
            'auth_code'     => $card['auth_code'],
            'receipt'       => $receipt,
        ];
        // A second poll that queued behind this one gets the same answer.
        $_SESSION['dojo_done'][$key] = $done;
        $reply($done);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('{$lock}')");
    }
};

switch ($action) {
    case 'start':
        if (!Gateway::dojoOn($cfg)) $reply(['ok' => false, 'error' => 'dojo_not_configured']);
        unset($_SESSION['dojo_done'][$key]);

        // A sale already running (reload, double click): resume it rather than
        // sending a second sale to the terminal.
        if ($state) {
            $s = $dojo->getSession((string) $state['session_id']);
            if ($s['ok'] && DojoClient::classify($s['status']) !== 'failure') {
                $reply(['ok' => true, 'state' => 'pending', 'resumed' => true, 'status' => $s['status'],
                        'amount_cents' => (int) $state['amount']]);
            }
            $forget();
        }

        if ($kind === 'session') {
            $amount = (int) ($body['amount_cents'] ?? 0);
            if ($amount <= 0) $reply(['ok' => false, 'error' => 'bad_amount']);
            $row = $sessionRow();
            if (!$row) $reply(['ok' => false, 'error' => 'session_not_found']);
            if ($row['status'] !== 'active') $reply(['ok' => false, 'error' => 'session_not_active', 'status' => $row['status']]);
            $new = ['pin' => $pin, 'amount' => $amount];
            $ref = 'parking-' . $pin;
            $desc = 'Parking PIN ' . $pin;
            $sessionId = (int) $row['id'];
        } else {
            $phone = preg_replace('/[^\d+]/', '', (string) ($body['phone'] ?? '')) ?: null;
            $email = trim((string) ($body['email'] ?? '')) ?: null;
            $name  = trim((string) ($body['name'] ?? '')) ?: null;
            if (!$phone) $reply(['ok' => false, 'error' => 'phone_required']);
            if (!$email || !Mailer::isValid($email)) $reply(['ok' => false, 'error' => 'email_required']);
            $plan = $pdo->query(
                "SELECT id, name, price_cents FROM subscription_plans
                 WHERE period = 'daily' AND active = 1 ORDER BY id LIMIT 1"
            )->fetch();
            if (!$plan || (int) $plan['price_cents'] <= 0) $reply(['ok' => false, 'error' => 'no_active_daily_plan']);
            $amount = (int) $plan['price_cents'];
            $tpin   = Generator::unique($pdo);
            $new = [
                'pin' => $tpin, 'amount' => $amount, 'plan_id' => (int) $plan['id'], 'plan_name' => (string) $plan['name'],
                'phone' => $phone, 'email' => $email, 'name' => $name,
            ];
            $ref = 'daily-' . $tpin;
            $desc = (string) $plan['name'];
            $sessionId = null;
        }

        FiscalLog::info('dojo_attempt', ['endpoint' => 'dojo-pay', 'kind' => $kind, 'pin' => $new['pin'], 'amount_cents' => $amount]);
        $r = $dojo->startSale($amount, Gateway::currencyNum($cfg), $ref, $desc);
        if (!$r['ok']) {
            Db::logEvent($pdo, $sessionId, $new['pin'], 'payment_fail', [
                'stage' => 'dojo_start', 'method' => 'card', 'provider' => 'dojo',
                'error' => $r['error'] ?? '?', 'payment_intent_id' => $r['payment_intent_id'] ?? '',
            ]);
            $reply(['ok' => false, 'error' => $r['error'] ?? 'start_failed']);
        }
        $_SESSION['dojo'][$key] = $new + [
            'payment_intent_id' => $r['payment_intent_id'],
            'session_id'        => $r['session_id'],
        ];
        Db::logEvent($pdo, $sessionId, $new['pin'], 'payment_start', [
            'method' => 'card', 'provider' => 'dojo', 'amount_cents' => $amount,
            'payment_intent_id' => $r['payment_intent_id'], 'session_id' => $r['session_id'],
        ]);
        $reply(['ok' => true, 'state' => 'pending', 'status' => $r['status'], 'amount_cents' => $amount]);

    case 'poll':
        if (!$state) {
            if (!empty($_SESSION['dojo_done'][$key])) $reply($_SESSION['dojo_done'][$key] + ['already' => true]);
            if ($kind === 'session' && ($row = $sessionRow()) && $row['status'] !== 'active') {
                $reply(['ok' => true, 'state' => 'done', 'already' => true]);
            }
            $reply(['ok' => false, 'state' => 'failed', 'error' => 'no_active_session']);
        }
        $s = $dojo->getSession((string) $state['session_id']);
        if (!$s['ok']) {
            // Transient network error — the terminal is still going; keep polling.
            $reply(['ok' => true, 'state' => 'pending', 'status' => '', 'warn' => $s['error'] ?? '']);
        }
        switch (DojoClient::classify($s['status'])) {
            case 'success':
                $complete($s['raw']);
            case 'signature':
                // Dojo gives the operator 80 s from entering this state, then
                // accepts the signature by itself. Report what's left so the
                // popup counts down to the real deadline (also after a reload).
                $since = null;
                foreach ((array) ($s['raw']['statusEvents'] ?? []) as $ev) {
                    if (($ev['status'] ?? '') === 'SignatureVerificationRequired' && !empty($ev['createdAt'])) {
                        $since = strtotime((string) $ev['createdAt']);
                    }
                }
                $left = $since ? max(0, 80 - (time() - $since)) : 80;
                $reply(['ok' => true, 'state' => 'signature', 'status' => $s['status'], 'seconds_left' => $left]);
            case 'failure':
                $forget();
                $err = ($state['signature'] ?? '') === 'rejected'
                    ? 'signature_rejected'
                    : DojoClient::failureReason($s['raw'], $s['status']);
                Db::logEvent($pdo, null, (string) $state['pin'], 'payment_fail', [
                    'stage' => 'dojo_terminal', 'method' => 'card', 'provider' => 'dojo',
                    'status' => $s['status'], 'error' => $err, 'session_id' => $state['session_id'],
                ]);
                $reply(['ok' => false, 'state' => 'failed', 'status' => $s['status'], 'error' => $err]);
            default:
                $reply(['ok' => true, 'state' => 'pending', 'status' => $s['status'], 'prompt' => $s['prompt']]);
        }

    case 'signature':
        if (!$state) $reply(['ok' => false, 'error' => 'no_active_session']);
        $accepted = !empty($body['accepted']);
        $r = $dojo->answerSignature((string) $state['session_id'], $accepted);
        FiscalLog::info($accepted ? 'dojo_signature_accepted' : 'dojo_signature_rejected', [
            'endpoint'   => 'dojo-pay',
            'pin'        => $state['pin'],
            'session_id' => $state['session_id'],
            'error'      => $r['error'] ?? null,
            'receipts'   => $r['receipts'] ?? null,
        ]);
        if ($r['ok']) {
            $_SESSION['dojo'][$key]['signature'] = $accepted ? 'accepted' : 'rejected';
        }
        $reply($r['ok'] ? ['ok' => true, 'state' => 'pending'] : ['ok' => false, 'error' => $r['error']]);

    case 'cancel':
        if (!$state) $reply(['ok' => true]);
        $r = $dojo->cancel((string) $state['session_id']);
        // Don't forget the session here: if the card was already presented the
        // cancel is refused and the sale may still complete — keep polling.
        $reply($r['ok'] ? ['ok' => true, 'state' => 'pending'] : ['ok' => false, 'error' => $r['error']]);

    default:
        $reply(['ok' => false, 'error' => 'unknown_action']);
}
