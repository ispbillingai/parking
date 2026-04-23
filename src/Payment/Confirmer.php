<?php
declare(strict_types=1);

namespace Parking\Payment;

use PDO;
use Parking\Db;
use Parking\Gate\MqttPublisher;
use Parking\Notify\TextMeBot;

class Confirmer
{
    public function __construct(private array $cfg) {}

    /**
     * Marks the active session for $pin as paid, logs the event, fires
     * WhatsApp + MQTT side-effects. Returns ['ok' => bool, 'error' => ?string].
     */
    public function confirm(string $pin, int $amountCents, ?int $cashmaticTxId): array
    {
        $pdo = Db::pdo($this->cfg['db']);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT * FROM parking_sessions
             WHERE pin = ? AND status = "active"
             FOR UPDATE'
        );
        $stmt->execute([$pin]);
        $s = $stmt->fetch();

        if (!$s) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'session not active'];
        }

        $pdo->prepare(
            'UPDATE parking_sessions
             SET status = "paid", paid_at = ?, amount_cents = ?, cashmatic_transaction_id = ?
             WHERE id = ?'
        )->execute([date('Y-m-d H:i:s'), $amountCents, $cashmaticTxId, $s['id']]);

        $pdo->commit();

        Db::logEvent($pdo, (int) $s['id'], $pin, 'payment_ok', [
            'amount_cents' => $amountCents,
            'cm_tx'        => $cashmaticTxId,
        ]);

        if (!empty($s['customer_phone']) && !empty($this->cfg['textmebot']['api_key'])) {
            $ttl = (int) ($this->cfg['app']['pin_ttl_after_pay_minutes'] ?? 15);
            $msg = "Parcheggio pagato.\nPIN uscita: $pin\nValido per {$ttl} minuti. Scansiona il QR al cancello.";
            $res = (new TextMeBot($this->cfg['textmebot']))->sendWhatsapp($s['customer_phone'], $msg);
            Db::logEvent(
                $pdo,
                (int) $s['id'],
                $pin,
                $res['ok'] ? 'whatsapp_sent' : 'whatsapp_fail',
                ['phone' => $s['customer_phone'], 'http' => $res['http'] ?? null]
            );
        }

        try {
            (new MqttPublisher($this->cfg['mqtt']))->publishPinAdd($pin);
        } catch (\Throwable $e) {
            error_log('MQTT pin_add failed: ' . $e->getMessage());
        }

        return ['ok' => true];
    }
}
