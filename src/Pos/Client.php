<?php
declare(strict_types=1);

namespace Parking\Pos;

use Parking\Fiscal\Log;

/**
 * Thin HTTP client for RTS Web DoReMi POS 2.0 — the Windows-side middleware
 * that wraps Italian "Protocollo 17" (ECR17) so we don't have to talk binary
 * to the iPP320 / Move/3500 ourselves.
 *
 * Architecture:
 *
 *   [PHP on VPS]
 *       │  HTTP GET /api/Payment?amount=100
 *       ▼
 *   [Tailscale tunnel]
 *       │
 *       ▼
 *   [RTS WebDoReMi service on a LAN PC]    e.g. http://192.164.1.21
 *       │  Protocollo 17 TCP — handled internally by RTS
 *       ▼
 *   [Move/3500 @ 192.164.1.26:5040]
 *
 * RTS docs: WEB_DOREMIPOS_2.0.pdf — http://www.rtseng.it/
 *
 * Endpoints we use:
 *
 *   GET <base_url>/api/Status[/<name>]
 *     → body "Operative" (XML or plain text) when RTS can reach the POS.
 *
 *   GET <base_url>/api/Payment?name=<terminal>&amount=<cents>&protocoltype=0
 *     → <NativeMethods.POSData> XML with TransactionResult ("00"=OK, "01"=KO),
 *       AuthorizationCode, PAN, STAN, OperationNumber, KODescription, etc.
 *
 * Config keys (config/config.php → 'pos' block):
 *
 *   base_url        e.g. 'http://192.164.1.21/WebDoremiposWS'   (required)
 *   terminal_name   the <terminal name="…"> attribute set in RTS's XML config
 *                   on the Windows host (default: '0' = first terminal in list)
 *   protocol_type   '0' auto / '1' credit / '2' debit (default '0')
 *   connect_timeout seconds for the cURL connect (default 5)
 *   read_timeout    seconds we wait for the full payment exchange to finish —
 *                   covers customer card-tap + acquirer auth (default 90)
 */
final class Client
{
    /**
     * @param array{
     *   base_url?:string,
     *   terminal_name?:string,
     *   protocol_type?:string,
     *   connect_timeout?:int,
     *   read_timeout?:int
     * } $cfg
     */
    public function __construct(private array $cfg) {}

    public function enabled(): bool
    {
        return !empty($this->cfg['base_url']);
    }

    /**
     * Trigger a card authorisation. Blocks for up to read_timeout seconds
     * while the customer taps the card and the terminal contacts the acquirer.
     *
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   status?:string,
     *   auth_code?:string,
     *   lines?:array,
     *   raw?:array,
     * }
     */
    public function pay(int $amountCents, ?string $terminalName = null): array
    {
        $name = $terminalName ?? (string) ($this->cfg['terminal_name'] ?? '0');
        $rid  = Log::begin('pos.pay', [
            'amount_cents' => $amountCents,
            'terminal'     => $name,
            'base_url'     => $this->cfg['base_url'] ?? null,
        ]);

        try {
            if (!$this->enabled()) {
                Log::error('pos_disabled', [
                    'error' => 'pos_disabled',
                    'hint'  => 'pos.base_url empty in config/config.php — set it to the RTS service URL on the LAN host.',
                ]);
                return ['ok' => false, 'error' => 'pos_disabled'];
            }

            $url = $this->buildUrl('Payment', [
                'name'         => $name,
                'amount'       => (string) max(0, $amountCents),
                'protocoltype' => (string) ($this->cfg['protocol_type'] ?? '0'),
            ]);

            Log::info('pos_http_request', ['url' => $url]);
            $res = $this->httpGet($url, (int) ($this->cfg['read_timeout'] ?? 90));
            if (!$res['ok']) {
                Log::error('pos_transport_error', [
                    'error' => $res['error'] ?? 'transport_error',
                    'note'  => 'card NOT charged — could not reach the RTS middleware. Check Tailscale + that the WebDoremipos service is running on the LAN host.',
                ]);
                return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
            }

            $parsed = $this->parsePosData($res['body']);
            Log::info('pos_response_parsed', $this->summarisePosData($parsed));

            $result = (string) ($parsed['TransactionResult'] ?? '');
            if ($result === '00') {
                $out = [
                    'ok'        => true,
                    'status'    => 'approved',
                    'auth_code' => (string) ($parsed['AuthorizationCode'] ?? ''),
                    'lines'     => [],
                    'raw'       => $parsed,
                ];
                Log::info('pos_approved', [
                    'auth_code'        => $out['auth_code'],
                    'operation_number' => (string) ($parsed['OperationNumber'] ?? ''),
                    'stan'             => (string) ($parsed['STAN'] ?? ''),
                    'card_type'        => (string) ($parsed['CardType'] ?? ''),
                ]);
                return $out;
            }

            $reason = trim((string) ($parsed['KODescription'] ?? '')) ?: ($result !== '' ? "result_{$result}" : 'card_declined');
            Log::error('card_declined', [
                'error'  => $reason,
                'result' => $result,
                'note'   => 'card NOT charged — terminal or acquirer declined the transaction.',
            ]);
            return [
                'ok'     => false,
                'error'  => $reason,
                'status' => 'declined',
                'lines'  => [],
                'raw'    => $parsed,
            ];
        } finally {
            Log::end($rid);
        }
    }

    /**
     * Check the RTS service is up and can reach the terminal.
     * RTS returns the plain string "Operative" when everything is wired.
     *
     * @return array{ok:bool, state?:string, error?:string}
     */
    public function status(?string $terminalName = null): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }

        $name = $terminalName ?? (string) ($this->cfg['terminal_name'] ?? '0');
        $url  = $this->buildUrl('Status', $name !== '' ? ['name' => $name] : []);

        $rid = Log::begin('pos.status', ['url' => $url]);
        try {
            $res = $this->httpGet($url, 10);
            if (!$res['ok']) {
                Log::error('pos_transport_error', ['error' => $res['error'] ?? '?', 'hint' => 'Verify the RTS service is running and reachable.']);
                return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
            }

            // Body is either "<string ...>Operative</string>" or plain "Operative",
            // depending on the Accept header — strip whitespace + XML tags.
            $text = trim(preg_replace('/<[^>]+>/', '', $res['body']) ?? '');
            Log::info('pos_status_response', ['raw' => $text]);

            $ok = strcasecmp($text, 'Operative') === 0;
            return ['ok' => $ok, 'state' => $text];
        } finally {
            Log::end($rid);
        }
    }

    /* ------------------------------------------------------------------ */
    /* HTTP                                                               */
    /* ------------------------------------------------------------------ */

    private function buildUrl(string $endpoint, array $query): string
    {
        $base = rtrim((string) $this->cfg['base_url'], '/');
        $url  = $base . '/api/' . $endpoint;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /** @return array{ok:bool, status?:int, body?:string, error?:string} */
    private function httpGet(string $url, int $timeoutSec): array
    {
        $connectTo = (int) ($this->cfg['connect_timeout'] ?? 5);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTo,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
        ]);

        $started = microtime(true);
        $body    = curl_exec($ch);
        $durMs   = (int) ((microtime(true) - $started) * 1000);

        if ($body === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);
            return [
                'ok'    => false,
                'error' => "curl({$errno}): {$err}",
            ];
        }

        $status = (int) (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($ch);

        Log::info('pos_http_response', [
            'http_status'  => $status,
            'duration_ms'  => $durMs,
            'body_length'  => strlen((string) $body),
            'body_excerpt' => substr((string) $body, 0, 600),
        ]);

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $body, 'error' => "http_{$status}"];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $body];
    }

    /* ------------------------------------------------------------------ */
    /* XML parsing — RTS returns <NativeMethods.POSData>…</…> by default  */
    /* ------------------------------------------------------------------ */

    /**
     * Lift every child element into a flat associative array, e.g.:
     *   ['TransactionResult' => '00', 'AuthorizationCode' => 'H98595', ...]
     *
     * Tolerates either the wrapped <NativeMethods.POSData> form or any
     * flat XML — and falls back to an empty array if the body isn't XML.
     */
    private function parsePosData(string $body): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->children() as $name => $child) {
            $out[(string) $name] = trim((string) $child);
        }
        return $out;
    }

    /**
     * Keep noisy fields out of the structured log so the entry stays scannable;
     * full body is already in `pos_http_response`.
     */
    private function summarisePosData(array $parsed): array
    {
        $keep = [
            'TransactionResult', 'AuthorizationCode', 'KODescription',
            'OperationNumber', 'STAN', 'TerminalId', 'CardType', 'TransactionType',
        ];
        $out = [];
        foreach ($keep as $k) {
            if (isset($parsed[$k])) $out[$k] = $parsed[$k];
        }
        return $out;
    }
}
