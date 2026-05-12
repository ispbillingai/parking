<?php
declare(strict_types=1);

namespace Parking\Pos;

use Parking\Fiscal\Log;

/**
 * Raw TCP client for the Ingenico iPP320 EFT-POS terminal.
 *
 * Architecture (replacing the Epson-style "printer drives the POS" path):
 *
 *   [PHP on VPS]
 *       │  fsockopen() over Tailscale
 *       ▼
 *   [iPP320 @ 192.164.1.26:5040]
 *       │  speaks Italian "Protocollo 17" (Nexi/CB ECR binary framing)
 *       ▼
 *   acquirer over GSM/Ethernet
 *
 * Reachability test (already passing):
 *   nc -vz 192.164.1.26 5040  → succeeded
 *
 * ──────────────────────────────────────────────────────────────────────
 * GAP: buildFrame() / parseFrame()
 *
 * The frame format is documented in the Nexi/CB "Protocollo 17 / ECR-POS
 * Specifiche" PDF that the cashier company / acquirer has to supply.
 * Until that document is on hand and the two methods below are filled
 * in, pay() returns ['ok' => false, 'error' => 'protocollo17_not_implemented'].
 *
 * Everything else (socket lifecycle, timeouts, hex/ascii logging,
 * structured correlation ids, hint strings on failure) is in place so
 * the day the spec arrives, it's a localized change.
 * ──────────────────────────────────────────────────────────────────────
 */
final class Client
{
    /** @param array{host?:string,port?:int,connect_timeout?:int,read_timeout?:int,operator?:string} $cfg */
    public function __construct(private array $cfg) {}

    public function enabled(): bool
    {
        return !empty($this->cfg['host']) && !empty($this->cfg['port']);
    }

    /**
     * Authorise a card payment for $amountCents.
     *
     * Returns:
     *   ok=true  : ['ok'=>true, 'status'=>'approved', 'auth_code'=>..., 'lines'=>[..slip lines..]]
     *   ok=false : ['ok'=>false, 'error'=>'card_declined'|'protocollo17_not_implemented'|...,
     *               'status'=>'declined'|'unknown', 'lines'=>[]]
     */
    public function pay(int $amountCents, ?string $operator = null): array
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int)    ($this->cfg['port'] ?? 0);
        $op   = $operator ?? (string) ($this->cfg['operator'] ?? '1');

        $rid = Log::begin('pos.pay', [
            'amount_cents' => $amountCents,
            'operator'     => $op,
            'host'         => $host,
            'port'         => $port,
        ]);

        try {
            if (!$this->enabled()) {
                Log::error('pos_disabled', [
                    'where' => 'pay',
                    'error' => 'pos_disabled',
                    'hint'  => 'pos.host or pos.port empty in config/config.php — set host=192.164.1.26 port=5040.',
                ]);
                return ['ok' => false, 'error' => 'pos_disabled'];
            }

            $frame = $this->buildFrame($amountCents, $op);

            // Hard fail until the real Protocollo 17 encoder is in place.
            // Filling in buildFrame() is the only thing that turns this on.
            if ($frame === null) {
                Log::error('protocollo17_not_implemented', [
                    'error' => 'protocollo17_not_implemented',
                    'amount_cents' => $amountCents,
                    'note'  => 'card NOT charged — POS\\Client::buildFrame() needs the Protocollo 17 / Nexi ECR spec',
                    'hint'  => 'Obtain "Protocollo 17 / CB ECR Specifiche" PDF from the cashier company or Nexi, then implement buildFrame() and parseFrame().',
                ]);
                return [
                    'ok'     => false,
                    'error'  => 'protocollo17_not_implemented',
                    'status' => 'unknown',
                    'lines'  => [],
                ];
            }

            $raw = $this->exchange($host, $port, $frame);
            if ($raw === null) {
                // exchange() already logged the transport failure with hint.
                return ['ok' => false, 'error' => 'transport_error'];
            }

            $parsed = $this->parseFrame($raw);
            if ($parsed === null) {
                Log::error('parse_failed', [
                    'error'       => 'parse_failed',
                    'raw_hex'     => self::hex($raw),
                    'raw_ascii'   => self::ascii($raw),
                    'raw_length'  => strlen($raw),
                    'hint'        => 'POS responded but our parser can\'t decode it — confirm the Protocollo 17 response format is what parseFrame() expects.',
                ]);
                return ['ok' => false, 'error' => 'parse_failed'];
            }

            if (($parsed['approved'] ?? false) === true) {
                Log::info('pos_approved', [
                    'auth_code'  => $parsed['auth_code'] ?? null,
                    'line_count' => count($parsed['lines'] ?? []),
                ]);
                return [
                    'ok'        => true,
                    'status'    => 'approved',
                    'auth_code' => (string) ($parsed['auth_code'] ?? ''),
                    'lines'     => $parsed['lines'] ?? [],
                ];
            }

            $reason = (string) ($parsed['decline_reason'] ?? 'card_declined');
            Log::error($reason, [
                'error'  => $reason,
                'parsed' => $parsed,
                'note'   => 'card NOT charged — terminal rejected the transaction',
            ]);
            return [
                'ok'     => false,
                'error'  => $reason,
                'status' => 'declined',
                'lines'  => $parsed['lines'] ?? [],
            ];
        } finally {
            Log::end($rid);
        }
    }

    /**
     * Build the outbound Protocollo 17 binary frame for a card-sale request.
     *
     * Returns the raw bytes ready to write to the socket, or null while
     * the spec is unavailable so that pay() can fail safely.
     */
    private function buildFrame(int $amountCents, string $operator): ?string
    {
        // TODO(spec): implement once the Protocollo 17 / Nexi ECR PDF is in hand.
        //   Expected fields (typical CB ECR layout — verify against the actual spec):
        //     STX (0x02)
        //     length (2 bytes big-endian, total payload length)
        //     message-type (e.g. "00" for sale)
        //     terminal-id (8 ASCII digits, zero-padded)
        //     amount (12 ASCII digits, in cents, zero-padded)
        //     currency code (3 ASCII digits — "978" = EUR)
        //     transaction-id (6 ASCII digits, monotonic counter)
        //     operator-id (variable)
        //     ETX (0x03)
        //     LRC (single XOR byte over message-type..ETX)
        return null;
    }

    /**
     * Decode the POS response frame.
     *
     * Returns a normalized array:
     *   ['approved'=>true, 'auth_code'=>'123456', 'lines'=>[...]]
     *   ['approved'=>false, 'decline_reason'=>'insufficient_funds', 'lines'=>[...]]
     *   null when the bytes don't look like a valid Protocollo 17 reply
     */
    private function parseFrame(string $raw): ?array
    {
        // TODO(spec): implement once the Protocollo 17 / Nexi ECR PDF is in hand.
        //   Typical reply layout:
        //     STX, length, message-type, response-code ("00"=approved, others=declined),
        //     auth-code, slip lines (printable text), ETX, LRC.
        return null;
    }

    /**
     * Open the TCP socket, write the request frame, read the response,
     * close. Times out per cfg.connect_timeout and cfg.read_timeout.
     */
    private function exchange(string $host, int $port, string $frame): ?string
    {
        $connectTo = (int) ($this->cfg['connect_timeout'] ?? 5);
        $readTo    = (int) ($this->cfg['read_timeout']    ?? 35);

        Log::info('pos_tcp_open', [
            'host'            => $host,
            'port'            => $port,
            'connect_timeout' => $connectTo,
            'read_timeout'    => $readTo,
            'send_bytes'      => strlen($frame),
            'send_hex'        => self::hex($frame),
        ]);

        $started = microtime(true);
        $errno   = 0;
        $errstr  = '';
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $connectTo
        );
        if (!$sock) {
            Log::error('pos_connect_failed', [
                'error'       => "stream_socket_client: {$errstr}",
                'errno'       => $errno,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'hint'        => self::connectHint($errno, $errstr, $host, $port),
            ]);
            return null;
        }
        stream_set_timeout($sock, $readTo);

        if (@fwrite($sock, $frame) === false) {
            Log::error('pos_write_failed', [
                'error' => 'fwrite returned false',
                'hint'  => 'TCP write to the POS failed — check Tailscale tunnel and that the terminal is on.',
            ]);
            fclose($sock);
            return null;
        }

        $resp = '';
        $deadline = microtime(true) + $readTo;
        while (!feof($sock) && microtime(true) < $deadline) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false || $chunk === '') break;
            $resp .= $chunk;
            $info = stream_get_meta_data($sock);
            if (!empty($info['timed_out'])) break;
            // Many POS replies fit in one TCP segment; bail when read seems done.
            if (strlen($chunk) < 4096) break;
        }
        $durMs = (int) ((microtime(true) - $started) * 1000);
        fclose($sock);

        Log::info('pos_tcp_recv', [
            'duration_ms' => $durMs,
            'recv_bytes'  => strlen($resp),
            'recv_hex'    => self::hex($resp),
            'recv_ascii'  => self::ascii($resp),
        ]);

        if ($resp === '') {
            Log::error('pos_no_response', [
                'error' => 'pos_no_response',
                'note'  => 'card NOT charged — terminal accepted the TCP connection but never replied (almost always means the request frame was rejected silently as malformed Protocollo 17).',
                'hint'  => 'Confirm buildFrame() output exactly matches the Nexi/CB Protocollo 17 spec, including LRC byte and length field byte-order.',
            ]);
            return null;
        }

        return $resp;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private static function hex(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $c) $out .= sprintf('%02x ', ord($c));
        return rtrim($out);
    }

    private static function ascii(string $s): string
    {
        return preg_replace('/[^\x20-\x7E]/', '.', $s) ?? '';
    }

    private static function connectHint(int $errno, string $errstr, string $host, int $port): string
    {
        $low = strtolower($errstr);
        if (str_contains($low, 'refused')) {
            return "Connection refused on {$host}:{$port} — terminal listening port may have changed; verify on the POS itself.";
        }
        if (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return "Connect timed out to {$host}:{$port} — check Tailscale tunnel is up on the LAN side and the terminal is powered on.";
        }
        if (str_contains($low, 'unreachable') || str_contains($low, 'network')) {
            return "Network unreachable to {$host} — confirm `tailscale status` shows the LAN subnet route as approved.";
        }
        return "TCP connect failed to {$host}:{$port} — re-run `nc -vz {$host} {$port}` from the VPS to confirm baseline reachability.";
    }
}
