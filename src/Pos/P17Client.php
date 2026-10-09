<?php
declare(strict_types=1);

namespace Parking\Pos;

/**
 * P17Client — card payment straight to an Ingenico EFT-POS over
 * "Protocollo 17" (ECR17) on TCP/IP, no RTS WebDoReMi PC in between.
 *
 * Spec: Ingenico Italia "INGE_EMV ECR protocol — Code 17" v2.7
 * (ING-EMV-Protocol 17-ECR RS232_TCP IP PLUS-110310_with TAG_AFW_EN.pdf).
 *
 *   [PHP] --TCP--> [Ingenico, ECR line set to TCP/IP, listening on a port]
 *
 * Framing (§2-3):
 *   application packet  STX <message> ETX LRC
 *   status (no reply)   SOH <20 chars> EOT        e.g. "TRANSACTION IN PROGRESS"
 *   confirmation        ACK ETX LRC  /  NAK ETX LRC
 *   LRC = 0x7F XOR every message byte. Whether STX is part of "every byte" is
 *   ambiguous in the manual, so incoming frames are accepted either way and
 *   outgoing ones switch convention when the terminal NAKs us.
 *   Each side resends up to 3 times on NAK or timeout.
 *
 * Commands used (fixed-width ASCII, terminal id 8 + "0" + code):
 *   P  payment (§3.4)        -> E result: "00" OK / "01" KO / "09" bad TAG
 *   G  send last result (§3.14) -> the last saved result, used to recover the
 *      outcome when the line drops after the terminal accepted the payment
 *   s  POS status (§3.12)    -> terminal date/time, state, SW releases
 *
 * Config ($cfg['pos'] with base_url = tcp://host:port, see Gateway::posClient()):
 *   terminal_name  -> terminal ID, 8 digits ("00000000" = any terminal)
 *   ecr_id         -> till ID sent in the request, 8 digits
 *   protocol_type  -> payment type, "0" = auto card recognition
 *   connect_timeout, read_timeout (seconds; read covers tap + acquirer auth)
 */
final class P17Client
{
    private const STX = "\x02", ETX = "\x03", SOH = "\x01", EOT = "\x04", ACK = "\x06", NAK = "\x15";

    /** Seconds to wait for the ACK of a packet we sent. */
    private const ACK_TIMEOUT = 5;

    private array $cfg;
    /** @var resource|null */
    private $sock = null;
    private string $buf = '';
    /** LRC convention: true = STX included. Flipped when the terminal NAKs us. */
    private bool $lrcWithStx = true;
    /** Last "procedure status" text the terminal sent (SOH…EOT). */
    private string $lastStatus = '';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        if (isset($cfg['lrc_stx'])) {
            $this->lrcWithStx = (bool) $cfg['lrc_stx'];
        }
    }

    public function enabled(): bool
    {
        return $this->endpoint() !== null;
    }

    /**
     * Charge the card. Blocks until the terminal returns the result (customer
     * tap + acquirer authorisation), up to read_timeout seconds.
     *
     * @return array{ok:bool, error?:string, status?:string, auth_code?:string,
     *               operation_number?:string, pan?:string, stan?:string, raw?:array}
     */
    public function pay(int $amountCents): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }
        if ($amountCents <= 0 || $amountCents > 99999999) {
            return ['ok' => false, 'error' => 'bad_amount'];
        }

        // What the terminal holds as "last result" now — if the line drops
        // mid-payment, a different one afterwards is the outcome of this payment.
        $before = $this->lastResult();
        if (!$before['ok'] && str_starts_with((string) ($before['error'] ?? ''), 'connect')) {
            return ['ok' => false, 'error' => $before['error']];   // terminal unreachable
        }
        $before = !empty($before['found']) ? $this->signature($before['raw']) : null;

        $msg = $this->head('P')
            . $this->ecrId()
            . '0'                                   // no additional data for GT
            . '000'
            . $this->paymentType()
            . str_pad((string) $amountCents, 8, '0', STR_PAD_LEFT)
            . str_repeat(' ', 128)                  // contract code (none)
            . '00000000';

        $readTimeout = max(30, (int) ($this->cfg['read_timeout'] ?? 90));
        $res = $this->transact($msg, $readTimeout);

        if (!$res['ok'] && !empty($res['accepted'])) {
            // The terminal took the payment but we lost the result: it may
            // have charged the card. Ask it for the last result.
            error_log('[p17] result lost after request accepted (' . ($res['error'] ?? '?') . '), recovering');
            $res = $this->recover($before, $readTimeout);
            if (!$res['ok']) {
                return ['ok' => false, 'error' => 'outcome_unknown', 'status' => 'unknown'];
            }
        }
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
        }

        return $this->paymentOutcome($this->parseResult($res['message']));
    }

    /**
     * The last payment/offset/credit result saved on the terminal (G command).
     *
     * @return array{ok:bool, found?:bool, raw?:array, error?:string}
     */
    public function lastResult(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }
        $res = $this->transact($this->head('G') . $this->ecrId() . '0' . '000', 15);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
        }
        $raw = $this->parseResult($res['message']);
        return ['ok' => true, 'found' => ($raw['result'] ?? '') !== '', 'raw' => $raw];
    }

    /**
     * Terminal status (s command). ok = terminal running (state 2, after DLL).
     *
     * @return array{ok:bool, state?:string, terminal_state?:string, datetime?:string,
     *               releases?:string, error?:string}
     */
    public function status(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }
        $res = $this->transact($this->head('s'), 10);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
        }
        $m = $res['message'];
        // After the 10-char header: reserved zeros, DDMMYYHHMM, state digit,
        // then 8-char release ids (e.g. SYS03.4E). Locate the releases rather
        // than trusting the manual's column numbers, which are off by one.
        $tail = substr($m, 10);
        $state = $dt = $rel = '';
        if (preg_match('/(\d{10})(\d)((?:[A-Z]{3}[0-9A-Z.]{5})*)\s*$/', $tail, $mm)) {
            [, $dt, $state, $rel] = $mm;
        }
        $names = [
            '0' => 'not set', '1' => 'set, no DLL', '2' => 'operative', '3' => 'not aligned',
            '4' => 'keys corrupted (run first DLL)', '5' => 'manual DLL requested', '6' => 'SW upload requested',
        ];
        return [
            'ok'             => $state === '2',
            'state'          => $names[$state] ?? ($state !== '' ? "state {$state}" : 'unknown'),
            'terminal_state' => $state,
            'datetime'       => $dt,
            'releases'       => $rel,
            'terminal_id'    => substr($m, 0, 8),
        ];
    }

    // ------------------------------------------------------------------

    /** Turn a parsed E/V result into the Client-shaped answer (same keys as the RTS client). */
    private function paymentOutcome(array $r): array
    {
        if (($r['result'] ?? '') === '00') {
            return [
                'ok'               => true,
                'status'           => 'approved',
                'auth_code'        => $r['auth_code'],
                'operation_number' => $r['stan'],
                'stan'             => $r['stan'],
                'pan'              => $r['pan'],
                'raw'              => $r,
            ];
        }
        $reason = trim($r['description'] ?? '')
            ?: (($r['result'] ?? '') !== '' ? 'result_' . $r['result'] : 'card_declined');
        error_log('[p17] declined: ' . $reason . ' result=' . ($r['result'] ?? ''));
        return ['ok' => false, 'error' => $reason, 'status' => 'declined', 'raw' => $r];
    }

    /** Poll G until the terminal reports a result newer than $before. */
    private function recover(?string $before, int $timeout): array
    {
        if ($before === null) {
            // We don't know what it held before, so a result can't be tied to
            // this payment. Better to say "check the terminal" than guess.
            return ['ok' => false, 'error' => 'outcome_unknown'];
        }
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            sleep(3);
            $last = $this->lastResult();
            if (!empty($last['found']) && $this->signature($last['raw']) !== $before) {
                return ['ok' => true, 'message' => $last['raw']['message']];
            }
        }
        return ['ok' => false, 'error' => 'outcome_unknown'];
    }

    /** What identifies one transaction result: STAN + online number + time. */
    private function signature(array $r): string
    {
        return ($r['result'] ?? '') . '|' . ($r['stan'] ?? '') . '|' . ($r['online_seq'] ?? '') . '|' . ($r['datetime'] ?? '');
    }

    /** Parse an E / V (payment) result message, positions per §3.4.2–3.5.2. */
    private function parseResult(string $m): array
    {
        $f = static fn(int $pos, int $len): string => (string) substr($m, $pos - 1, $len);
        $result = $f(11, 2);
        $out = [
            'message'     => $m,
            'terminal_id' => $f(1, 8),
            'code'        => $f(10, 1),
            'result'      => preg_match('/^\d\d$/', $result) ? $result : '',
            'pan'         => '', 'tx_type' => '', 'auth_code' => '', 'datetime' => '', 'description' => '',
            'card_type'   => $f(48, 1),
            'acquirer'    => trim($f(49, 11)),
            'stan'        => $f(60, 6),
            'online_seq'  => $f(66, 6),
            'action_code' => strlen($m) >= 74 ? $f(72, 3) : '',
        ];
        if ($result === '00') {
            $out['pan']       = ltrim($f(13, 19), '0') ?: $f(13, 19);
            $out['tx_type']   = $f(32, 3);
            $out['auth_code'] = trim($f(35, 6));
            $out['datetime']  = $f(41, 7);
        } else {
            $out['description'] = trim($f(13, 24));
        }
        return $out;
    }

    private function head(string $code): string
    {
        $tid = preg_replace('/\D/', '', (string) ($this->cfg['terminal_name'] ?? ''));
        $tid = $tid === '' ? '0' : $tid;
        return str_pad(substr($tid, -8), 8, '0', STR_PAD_LEFT) . '0' . $code;
    }

    private function ecrId(): string
    {
        $id = preg_replace('/\D/', '', (string) ($this->cfg['ecr_id'] ?? ''));
        return str_pad(substr($id === '' ? '1' : $id, -8), 8, '0', STR_PAD_LEFT);
    }

    private function paymentType(): string
    {
        $t = (string) ($this->cfg['protocol_type'] ?? '0');
        return preg_match('/^[0-3]$/', $t) ? $t : '0';
    }

    /** host + port from base_url "tcp://192.168.100.14:9999" (or bare host:port). */
    private function endpoint(): ?array
    {
        $url = trim((string) ($this->cfg['base_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $p = parse_url(preg_match('#^[a-z]+://#i', $url) ? $url : 'tcp://' . $url);
        if (empty($p['host']) || empty($p['port'])) {
            return null;
        }
        return [$p['host'], (int) $p['port']];
    }

    // ---- transport ---------------------------------------------------

    /**
     * Send one request and wait for its result packet.
     * accepted = the terminal ACKed the request (so it may be acting on it).
     *
     * @return array{ok:bool, message?:string, accepted?:bool, error?:string}
     */
    private function transact(string $msg, int $resultTimeout): array
    {
        $err = $this->connect();
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
        try {
            $acked = false;
            for ($try = 0; $try < 3 && !$acked; $try++) {
                if (!$this->write($this->frame($msg))) {
                    return ['ok' => false, 'error' => 'write_failed'];
                }
                $deadline = microtime(true) + self::ACK_TIMEOUT;
                while (true) {
                    $pkt = $this->readPacket($deadline);
                    if ($pkt === null || $pkt['type'] === 'nak') {
                        // Timeout or NAK: maybe our LRC convention is the wrong one.
                        $this->lrcWithStx = !$this->lrcWithStx;
                        break;
                    }
                    if ($pkt['type'] === 'ack') { $acked = true; break; }
                    if ($pkt['type'] === 'data') {
                        // Result without a separate ACK: treat it as both.
                        $acked = true;
                        $this->write($this->confirm(self::ACK));
                        return ['ok' => true, 'message' => $pkt['message'], 'accepted' => true];
                    }
                    // status text: keep waiting
                }
            }
            if (!$acked) {
                return ['ok' => false, 'error' => 'no_ack'];
            }

            $deadline = microtime(true) + $resultTimeout;
            $naks = 0;
            while (true) {
                $pkt = $this->readPacket($deadline);
                if ($pkt === null) {
                    return ['ok' => false, 'error' => 'result_timeout', 'accepted' => true];
                }
                if ($pkt['type'] === 'bad') {
                    if (++$naks > 3) {
                        return ['ok' => false, 'error' => 'bad_lrc', 'accepted' => true];
                    }
                    $this->write($this->confirm(self::NAK));   // ask for a resend
                    continue;
                }
                if ($pkt['type'] === 'data') {
                    $this->write($this->confirm(self::ACK));
                    return ['ok' => true, 'message' => $pkt['message'], 'accepted' => true];
                }
                // status / stray ACK: keep waiting
            }
        } finally {
            $this->close();
        }
    }

    private function connect(): ?string
    {
        $ep = $this->endpoint();
        if ($ep === null) {
            return 'pos_disabled';
        }
        [$host, $port] = $ep;
        $connTimeout = (float) ($this->cfg['connect_timeout'] ?? 5);
        // The terminal accepts one ECR connection at a time and refuses a new
        // one for a moment after the previous closed (errno 111) or resets it
        // (104). Retry those briefly so a payment isn't lost to a transient
        // refusal; a real bad host/port still fails fast on the first try.
        $attempts = max(1, (int) ($this->cfg['connect_retries'] ?? 8));
        $errno = 0; $errstr = '';
        for ($i = 0; $i < $attempts; $i++) {
            $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $connTimeout);
            if ($sock) {
                stream_set_blocking($sock, true);
                $this->sock = $sock;
                $this->buf  = '';
                return null;
            }
            if ($i < $attempts - 1 && in_array((int) $errno, [111, 104], true)) {
                usleep(500000); // 0.5 s, then try again
                continue;
            }
            break;
        }
        error_log("[p17] connect {$host}:{$port} failed: {$errno} {$errstr}");
        return trim("connect({$errno}): {$errstr}") ?: 'unreachable';
    }

    private function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->buf  = '';
    }

    private function write(string $bytes): bool
    {
        if (!$this->sock) {
            return false;
        }
        $len = strlen($bytes);
        for ($done = 0; $done < $len; $done += $n) {
            $n = @fwrite($this->sock, substr($bytes, $done));
            if ($n === false || $n === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Next packet from the terminal, or null on timeout / closed line.
     * @return array{type:string, message?:string}|null  type: data|ack|nak|status|bad
     */
    private function readPacket(float $deadline): ?array
    {
        while (true) {
            $pkt = $this->extractPacket();
            if ($pkt !== null) {
                return $pkt;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0 || !$this->sock) {
                return null;
            }
            $r = [$this->sock]; $w = $e = null;
            $sec = (int) $left;
            $n = @stream_select($r, $w, $e, $sec, (int) (($left - $sec) * 1e6));
            if ($n === false) {
                return null;
            }
            if ($n === 0) {
                continue;
            }
            $chunk = @fread($this->sock, 1024);
            if ($chunk === false || ($chunk === '' && feof($this->sock))) {
                return null;   // terminal closed the line
            }
            $this->buf .= $chunk;
        }
    }

    /** Cut one complete packet off the front of the buffer, if there is one. */
    private function extractPacket(): ?array
    {
        while ($this->buf !== '') {
            $c = $this->buf[0];
            if ($c === self::ACK || $c === self::NAK) {
                if (strlen($this->buf) < 3) {
                    return null;
                }
                $this->buf = substr($this->buf, 3);
                return ['type' => $c === self::ACK ? 'ack' : 'nak'];
            }
            if ($c === self::SOH) {
                $end = strpos($this->buf, self::EOT);
                if ($end === false) {
                    return null;
                }
                $this->lastStatus = trim(substr($this->buf, 1, $end - 1));
                $this->buf = substr($this->buf, $end + 1);
                return ['type' => 'status', 'message' => $this->lastStatus];
            }
            if ($c === self::STX) {
                $end = strpos($this->buf, self::ETX, 1);
                if ($end === false || strlen($this->buf) < $end + 2) {
                    return null;
                }
                $msg = substr($this->buf, 1, $end - 1);
                $lrc = $this->buf[$end + 1];
                $this->buf = substr($this->buf, $end + 2);
                if ($lrc === $this->lrc(self::STX . $msg . self::ETX)) {
                    $this->lrcWithStx = true;
                } elseif ($lrc === $this->lrc($msg . self::ETX)) {
                    $this->lrcWithStx = false;
                } else {
                    return ['type' => 'bad'];
                }
                return ['type' => 'data', 'message' => $msg];
            }
            $this->buf = substr($this->buf, 1);   // line noise
        }
        return null;
    }

    private function frame(string $msg): string
    {
        $body = self::STX . $msg . self::ETX;
        return $body . $this->lrc($this->lrcWithStx ? $body : $msg . self::ETX);
    }

    private function confirm(string $code): string
    {
        return $code . self::ETX . $this->lrc($code . self::ETX);
    }

    private function lrc(string $bytes): string
    {
        $x = 0x7F;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $x ^= ord($bytes[$i]);
        }
        return chr($x);
    }
}
