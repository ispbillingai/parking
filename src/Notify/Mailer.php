<?php
declare(strict_types=1);

namespace Parking\Notify;

/**
 * Minimal mailer. By default uses PHP mail() with proper headers (good enough
 * when XAMPP / the server has a working sendmail or fake-sendmail relay).
 * If config 'mailer.transport' = 'smtp', uses raw socket SMTP (no PHPMailer
 * dependency required) with optional STARTTLS / implicit TLS and AUTH LOGIN.
 */
class Mailer
{
    public function __construct(private array $cfg) {}

    public function send(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): array
    {
        $transport = strtolower((string) ($this->cfg['transport'] ?? 'mail'));
        $fromEmail = (string) ($this->cfg['from_email'] ?? 'no-reply@localhost');
        $fromName  = (string) ($this->cfg['from_name'] ?? 'Parking');

        $textBody = $textBody ?? trim(strip_tags($htmlBody));

        if ($transport === 'smtp') {
            return $this->sendSmtp($toEmail, $fromEmail, $fromName, $subject, $htmlBody, $textBody);
        }
        return $this->sendMail($toEmail, $fromEmail, $fromName, $subject, $htmlBody, $textBody);
    }

    private function sendMail(string $to, string $fromEmail, string $fromName, string $subject, string $html, string $text): array
    {
        $boundary = '=_b_' . bin2hex(random_bytes(8));
        $headers  = [
            'MIME-Version: 1.0',
            sprintf('From: %s <%s>', self::encodeHeader($fromName), $fromEmail),
            'Reply-To: ' . $fromEmail,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Parking-Mailer',
        ];
        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
              . $text . "\r\n\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
              . $html . "\r\n\r\n"
              . "--$boundary--\r\n";

        $ok = @mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers));
        return ['ok' => (bool) $ok, 'transport' => 'mail'];
    }

    private function sendSmtp(string $to, string $fromEmail, string $fromName, string $subject, string $html, string $text): array
    {
        $host    = (string) ($this->cfg['smtp_host'] ?? '127.0.0.1');
        $port    = (int)    ($this->cfg['smtp_port'] ?? 25);
        $secure  = strtolower((string) ($this->cfg['smtp_secure'] ?? '')); // '', 'ssl', 'tls'
        $user    = (string) ($this->cfg['smtp_user'] ?? '');
        $pass    = (string) ($this->cfg['smtp_pass'] ?? '');
        $timeout = (int)    ($this->cfg['smtp_timeout'] ?? 15);

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if (!$sock) {
            return ['ok' => false, 'transport' => 'smtp', 'error' => "connect: $errstr ($errno)"];
        }
        stream_set_timeout($sock, $timeout);

        $read = function () use ($sock) {
            $resp = '';
            while (!feof($sock)) {
                $line = fgets($sock, 1024);
                if ($line === false) break;
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $resp;
        };
        $write = function (string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
        $expect = function (string $resp, string $code) {
            return strpos(ltrim($resp), $code) === 0;
        };

        $banner = $read();
        if (!$expect($banner, '220')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'banner: '.trim($banner)]; }

        $ehloHost = parse_url((string)($this->cfg['from_email'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
        $write('EHLO ' . $ehloHost);
        $resp = $read();
        if (!$expect($resp, '250')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'ehlo: '.trim($resp)]; }

        if ($secure === 'tls') {
            $write('STARTTLS');
            $resp = $read();
            if (!$expect($resp, '220')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'starttls: '.trim($resp)]; }
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'tls handshake failed'];
            }
            $write('EHLO ' . $ehloHost);
            $resp = $read();
            if (!$expect($resp, '250')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'ehlo2: '.trim($resp)]; }
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $resp = $read();
            if (!$expect($resp, '334')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'auth: '.trim($resp)]; }
            $write(base64_encode($user));
            $resp = $read();
            if (!$expect($resp, '334')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'user: '.trim($resp)]; }
            $write(base64_encode($pass));
            $resp = $read();
            if (!$expect($resp, '235')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'pass: '.trim($resp)]; }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $resp = $read();
        if (!$expect($resp, '250')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'mail-from: '.trim($resp)]; }

        $write('RCPT TO:<' . $to . '>');
        $resp = $read();
        if (!$expect($resp, '250')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'rcpt-to: '.trim($resp)]; }

        $write('DATA');
        $resp = $read();
        if (!$expect($resp, '354')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'data: '.trim($resp)]; }

        $boundary = '=_b_' . bin2hex(random_bytes(8));
        $headers  = [
            'MIME-Version: 1.0',
            sprintf('From: %s <%s>', self::encodeHeader($fromName), $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
              . $text . "\r\n\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
              . $html . "\r\n\r\n"
              . "--$boundary--\r\n";

        // Dot-stuffing (RFC 5321 §4.5.2)
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $resp = $read();
        if (!$expect($resp, '250')) { fclose($sock); return ['ok'=>false,'transport'=>'smtp','error'=>'data-end: '.trim($resp)]; }

        $write('QUIT'); @fclose($sock);
        return ['ok' => true, 'transport' => 'smtp'];
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    public static function isValid(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
