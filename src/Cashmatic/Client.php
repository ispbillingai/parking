<?php
declare(strict_types=1);

namespace Parking\Cashmatic;

/**
 * Server-side Cashmatic client (for tests/health-checks/server-hosted kiosks).
 *
 * In the standard cloud-PHP deployment the kiosk browser talks to the local
 * Cashmatic REST at 127.0.0.1:50301 directly via JavaScript — see pay.php.
 *
 * Every HTTP call is logged as a JSON line to
 *   storage/logs/cashmatic-YYYY-MM-DD.log
 * so connection / response problems — in particular the "Invalid JSON
 * response" error — can be diagnosed after the fact: the log captures the
 * URL, HTTP status, timing, the raw response body and a remediation hint.
 */
class Client
{
    private ?string $token = null;

    public function __construct(private array $cfg) {}

    public function login(): array
    {
        $res = $this->request('POST', '/api/user/Login', [
            'username' => $this->cfg['username'],
            'password' => $this->cfg['password'],
        ]);
        if (($res['code'] ?? -1) === 0) {
            $this->token = $res['data']['token'] ?? null;
        }
        return $res;
    }

    public function renewToken(): array
    {
        $res = $this->request('POST', '/api/user/RenewToken', null, true);
        if (($res['code'] ?? -1) === 0) {
            $this->token = $res['data']['token'] ?? $this->token;
        }
        return $res;
    }

    public function renewOrLogin(): array
    {
        if ($this->token) {
            $r = $this->renewToken();
            if (($r['code'] ?? -1) === 0) {
                return $r;
            }
        }
        return $this->login();
    }

    public function startPayment(int $amountCents, string $reference = '', string $reason = 'parking'): array
    {
        return $this->request('POST', '/api/transaction/StartPayment', [
            'amount'       => $amountCents,
            'reason'       => $reason,
            'reference'    => $reference,
            'queueAllowed' => false,
        ], true);
    }

    public function activeTransaction(): array
    {
        return $this->request('POST', '/api/device/ActiveTransaction', null, true);
    }

    public function lastTransaction(): array
    {
        return $this->request('POST', '/api/device/LastTransaction', null, true);
    }

    public function cancelPayment(): array
    {
        return $this->request('POST', '/api/transaction/CancelPayment', null, true);
    }

    public function commitPayment(): array
    {
        return $this->request('POST', '/api/transaction/CommitPayment', null, true);
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    private function request(string $method, string $path, ?array $body, bool $auth = false): array
    {
        $url = (string) ($this->cfg['base_url'] ?? '') . $path;

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['verify_ssl']) ? 2 : 0,
            // The Cashmatic is reached over a slow Tailscale link: TCP
            // connects in ~2s and the TLS handshake adds several more.
            // Generous timeouts stop killing calls that would succeed.
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        $started = microtime(true);
        $raw     = curl_exec($ch);
        $durMs   = (int) ((microtime(true) - $started) * 1000);
        $info    = curl_getinfo($ch);
        $httpCode = (int) ($info['http_code'] ?? 0);

        // Transport failure — cURL never got a usable response.
        if ($raw === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);

            self::log('error', 'transport_error', [
                'method'       => $method,
                'path'         => $path,
                'url'          => $url,
                'auth'         => $auth,
                'curl_errno'   => $errno,
                'error'        => 'cURL: ' . $err,
                'duration_ms'  => $durMs,
                'connect_time' => $info['connect_time']    ?? null,
                'namelookup'   => $info['namelookup_time'] ?? null,
                'primary_ip'   => $info['primary_ip']      ?? null,
                'primary_port' => $info['primary_port']    ?? null,
                'verify_ssl'   => !empty($this->cfg['verify_ssl']),
                'hint'         => self::transportHint('curl: ' . $err),
            ]);

            return ['code' => -1, 'message' => 'cURL: ' . $err];
        }
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);

        // Reached the server but the body is not JSON — this is the
        // "Invalid JSON response" path. Log the raw body so the cause
        // (empty reply, HTML error page, wrong port, ...) is visible.
        if (!is_array($decoded)) {
            self::log('error', 'invalid_json_response', [
                'method'      => $method,
                'path'        => $path,
                'url'         => $url,
                'auth'        => $auth,
                'http_code'   => $httpCode,
                'duration_ms' => $durMs,
                'primary_ip'  => $info['primary_ip']   ?? null,
                'primary_port'=> $info['primary_port'] ?? null,
                'content_type'=> $info['content_type'] ?? null,
                'body_length' => strlen((string) $raw),
                'body'        => self::truncate((string) $raw, 2000),
                'hint'        => self::bodyHint($httpCode, (string) $raw),
            ]);

            return [
                'code'    => -1,
                'message' => 'Invalid JSON response: ' . $raw,
                'http'    => $httpCode,
            ];
        }

        // Structured Cashmatic reply. code 0 = ok; anything else is a
        // Cashmatic-level error worth recording too.
        $cmCode = $decoded['code'] ?? null;
        self::log($cmCode === 0 ? 'info' : 'warn', 'response', [
            'method'      => $method,
            'path'        => $path,
            'http_code'   => $httpCode,
            'duration_ms' => $durMs,
            'cm_code'     => $cmCode,
            'cm_message'  => $decoded['message'] ?? null,
        ]);

        return $decoded;
    }

    /**
     * Append one JSON line to storage/logs/cashmatic-YYYY-MM-DD.log.
     * Errors are also mirrored to the PHP error log.
     */
    private static function log(string $level, string $event, array $ctx): void
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = [
            'ts'    => date('c'),
            'level' => $level,
            'event' => $event,
            'pid'   => getmypid(),
        ] + $ctx;

        $encoded = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            $encoded = json_encode(['ts' => date('c'), 'level' => 'error', 'event' => 'log_encode_failed']);
        }

        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'cashmatic-' . date('Y-m-d') . '.log',
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($level === 'error') {
            @error_log('[cashmatic][error] ' . $event . ' ' . (string) ($ctx['hint'] ?? $ctx['error'] ?? ''));
        }
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max
            ? $s
            : substr($s, 0, $max) . '...[' . (strlen($s) - $max) . ' more bytes]';
    }

    /** Remediation tip for a cURL transport error. */
    private static function transportHint(string $err): string
    {
        $low = strtolower($err);
        if (str_contains($low, 'could not resolve host')) {
            return 'DNS failed — set cashmatic.base_url to the Cashmatic IP (e.g. https://192.164.1.4:50301), not a hostname.';
        }
        if (str_contains($low, 'connection refused')) {
            return 'Host reachable but nothing listening on that port — confirm the Cashmatic REST server is running and the port (50301) is correct.';
        }
        if (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return 'Network timeout — confirm the Tailscale subnet route covering 192.164.1.x is advertised+approved and the VPS ran "tailscale up --accept-routes".';
        }
        if (str_contains($low, 'ssl') || str_contains($low, 'certificate')) {
            return 'TLS verification failed — set cashmatic.verify_ssl=false (the Cashmatic kiosk uses a self-signed certificate).';
        }
        return 'Transport error reaching the Cashmatic REST server — check cashmatic.base_url and the network path.';
    }

    /** Remediation tip when a response arrived but was not JSON. */
    private static function bodyHint(int $httpCode, string $body): string
    {
        $trim = ltrim($body);
        if ($trim === '') {
            return 'Empty response body — the connection opened but the server returned nothing. Likely the wrong host:port, or base_url points at a tunnel/proxy that is down. Confirm base_url targets the Cashmatic REST on port 50301.';
        }
        if ($trim[0] === '<' || stripos($trim, '<html') !== false) {
            return 'Server returned HTML, not JSON — base_url is hitting the wrong service or an ngrok/proxy error page. Point cashmatic.base_url straight at the Cashmatic REST (https://<ip>:50301).';
        }
        if ($httpCode >= 500) {
            return "Cashmatic server error (HTTP {$httpCode}) — check the Cashmatic kiosk application.";
        }
        if ($httpCode === 404) {
            return 'HTTP 404 — API path not found; cashmatic.base_url probably has a wrong suffix (it must be just scheme://host:port).';
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return "HTTP {$httpCode} — Cashmatic rejected authentication; check cashmatic.username / password.";
        }
        return "Response was not JSON (HTTP {$httpCode}) — inspect the 'body' field in this log line.";
    }
}
