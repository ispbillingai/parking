<?php
declare(strict_types=1);

namespace Parking\Pos;

/**
 * DojoClient — card-present payment via the Dojo Cloud API ("Pay at Counter").
 *
 * Unlike the Ingenico terminal (Client / P17Client), the Dojo terminal is driven
 * through Dojo's CLOUD REST API — we never talk to the terminal directly.
 * Flow (docs.dojo.tech → Pay at Counter → Terminals → step-by-step guide):
 *
 *   1) POST /payment-intents                  -> { id: "pi_...", status: "Created" }
 *   2) POST /terminal-sessions                -> { id: "ts_...", status: "InitiateRequested" }
 *        body { terminalId, details: { sessionType: "Sale",
 *                                      sale: { paymentIntentId } } }
 *   3) GET  /terminal-sessions/{id}  (poll)   in flight: InitiateRequested /
 *        Initiated / CancelRequested; final: Captured (Auto) / Authorized
 *        (Manual) / Declined / Canceled / Expired / SignatureVerificationRequired
 *   4) PUT  /terminal-sessions/{id}/signature { accepted: bool }  (only when asked)
 *   5) PUT  /terminal-sessions/{id}/cancel    (only before a card is presented)
 *   6) GET  /payment-intents/{id}             -> paymentDetails { authCode,
 *        transactionId, card { cardNumber, cardType, ... } } for the receipt
 *
 * The steps are exposed separately so public/api/dojo-pay.php can drive them from the
 * kiosk's poll loop (live terminal prompts, Cancel button, signature check)
 * instead of blocking one PHP request for the whole tap.
 *
 * AUTH: `Authorization: Basic <secret_key>` — the key is sent AS-IS after the
 * word Basic (Dojo docs: not base64-encoded). Keys are `sk_sandbox_…` (test) or
 * `sk_prod_…` (live). A dated `version` header is required on every call.
 * Terminal endpoints also need `software-house-id` and `reseller-id` headers
 * (sandbox: softwareHouse1 / reseller1; production values come from Dojo).
 *
 * Config ($cfg['dojo']: config.php overlaid by the admin Payment Gateways page):
 *   base_url          https://api.dojo.tech   (sandbox uses the same host)
 *   secret_key        sk_prod_… / sk_sandbox_…
 *   terminal_id       Dojo terminalId for this till's card machine
 *   version           API version date, e.g. 2026-02-27
 *   reseller_id       reseller-id header
 *   software_house_id software-house-id header
 *   capture_mode      Auto | Manual        (default Auto)
 *   connect_timeout   seconds (default 5)
 *   read_timeout      seconds per HTTP call (default 20)
 *   poll_interval_ms  cashier poll cadence (default 1500)
 *   verify_ssl        (default true)
 */
final class DojoClient
{
    private array $cfg;

    /** ISO 4217 numeric -> alpha, for the few currencies this deployment uses. */
    private const CURRENCY_ALPHA = [978 => 'EUR', 826 => 'GBP', 840 => 'USD'];

    private const SUCCESS_STATES  = ['Captured', 'Authorized'];
    private const FAILURE_STATES  = ['Declined', 'Expired', 'Canceled', 'Cancelled'];
    private const SIGNATURE_STATE = 'SignatureVerificationRequired';

    public function __construct(array $cfg)
    {
        // config.php may have no 'dojo' block at all (settings come from the
        // admin page): Dojo has one API host for sandbox and live.
        if (empty($cfg['base_url'])) {
            $cfg['base_url'] = 'https://api.dojo.tech';
        }
        $this->cfg = $cfg;
    }

    /** Ready to take a payment: key + terminal configured. */
    public function enabled(): bool
    {
        return $this->hasKey() && !empty($this->cfg['terminal_id']);
    }

    private function hasKey(): bool
    {
        return !empty($this->cfg['base_url']) && !empty($this->cfg['secret_key']);
    }

    /**
     * Map a terminal-session status to what the cashier loop should do.
     * @return string pending | success | failure | signature
     */
    public static function classify(string $status): string
    {
        if (in_array($status, self::SUCCESS_STATES, true)) return 'success';
        if (in_array($status, self::FAILURE_STATES, true)) return 'failure';
        if ($status === self::SIGNATURE_STATE) return 'signature';
        return 'pending';
    }

    /**
     * Steps 1+2: create the payment intent and push it to the terminal.
     *
     * @param int $amountCents minor units (1050 = €10.50)
     * @param int $currencyNum ISO 4217 numeric (978 = EUR)
     * @return array{ok:bool, error?:string, payment_intent_id?:string, session_id?:string, status?:string}
     */
    public function startSale(int $amountCents, int $currencyNum, string $reference, string $description = ''): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'dojo_not_configured'];
        }
        $currency = $this->cfg['currency_code'] ?? (self::CURRENCY_ALPHA[$currencyNum] ?? 'EUR');

        $intent = $this->request('POST', '/payment-intents', [
            'amount'      => ['value' => max(0, $amountCents), 'currencyCode' => $currency],
            'reference'   => $reference,
            'description' => $description !== '' ? $description : $reference,
            'captureMode' => ($this->cfg['capture_mode'] ?? 'Auto') === 'Manual' ? 'Manual' : 'Auto',
        ]);
        if (!$intent['ok']) {
            error_log('[dojo] intent error: ' . ($intent['error'] ?? '?'));
            return ['ok' => false, 'error' => $intent['error'] ?? 'intent_failed'];
        }
        $intentId = (string) ($intent['body']['id'] ?? '');
        if ($intentId === '') {
            return ['ok' => false, 'error' => 'intent_no_id'];
        }

        $session = $this->request('POST', '/terminal-sessions', [
            'terminalId' => (string) $this->cfg['terminal_id'],
            'details'    => [
                'sessionType' => 'Sale',
                'sale'        => ['paymentIntentId' => $intentId],
            ],
        ]);
        if (!$session['ok']) {
            error_log('[dojo] session error: ' . ($session['error'] ?? '?'));
            return ['ok' => false, 'error' => $session['error'] ?? 'session_failed', 'payment_intent_id' => $intentId];
        }
        $sessionId = (string) ($session['body']['id'] ?? '');
        if ($sessionId === '') {
            return ['ok' => false, 'error' => 'session_no_id', 'payment_intent_id' => $intentId];
        }
        return [
            'ok'                => true,
            'payment_intent_id' => $intentId,
            'session_id'        => $sessionId,
            'status'            => (string) ($session['body']['status'] ?? 'InitiateRequested'),
        ];
    }

    /**
     * Step 3: one poll of the terminal session.
     * @return array{ok:bool, error?:string, status?:string, prompt?:string, raw?:array}
     */
    public function getSession(string $sessionId): array
    {
        $r = $this->request('GET', '/terminal-sessions/' . rawurlencode($sessionId));
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'poll_failed'];
        }
        $body = $r['body'];
        return [
            'ok'     => true,
            'status' => (string) ($body['status'] ?? ''),
            'prompt' => $this->lastNotification($body),
            'raw'    => $body,
        ];
    }

    /** Step 4: the cashier checked the signature on the terminal receipt. */
    public function answerSignature(string $sessionId, bool $accepted): array
    {
        $r = $this->request('PUT', '/terminal-sessions/' . rawurlencode($sessionId) . '/signature',
            ['accepted' => $accepted]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'signature_failed'];
        }
        // On acceptance Dojo returns the receipt texts; kept for the payment record.
        return ['ok' => true, 'receipts' => array_filter([
            'customer' => $r['body']['customerReceipt'] ?? null,
            'merchant' => $r['body']['merchantReceipt'] ?? null,
        ])];
    }

    /** Step 5: cancel. Dojo refuses once a card has been presented. */
    public function cancel(string $sessionId): array
    {
        if ($sessionId === '' || !$this->hasKey()) {
            return ['ok' => false, 'error' => 'no_session'];
        }
        $r = $this->request('PUT', '/terminal-sessions/' . rawurlencode($sessionId) . '/cancel');
        if (!$r['ok']) {
            error_log('[dojo] cancel failed: ' . ($r['error'] ?? '?'));
        }
        return $r['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $r['error'] ?? 'cancel_failed'];
    }

    /**
     * Step 6: card details for the payment record, from the payment intent.
     * @return array{auth_code:string, pan:string, transaction_id:string, card_type:string, raw:array}
     */
    public function paymentDetails(string $intentId): array
    {
        $r = $this->request('GET', '/payment-intents/' . rawurlencode($intentId));
        $body = $r['ok'] ? $r['body'] : [];
        $pd   = is_array($body['paymentDetails'] ?? null) ? $body['paymentDetails'] : [];
        $card = is_array($pd['card'] ?? null) ? $pd['card'] : [];

        // Never store more than the last 4 digits, whatever shape Dojo sends.
        $digits = preg_replace('/\D/', '', (string) ($card['cardNumber'] ?? ''));
        $last4  = $digits !== '' ? substr($digits, -4) : '';

        return [
            'auth_code'      => (string) ($pd['authCode'] ?? ''),
            'pan'            => $last4 !== '' ? ('************' . $last4) : '',
            'transaction_id' => (string) ($pd['transactionId'] ?? ''),
            'card_type'      => (string) ($card['cardType'] ?? ''),
            'raw'            => $body,
        ];
    }

    /** GET /terminals?statuses=Available — for the admin "find my terminal" helper. */
    public function listTerminals(): array
    {
        if (!$this->hasKey()) {
            return ['ok' => false, 'error' => 'dojo_no_key'];
        }
        $r = $this->request('GET', '/terminals');
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?? 'unreachable'];
        }
        $list = $r['body']['terminals'] ?? $r['body']['items'] ?? $r['body'];
        $out  = [];
        foreach ((array) $list as $t) {
            if (is_array($t) && !empty($t['id'])) {
                // The API id (tm_…) is not what the terminal screen shows; carry
                // the printed identifiers (TID, serial, name) so staff can tell
                // which physical machine is which.
                $label = [];
                $props = array_merge($t, is_array($t['properties'] ?? null) ? $t['properties'] : []);
                foreach (['tid', 'terminalNumber', 'serialNumber', 'name', 'model'] as $k) {
                    if (isset($props[$k]) && is_scalar($props[$k]) && (string) $props[$k] !== '') {
                        $label[] = (string) $props[$k];
                    }
                }
                $out[] = ['id' => (string) $t['id'], 'status' => (string) ($t['status'] ?? ''),
                          'label' => implode(' · ', $label)];
            }
        }
        return ['ok' => true, 'terminals' => $out];
    }

    /**
     * Admin "Test connection": with a terminal id, fetch that terminal (proves
     * key + headers + terminal); without one, list the account's terminals so
     * the admin can copy the id.
     *
     * @return array{ok:bool, error?:string, state?:string, terminals?:array}
     */
    public function testConnection(): array
    {
        if (!$this->hasKey()) {
            return ['ok' => false, 'error' => 'dojo_no_key'];
        }
        if (empty($this->cfg['terminal_id'])) {
            return $this->listTerminals();
        }
        $res = $this->request('GET', '/terminals/' . rawurlencode((string) $this->cfg['terminal_id']));
        if ($res['ok']) {
            return ['ok' => true, 'state' => (string) ($res['body']['status'] ?? 'reachable')];
        }
        return ['ok' => false, 'error' => $res['error'] ?? 'unreachable'];
    }

    /** Human reason for a failed session (Declined / Expired / Canceled). */
    public static function failureReason(array $session, string $status): string
    {
        $reason = trim((string) ($session['declineReason'] ?? $session['errorMessage'] ?? $session['statusReason'] ?? ''));
        return $reason !== '' ? $reason : ('dojo_' . strtolower($status));
    }

    /**
     * The latest prompt the terminal is showing (PresentCard, EnterPin,
     * RemoveCard, …) from notificationEvents, so the cashier sees it live.
     */
    private function lastNotification(array $session): string
    {
        $events = $session['notificationEvents'] ?? [];
        if (!is_array($events) || !$events) {
            return '';
        }
        $last = end($events);
        if (is_string($last)) {
            return $last;
        }
        if (is_array($last)) {
            return (string) ($last['notificationType'] ?? $last['type'] ?? $last['event'] ?? $last['name'] ?? '');
        }
        return '';
    }

    /**
     * One authenticated JSON request to the Dojo API.
     *
     * @return array{ok:bool, status?:int, body?:array, error?:string}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim((string) $this->cfg['base_url'], '/') . $path;

        $headers = [
            'Authorization: Basic ' . trim((string) $this->cfg['secret_key']),
            'version: ' . (string) ($this->cfg['version'] ?? '2026-02-27'),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if (!empty($this->cfg['reseller_id'])) {
            $headers[] = 'reseller-id: ' . (string) $this->cfg['reseller_id'];
        }
        if (!empty($this->cfg['software_house_id'])) {
            $headers[] = 'software-house-id: ' . (string) $this->cfg['software_house_id'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->cfg['connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT        => max(5, min(30, (int) ($this->cfg['read_timeout'] ?? 20))),
            CURLOPT_SSL_VERIFYPEER => ($this->cfg['verify_ssl'] ?? true) ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => ($this->cfg['verify_ssl'] ?? true) ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = ''; // PUT with no body still needs Content-Length: 0
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => "curl({$errno}): {$err}"];
        }
        $status = (int) (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($ch);

        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            $body = [];
        }
        if ($status < 200 || $status >= 300) {
            $msg = (string) ($body['message'] ?? $body['title'] ?? $body['detail'] ?? $body['error'] ?? '');
            if (!empty($body['errors']) && is_array($body['errors'])) {
                $msg .= ' ' . json_encode($body['errors'], JSON_UNESCAPED_UNICODE);
            }
            error_log("[dojo] {$method} {$path} -> HTTP {$status} " . substr((string) $raw, 0, 500));
            return ['ok' => false, 'status' => $status, 'body' => $body,
                    'error' => trim($msg) !== '' ? "HTTP {$status}: " . trim($msg) : "http_{$status}"];
        }
        return ['ok' => true, 'status' => $status, 'body' => $body];
    }
}
