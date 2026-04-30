<?php
declare(strict_types=1);

namespace Parking\Notify;

use PDO;

/**
 * DB-stored message templates with simple {placeholder} substitution.
 * Admins edit them in /admin/notifications.php; sender paths call
 * Template::render($pdo, $channel, $eventKey, $vars) to get the final
 * subject/body, or fall back to a static default when no row exists
 * (so a fresh DB still sends sensible messages).
 */
final class Template
{
    /**
     * @return array{enabled:bool,subject:?string,body:string}
     */
    public static function render(PDO $pdo, string $channel, string $eventKey, array $vars): array
    {
        $stmt = $pdo->prepare(
            'SELECT subject, body, enabled
             FROM notification_templates
             WHERE channel = ? AND event_key = ? LIMIT 1'
        );
        $stmt->execute([$channel, $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $fallback = self::fallback($channel, $eventKey);
            $row = ['subject' => $fallback['subject'], 'body' => $fallback['body'], 'enabled' => 1];
        }

        return [
            'enabled' => (int) $row['enabled'] === 1,
            'subject' => $row['subject'] !== null ? self::sub((string) $row['subject'], $vars) : null,
            'body'    => self::sub((string) $row['body'], $vars),
        ];
    }

    /**
     * List of every (channel, event_key) the system can send, with the
     * placeholders each one supports. Drives the admin UI's edit form.
     *
     * @return array<int,array{channel:string,event_key:string,description:string,placeholders:string[]}>
     */
    public static function catalog(): array
    {
        return [
            [
                'channel'      => 'whatsapp',
                'event_key'    => 'entrance_ticket',
                'description'  => 'Sent on entry when the visitor provided a phone number (gate auto-print or totem).',
                'placeholders' => ['brand', 'entry_time', 'pin', 'qr_url', 'customer_name', 'phone'],
            ],
            [
                'channel'      => 'email',
                'event_key'    => 'entrance_ticket',
                'description'  => 'Sent by the totem when the visitor chose Email delivery. HTML allowed in the body.',
                'placeholders' => ['brand', 'entry_time', 'pin', 'qr_url', 'customer_name', 'email'],
            ],
            [
                'channel'      => 'whatsapp',
                'event_key'    => 'payment_paid',
                'description'  => 'Sent after the cashier confirms a payment, telling the customer their PIN is now valid for exit.',
                'placeholders' => ['brand', 'pin', 'ttl_minutes', 'amount', 'customer_name', 'phone'],
            ],
        ];
    }

    public static function fallback(string $channel, string $eventKey): array
    {
        $key = $channel . '/' . $eventKey;
        return match ($key) {
            'whatsapp/entrance_ticket' => [
                'subject' => null,
                'body'    => "{brand}\nEntry: {entry_time}\nPIN: {pin}\n{qr_url}",
            ],
            'whatsapp/payment_paid' => [
                'subject' => null,
                'body'    => "Parking paid.\nExit PIN: {pin}\nValid for {ttl_minutes} minutes.",
            ],
            'email/entrance_ticket' => [
                'subject' => '{brand} — Your parking ticket',
                'body'    => '<p>Hello {customer_name},</p><p><strong>PIN: {pin}</strong> · {entry_time}</p><p><img src="{qr_url}" width="220" alt="QR"></p>',
            ],
            default => ['subject' => null, 'body' => ''],
        };
    }

    private static function sub(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }
}
