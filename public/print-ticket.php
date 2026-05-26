<?php
declare(strict_types=1);

/**
 * Headless ticket-print endpoint for the gate hardware / external
 * controllers. A plain GET issues a new PIN, inserts the session,
 * prints the thermal ticket, and returns "OK" (or "ERR: <reason>").
 *
 * No HTML, no QR page, no redirects — only a text/plain body so the
 * device firing the request never has to parse anything.
 *
 *   GET /print-ticket.php
 *   GET /print-ticket.php?phone=+39123456789
 *
 * The kiosk's customer-facing entrance.php is unchanged; this URL is
 * meant for "fire and forget" integrations.
 */

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\I18n;
use Parking\Notify\TextMeBot;
use Parking\Notify\Template;
use Parking\Pin\Generator;
use Parking\Printer\Thermal;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);
I18n::init($cfg['app']['default_lang'] ?? null);

$now = new DateTimeImmutable();

$phoneRaw = (string) ($_REQUEST['phone'] ?? '');
$phone    = preg_replace('/[^\d+]/', '', $phoneRaw);
$phone    = $phone === '' ? null : $phone;

$pin = Generator::unique($pdo);

$pdo->prepare(
    'INSERT INTO parking_sessions (pin, entered_at, customer_phone, status)
     VALUES (?, ?, ?, "active")'
)->execute([$pin, $now->format('Y-m-d H:i:s'), $phone]);
$sessionId = (int) $pdo->lastInsertId();
Db::logEvent($pdo, $sessionId, $pin, 'entry', $phone ? ['phone' => $phone, 'channel' => 'gate'] : ['channel' => 'gate']);

$serial = 'N° ' . str_pad((string) $sessionId, 6, '0', STR_PAD_LEFT);

$printer = new Thermal($cfg['printer'] ?? []);
if (!$printer->isEnabled()) {
    http_response_code(503);
    echo "ERR: printer disabled\n";
    exit;
}

$res = $printer->printEntranceTicket([
    'brand'       => I18n::t('entrance_title'),
    'serial'      => $serial,
    'entry_label' => I18n::t('entrance_entry_time'),
    'entry_time'  => $now->format('d/m/Y H:i'),
    'plate_label' => I18n::t('entrance_plate'),
    'plate'       => '—',
    'pin_label'   => I18n::t('entrance_pin'),
    'pin'         => $pin,
    'note'        => I18n::t('entrance_note'),
]);
Db::logEvent($pdo, $sessionId, $pin, 'admin_action', [
    'action' => 'thermal_print',
    'ok'     => $res['ok'],
    'error'  => $res['error'] ?? null,
]);

if (!$res['ok']) {
    http_response_code(502);
    echo 'ERR: ' . ($res['error'] ?? 'print failed') . "\n";
    exit;
}

// Optional WhatsApp copy when a phone was provided. Failure here does
// not change the response — the ticket already printed, which is what
// the caller cares about.
if ($phone && !empty($cfg['textmebot']['api_key'])) {
    try {
        $tpl = Template::render($pdo, 'whatsapp', 'entrance_ticket', [
            'brand'         => I18n::t('entrance_title'),
            'entry_time'    => $now->format('d/m/Y H:i'),
            'pin'           => $pin,
            'qr_url'        => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($pin),
            'phone'         => $phone,
            'customer_name' => '',
        ]);
        if ($tpl['enabled']) {
            $r = (new TextMeBot($cfg['textmebot']))->sendWhatsapp($phone, $tpl['body']);
            Db::logEvent($pdo, $sessionId, $pin, $r['ok'] ? 'whatsapp_sent' : 'whatsapp_fail', [
                'phone' => $phone,
                'http'  => $r['http'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        error_log('print-ticket WhatsApp send failed: ' . $e->getMessage());
    }
}

echo "OK\n";
