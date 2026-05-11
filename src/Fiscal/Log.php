<?php
declare(strict_types=1);

namespace Parking\Fiscal;

/**
 * Structured JSON-line logger for the fiscal-printer integration.
 *
 * Every call to Client::authorizeSales() or Client::emit() opens a
 * "scope" with a short correlation id (rid). All sub-events generated
 * while the scope is open (HTTP request, response, parse, retries,
 * the final outcome) carry that same rid so they can be stitched back
 * together when reading the log.
 *
 * Storage:
 *   storage/logs/fiscal-YYYY-MM-DD.log     (rotated by day)
 *
 * Each line is a single JSON object — `tail -f` works, and you can pipe
 * the file to `jq` for ad-hoc queries. Errors are additionally mirrored
 * to PHP error_log so they surface in the standard web-server log too.
 *
 * Failure hints: every error logged through ::error() is decorated with
 * a short remediation tip (see hintFor()). The operator on call sees,
 * at a glance, where to start looking ("printer unreachable on LAN —
 * check fiscal_printer.base_url and ping the IP").
 */
final class Log
{
    private static ?string $dir = null;
    private static array   $stack = [];     // stack of correlation IDs

    /**
     * Override the default log directory. Called by the bootstrap of
     * the test harness; production code calls nothing and gets the
     * default storage/logs path under the project root.
     */
    public static function setDir(string $dir): void
    {
        self::$dir = rtrim($dir, "/\\");
    }

    /**
     * Open a logging scope. All log lines emitted until end($rid) carry
     * the returned correlation id. Scopes nest: the most recent open
     * scope wins, which matches how authorizeSales/emit wrap an internal
     * request() call.
     */
    public static function begin(string $op, array $ctx = []): string
    {
        $rid = bin2hex(random_bytes(4));
        self::$stack[] = $rid;
        self::write('info', 'scope_open', ['op' => $op] + $ctx);
        return $rid;
    }

    public static function end(string $rid, array $ctx = []): void
    {
        self::write('info', 'scope_close', $ctx);
        // Pop the matching rid (tolerate out-of-order ends — rare but
        // worth not crashing on).
        $idx = array_search($rid, self::$stack, true);
        if ($idx !== false) {
            array_splice(self::$stack, $idx, 1);
        }
    }

    public static function info(string $event, array $ctx = []): void
    {
        self::write('info', $event, $ctx);
    }

    public static function warn(string $event, array $ctx = []): void
    {
        self::write('warn', $event, $ctx);
        @error_log('[fiscal][warn] ' . $event . ' ' . self::ctxToString($ctx));
    }

    /**
     * Log an error with an auto-attached `hint` field. The hint is a
     * short string aimed at the on-call operator that names the most
     * likely root cause and the first thing to check.
     */
    public static function error(string $event, array $ctx = []): void
    {
        if (!isset($ctx['hint'])) {
            $ctx['hint'] = self::hintFor($event, $ctx);
        }
        self::write('error', $event, $ctx);
        @error_log('[fiscal][error] ' . $event . ' ' . self::ctxToString($ctx));
    }

    /**
     * Return a short remediation tip for a given error event. Keep
     * messages action-oriented ("do X") and short enough to fit in a
     * single log line.
     */
    public static function hintFor(string $event, array $ctx = []): string
    {
        $err = (string) ($ctx['error'] ?? '');

        // Transport-level: cURL never reached the printer.
        if (str_starts_with($err, 'curl: ')) {
            $low = strtolower($err);
            if (str_contains($low, 'could not resolve host')) {
                return 'DNS failed — set fiscal_printer.base_url to the printer IP, not a hostname, or fix DNS.';
            }
            if (str_contains($low, 'connection refused')) {
                return 'Printer reachable but fpmate.cgi service not responding — power-cycle the printer or check it is in fiscal-server mode.';
            }
            if (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
                return 'Network timeout — confirm the printer IP is on the same LAN as the PHP host and the firewall allows outbound TCP 80/443.';
            }
            if (str_contains($low, 'ssl') || str_contains($low, 'certificate')) {
                return 'TLS verification failed — set fiscal_printer.verify_ssl=false, or install the printer cert in the PHP CA bundle.';
            }
            return 'Transport error — verify the printer LAN cable, IP, and that fpmate.cgi is enabled in the printer web admin.';
        }

        switch ($event) {
            case 'fiscal_printer_disabled':
                return 'fiscal_printer.base_url is empty in config/config.php — set it to the printer URL (e.g. http://192.168.1.50).';
            case 'parse_failed':
                return 'Printer returned something that is not the expected SOAP/XML envelope — capture the raw response and check fpmate.cgi firmware version.';
            case 'no_response_node':
                return 'SOAP envelope received but no <response> child — the printer may have replied to an unsupported command. Check the request XML.';
            case 'printer_error':
                $code   = (string) ($ctx['code']   ?? '');
                $status = (string) ($ctx['status'] ?? '');
                if ($code !== '' || $status !== '') {
                    return "Printer rejected the command (code={$code} status={$status}) — look up the code in FP 000 008 EN §8.3 Error Table.";
                }
                return 'Printer rejected the command — see FP 000 008 EN §8.3 Error Table for the returned code.';
            case 'card_declined':
                return 'EFT-POS terminal declined the card — receipt was not emitted. Customer should retry or use cash.';
            case 'authorize_unknown':
                return 'EFT-POS returned without a clear approve/decline — check the POS terminal display and printer status before retrying.';
            case 'fiscal_emit_failed':
                return 'Card already charged but fiscal receipt failed to print — issue a manual receipt or re-emit before next Z-report (see FP 000 008 EN §10).';
        }

        return 'See fiscal log file for full context; consult FP 000 008 EN error table for printer codes.';
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = [
            'ts'    => date('c'),
            'rid'   => end(self::$stack) ?: null,
            'level' => $level,
            'event' => $event,
            'pid'   => getmypid(),
        ] + $ctx;

        $encoded = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            $encoded = json_encode(['ts' => date('c'), 'level' => 'error', 'event' => 'log_encode_failed']);
        }

        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'fiscal-' . date('Y-m-d') . '.log',
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }
        return self::$dir;
    }

    private static function ctxToString(array $ctx): string
    {
        $s = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $s === false ? '{}' : $s;
    }
}
