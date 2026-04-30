<?php
declare(strict_types=1);

namespace Parking\Notify;

use PDO;
use Parking\Db;

/**
 * Sends the entry-ticket notification (PIN + QR + entry time) over the
 * channel(s) the visitor selected at the totem. Bodies are pulled from
 * the notification_templates table (admin-editable), so the wording
 * here is just placeholder substitution. Logs each attempt to
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
        array $i18n,
        ?string $customerName = null
    ): array {
        $brand = $i18n['brand'] ?? 'Parking';

        $vars = [
            'brand'         => $brand,
            'entry_time'    => $enteredAtHuman,
            'pin'           => $pin,
            'qr_url'        => $qrUrl,
            'phone'         => (string) ($phone ?? ''),
            'email'         => (string) ($email ?? ''),
            'customer_name' => (string) ($customerName ?? ''),
        ];

        $waOk = null; $emailOk = null;

        if ($phone && !empty($this->cfg['textmebot']['api_key'])) {
            $tpl = Template::render($pdo, 'whatsapp', 'entrance_ticket', $vars);
            if ($tpl['enabled']) {
                $res = (new TextMeBot($this->cfg['textmebot']))->sendWhatsapp($phone, $tpl['body']);
                $waOk = (bool) $res['ok'];
                Db::logEvent(
                    $pdo, $sessionId, $pin,
                    $waOk ? 'whatsapp_sent' : 'whatsapp_fail',
                    ['phone' => $phone, 'http' => $res['http'] ?? null]
                );
            }
        }

        if ($email && Mailer::isValid($email)) {
            $tpl = Template::render($pdo, 'email', 'entrance_ticket', $vars);
            if ($tpl['enabled']) {
                $subject = $tpl['subject'] ?? ($brand . ' — Your parking ticket');
                $res = (new Mailer($this->cfg['mailer'] ?? []))->send($email, $subject, $tpl['body']);
                $emailOk = (bool) $res['ok'];
                Db::logEvent(
                    $pdo, $sessionId, $pin,
                    $emailOk ? 'email_sent' : 'email_fail',
                    ['email' => $email, 'transport' => $res['transport'] ?? null, 'error' => $res['error'] ?? null]
                );
            }
        }

        return ['whatsapp' => $waOk, 'email' => $emailOk];
    }
}
