<?php
declare(strict_types=1);

namespace Parking\Printer;

/**
 * Pushes an entrance ticket straight to a network thermal printer over
 * raw TCP (port 9100). Speaks ESC/POS so the printer cuts the slip on
 * its own — no browser print dialog involved.
 *
 * Config block (config.php → 'printer'):
 *   host    string  — printer IP, e.g. '192.168.52.240'
 *   port    int     — usually 9100
 *   timeout int     — connect / write timeout in seconds (default 5)
 *   width   int     — characters per line (default 32)
 *   codepage int    — ESC/POS code page index for accented chars
 *                     (2 = CP850 Multilingual; default 2)
 */
final class Thermal
{
    private const ESC = "\x1b";
    private const GS  = "\x1d";
    private const LF  = "\n";

    public function __construct(private array $cfg) {}

    public function isEnabled(): bool
    {
        return !empty($this->cfg['host']);
    }

    /**
     * @param array{
     *   brand?:string, serial?:string,
     *   entry_label?:string, entry_time?:string,
     *   plate_label?:string, plate?:string,
     *   pin_label?:string, pin:string,
     *   note?:string
     * } $t
     * @return array{ok:bool, error?:string, bytes?:int}
     */
    public function printEntranceTicket(array $t): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'printer not configured'];
        }
        $host    = (string) $this->cfg['host'];
        $port    = (int) ($this->cfg['port'] ?? 9100);
        $timeout = (int) ($this->cfg['timeout'] ?? 5);

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => trim("$errno $errstr") ?: 'connect failed'];
        }
        stream_set_timeout($fp, $timeout);

        $payload = $this->buildEntrance($t);
        $written = @fwrite($fp, $payload);
        @fclose($fp);

        if ($written === false || $written < strlen($payload)) {
            return ['ok' => false, 'error' => 'short write'];
        }
        return ['ok' => true, 'bytes' => $written];
    }

    private function buildEntrance(array $t): string
    {
        $w        = (int) ($this->cfg['width'] ?? 32);
        $codepage = (int) ($this->cfg['codepage'] ?? 2);  // 2 = CP850
        $brand    = (string) ($t['brand'] ?? 'PARKING');
        $serial   = (string) ($t['serial'] ?? '');
        $pin      = (string) ($t['pin'] ?? '');

        $line  = str_repeat('-', $w);
        $row   = function (string $k, string $v) use ($w): string {
            $left = $this->enc($k);
            $right = $this->enc($v);
            $space = max(1, $w - mb_strlen($left, 'UTF-8') - mb_strlen($right, 'UTF-8'));
            return $left . str_repeat(' ', $space) . $right;
        };

        $out  = self::ESC . '@';                                  // init
        $out .= self::ESC . 't' . chr($codepage);                 // code page
        $out .= self::ESC . 'a' . "\x01";                         // centre
        $out .= self::ESC . '!' . "\x30";                         // double width + height
        $out .= $this->enc($brand) . self::LF;
        $out .= self::ESC . '!' . "\x00";                         // normal
        if ($serial !== '') $out .= $this->enc($serial) . self::LF;
        $out .= $line . self::LF;

        $out .= self::ESC . 'a' . "\x00";                         // left
        if (!empty($t['entry_time'])) {
            $out .= $row((string) ($t['entry_label'] ?? 'Entry'), (string) $t['entry_time']) . self::LF;
        }
        if (!empty($t['plate'])) {
            $out .= $row((string) ($t['plate_label'] ?? 'Plate'), (string) $t['plate']) . self::LF;
        }
        $out .= $line . self::LF;

        // QR code carrying the PIN — the printer's own barcode engine
        // renders it, so the operator never opens a browser dialog.
        $out .= self::ESC . 'a' . "\x01";                         // centre
        $out .= $this->qr($pin);
        $out .= self::LF;
        $out .= $this->enc((string) ($t['pin_label'] ?? 'PIN')) . self::LF;
        $out .= self::ESC . '!' . "\x30";                         // double
        $out .= $pin . self::LF;
        $out .= self::ESC . '!' . "\x00";
        $out .= $line . self::LF;

        if (!empty($t['note'])) {
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->enc((string) $t['note']) . self::LF;
        }

        // Feed a few lines so the cut clears the printed text, then cut.
        $out .= str_repeat(self::LF, 4);
        $out .= self::GS . 'V' . "\x01";                          // partial cut
        return $out;
    }

    /** ESC/POS QR (model 2, error-correction L, module size 6). */
    private function qr(string $data): string
    {
        if ($data === '') return '';
        $len = strlen($data) + 3;
        $pL  = chr($len & 0xff);
        $pH  = chr(($len >> 8) & 0xff);
        $G   = self::GS . '(k';

        $cmd  = $G . "\x04\x00\x31\x41\x32\x00";        // model: 2
        $cmd .= $G . "\x03\x00\x31\x43\x06";            // module size: 6
        $cmd .= $G . "\x03\x00\x31\x45\x31";            // error correction: L
        $cmd .= $G . $pL . $pH . "\x31\x50\x30" . $data;// store data
        $cmd .= $G . "\x03\x00\x31\x51\x30";            // print stored
        return $cmd;
    }

    /** UTF-8 → printer code page so accents (è, à, ò) print correctly. */
    private function enc(string $s): string
    {
        $cp = (int) ($this->cfg['codepage'] ?? 2);
        $target = $cp === 2 ? 'CP850' : ($cp === 0 ? 'CP437' : 'CP850');
        $r = @iconv('UTF-8', $target . '//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }
}
