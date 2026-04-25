<?php
declare(strict_types=1);

namespace Parking\Notify;

use PDO;
use Parking\Db;

/**
 * Sends the entry-ticket notification (PIN + QR + entry time) over the
 * channel(s) the visitor selected at the totem. Logs each attempt to
 * gate_events so the admin dashboard reflects what was sent.
 */
class Dispatcher
{
    public function __construct(private array $cfg) {}

    public function sendTicket(
        PDO $pdo,
        int $sessionId,
        string $pin,
        string $enteredAtHuman,
        ?string $phone,
        ?string $email,
        string $qrUrl,
        array $i18n
    ): array {
        $brand = $i18n['brand'] ?? 'Parking';
        $pinLabel = $i18n['pin_label'] ?? 'PIN';
        $entryLabel = $i18n['entry_label'] ?? 'Entry';
        $intro = $i18n['intro'] ?? 'Your parking ticket';

        $text = $brand . "\n"
              . $intro . "\n"
              . $entryLabel . ': ' . $enteredAtHuman . "\n"
              . $pinLabel . ': ' . $pin . "\n"
              . $qrUrl;

        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:480px;margin:auto;padding:24px;background:#0b1020;color:#e7ecf5;border-radius:14px">'
              . '<h2 style="margin:0 0 8px">' . htmlspecialchars($brand) . '</h2>'
              . '<p style="color:#9aa4bf;margin:0 0 18px">' . htmlspecialchars($intro) . '</p>'
              . '<p style="margin:0 0 6px"><strong>' . htmlspecialchars($entryLabel) . ':</strong> ' . htmlspecialchars($enteredAtHuman) . '</p>'
              . '<p style="margin:0 0 16px"><strong>' . htmlspecialchars($pinLabel) . ':</strong> '
              . '<span style="display:inline-block;font-size:28px;letter-spacing:8px;background:#fff;color:#0b1020;padding:6px 14px;border-radius:8px;font-weight:800">' . htmlspecialchars($pin) . '</span></p>'
              . '<p><img alt="QR" src="' . htmlspecialchars($qrUrl) . '" width="220" height="220" style="background:#fff;padding:8px;border-radius:8px"></p>'
              . '</div>';

        $waOk = null; $emailOk = null;

        if ($phone && !empty($this->cfg['textmebot']['api_key'])) {
            $res = (new TextMeBot($this->cfg['textmebot']))->sendWhatsapp($phone, $text);
            $waOk = (bool) $res['ok'];
            Db::logEvent(
                $pdo, $sessionId, $pin,
                $waOk ? 'whatsapp_sent' : 'whatsapp_fail',
                ['phone' => $phone, 'http' => $res['http'] ?? null]
            );
        }

        if ($email && Mailer::isValid($email)) {
            $subject = $brand . ' — ' . $intro;
            $res = (new Mailer($this->cfg['mailer'] ?? []))->send($email, $subject, $html, $text);
            $emailOk = (bool) $res['ok'];
            Db::logEvent(
                $pdo, $sessionId, $pin,
                $emailOk ? 'email_sent' : 'email_fail',
                ['email' => $email, 'transport' => $res['transport'] ?? null, 'error' => $res['error'] ?? null]
            );
        }

        return ['whatsapp' => $waOk, 'email' => $emailOk];
    }
}
