<?php
declare(strict_types=1);

namespace Parking\Pos;

use Parking\Fiscal\Log;

/**
 * Raw TCP client for the Ingenico iPP320 EFT-POS terminal speaking
 * Italian "Protocollo 17" (ECR17) over LAN.
 *
 *   [PHP on VPS]
 *       │  fsockopen() over Tailscale
 *       ▼
 *   [iPP320 @ 192.164.1.26:5040]
 *       │  Protocollo 17 — STX/ETX framing + LRC
 *       ▼
 *   Nexi acquirer (over the terminal's own GSM/Ethernet uplink)
 *
 * Wire format (per Nexi developer portal,
 * https://developer.nexigroup.com/traditionalpos/en-EU/docs/):
 *
 *   STX (0x02) | application_message | ETX (0x03) | LRC
 *
 *   LRC = 0x7F XOR every byte of (application_message || ETX)
 *
 *   ACK = 0x06 0x03 LRC   (request received, response coming)
 *   NAK = 0x15 0x03 LRC   (bad LRC, retransmit; up to 3 attempts)
 *
 * Payment request 'P' (167 bytes, see buildFrame()):
 *
 *   1-8    terminal id  (8 digits ASCII, zero-padded)
 *   9      '0' reserved
 *   10     'P' message code
 *   11-18  cash register id (8 digits ASCII, zero-padded)
 *   19     additional-data flag '0' or '1'
 *   20-21  '00' reserved
 *   22     card-present flag '0'=insert at POS '1'=already inserted
 *   23     payment type '0'=auto '1'=debit '2'=credit '3'=other
 *   24-31  amount in cents (8 digits ASCII, zero-padded, right-aligned)
 *   32-159 receipt text (128 chars, right-aligned, space-padded)
 *   160-167 '00000000' reserved
 *
 * Payment response 'E' (71 bytes — see parseFrame()):
 *
 *   1-8    terminal id (echo)
 *   9      '0'
 *   10     'E' message code
 *   11-12  result: "00"=OK "01"=KO "05"=card absent "09"=unknown tag
 *
 *   if "00" (approved):
 *     13-31  masked PAN
 *     32-34  txn type (ICC/MAG/MAN/CLM/CLI)
 *     35-40  auth code
 *     41-47  date/time DDDHHMM
 *   if "01" (declined):
 *     13-36  reason text (24 chars)
 *     37-47  '0' reserved
 *
 *   48     card type '1'=Bancomat '2'=Credit '3'=Other
 *   49-59  acquirer id
 *   60-65  STAN
 *   66-71  ID online
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
     * Build the Protocollo 17 'P' payment-request frame.
     *
     * Wire layout: STX (0x02) | 167-byte application message | ETX (0x03) | LRC.
     * See class docblock for the application-message field map.
     */
    private function buildFrame(int $amountCents, string $operator): ?string
    {
        $terminalId    = self::padDigits((string) ($this->cfg['terminal_id']      ?? '00000001'), 8);
        $cashRegister  = self::padDigits((string) ($this->cfg['cash_register_id'] ?? '00000001'), 8);
        $paymentType   = (string) ($this->cfg['payment_type'] ?? '0');   // '0'=auto
        $receiptText   = (string) ($this->cfg['receipt_text'] ?? 'PARCHEGGIO');

        $msg  = $terminalId;                                                                   // 1-8
        $msg .= '0';                                                                           // 9    reserved
        $msg .= 'P';                                                                           // 10   message code
        $msg .= $cashRegister;                                                                 // 11-18
        $msg .= '0';                                                                           // 19   additional-data flag (no extra blocks)
        $msg .= '00';                                                                          // 20-21 reserved
        $msg .= '0';                                                                           // 22   card-present flag (will be inserted at POS)
        $msg .= $paymentType;                                                                  // 23   payment type
        $msg .= self::padDigits((string) max(0, $amountCents), 8);                             // 24-31 amount in cents
        $msg .= str_pad(substr($receiptText, 0, 128), 128, ' ', STR_PAD_LEFT);                 // 32-159 receipt text right-aligned
        $msg .= '00000000';                                                                    // 160-167 reserved

        if (strlen($msg) !== 167) {
            // Defensive — should never happen. Logged so we notice quickly.
            Log::error('frame_length_invalid', [
                'error'    => 'frame_length_invalid',
                'expected' => 167,
                'actual'   => strlen($msg),
            ]);
            return null;
        }

        $framed = "\x02" . $msg . "\x03";
        // LRC = 0x7F XOR every byte from the start of the application message through ETX.
        $lrc = self::lrc(substr($framed, 1)); // exclude STX itself
        return $framed . chr($lrc);
    }

    /**
     * Decode the Protocollo 17 'E' response frame.
     *
     * Tolerates an optional ACK/NAK prefix from the terminal (some
     * iPP320 firmware sends ACK immediately on receipt, then the full
     * response after the card is processed — our exchange() does a long
     * read so both arrive in the same buffer).
     *
     * @return array{approved:bool, decline_reason?:string, auth_code?:string, lines?:array}|null
     */
    private function parseFrame(string $raw): ?array
    {
        $len = strlen($raw);

        // Find the STX that starts the actual response message. Skip any
        // ACK (0x06) or NAK (0x15) prefix the terminal may have sent.
        $stxPos = strpos($raw, "\x02");
        if ($stxPos === false) {
            // Maybe a bare NAK with no STX. Treat as decline so the caller
            // sees something useful in logs.
            if (strpos($raw, "\x15") !== false) {
                return ['approved' => false, 'decline_reason' => 'nak_from_terminal', 'lines' => []];
            }
            return null;
        }
        $etxPos = strpos($raw, "\x03", $stxPos);
        if ($etxPos === false) return null;

        $msg = substr($raw, $stxPos + 1, $etxPos - $stxPos - 1);
        if (strlen($msg) < 12) return null;

        // Position 10 (zero-based 9) = message code; position 11-12 = result.
        $msgCode = $msg[9] ?? '';
        if ($msgCode !== 'E' && $msgCode !== 'V') {
            return null;
        }
        $result = substr($msg, 10, 2);

        // Common trailing fields (positions 48-71 → offsets 47..70).
        // Only present when the message is long enough.
        $common = [];
        if (strlen($msg) >= 71) {
            $common = [
                'card_type'  => $msg[47] ?? '',
                'acquirer'   => trim(substr($msg, 48, 11)),
                'stan'       => substr($msg, 59, 6),
                'id_online'  => substr($msg, 65, 6),
            ];
        }

        if ($result === '00') {
            return array_merge([
                'approved'  => true,
                'pan'       => trim(substr($msg, 12, 19)),
                'txn_type'  => trim(substr($msg, 31, 3)),
                'auth_code' => trim(substr($msg, 34, 6)),
                'date_time' => trim(substr($msg, 40, 7)),
                'lines'     => [],
            ], $common);
        }

        if ($result === '01') {
            return array_merge([
                'approved'       => false,
                'decline_reason' => trim(substr($msg, 12, 24)) ?: 'card_declined',
                'lines'          => [],
            ], $common);
        }

        $known = ['05' => 'card_absent', '09' => 'unknown_tag'];
        return array_merge([
            'approved'       => false,
            'decline_reason' => $known[$result] ?? "result_{$result}",
            'lines'          => [],
        ], $common);
    }

    /**
     * Compute Protocollo 17 LRC = 0x7F XOR every byte.
     */
    private static function lrc(string $bytes): int
    {
        $lrc = 0x7F;
        $n   = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $lrc ^= ord($bytes[$i]);
        }
        return $lrc & 0xFF;
    }

    /**
     * Right-align numeric string to exactly $len ASCII digits,
     * zero-padded on the left, truncated from the left if too long.
     */
    private static function padDigits(string $value, int $len): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '0';
        if (strlen($digits) > $len) {
            $digits = substr($digits, -$len);
        }
        return str_pad($digits, $len, '0', STR_PAD_LEFT);
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

        // Read until we've seen the response 'E'/'V' frame's STX..ETX..LRC,
        // not just any STX. The terminal may send ACK (3 bytes) before the
        // full response, so we keep reading past the first ETX if the
        // message body is too short to be a valid payment response.
        $resp = '';
        $deadline = microtime(true) + $readTo;
        $minPaymentBody = 12;   // STX + 10 header bytes + result code is the minimum to classify
        while (!feof($sock) && microtime(true) < $deadline) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false) break;
            if ($chunk === '') {
                // Empty read can mean "more later" — give the socket a tick.
                usleep(50000);
                $info = stream_get_meta_data($sock);
                if (!empty($info['timed_out'])) break;
                continue;
            }
            $resp .= $chunk;

            // Bail once we have a full 'E'/'V' response (STX, code, ETX, LRC).
            $stx = strpos($resp, "\x02");
            if ($stx !== false) {
                $etx = strpos($resp, "\x03", $stx);
                if ($etx !== false && ($etx - $stx) >= $minPaymentBody && strlen($resp) >= $etx + 2) {
                    // Verify it's actually a payment response, not an ACK
                    // (whose body is 1 byte: 0x06). Position 10 (0-based 9
                    // inside the message) carries the message code.
                    $code = $resp[$stx + 10] ?? '';
                    if ($code === 'E' || $code === 'V') break;
                }
            }

            $info = stream_get_meta_data($sock);
            if (!empty($info['timed_out'])) break;
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
