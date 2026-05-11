<?php
declare(strict_types=1);

namespace Parking\Subscription;

use DateTimeImmutable;
use PDO;
use Parking\Db;
use Parking\Gate\MqttPublisher;
use Parking\I18n;
use Parking\Notify\Dispatcher;
use Throwable;

/**
 * Issues a daily-ticket subscription after a successful payment.
 *
 * Shared between the Cashmatic (cash) finish endpoint and the card-pay
 * endpoint so both payment paths produce the same artefacts:
 *   - customer row (find-or-create by email/phone)
 *   - subscriptions row with expires_at = now + 24h
 *   - paid subscription_payments row (so admin revenue reports are correct)
 *   - daily_ticket_sold event
 *   - MQTT pin_add so the gate-side cache learns the new key
 *   - WhatsApp + email delivery of the PIN
 */
final class DailyTicket
{
    /**
     * @return array{
     *   ok:bool,
     *   subscription_id?:int,
     *   customer_id?:int,
     *   pin?:string,
     *   qr_url?:string,
     *   expires_at?:string,
     *   expires_human?:string,
     *   delivered?:array{whatsapp:?bool,email:?bool},
     *   error?:string
     * }
     */
    public static function issue(
        PDO $pdo,
        array $cfg,
        string $pin,
        int $planId,
        int $amountCents,
        ?string $phone,
        ?string $email,
        ?string $name,
        string $method
    ): array {
        $now     = new DateTimeImmutable();
        $today   = $now->format('Y-m-d');
        $nowStr  = $now->format('Y-m-d H:i:s');
        $expires = $now->modify('+24 hours')->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT id FROM customers
                 WHERE (email IS NOT NULL AND email = ?) OR (phone IS NOT NULL AND phone = ?)
                 LIMIT 1'
            );
            $find->execute([$email, $phone]);
            $row = $find->fetch();
            if ($row) {
                $customerId = (int) $row['id'];
                $pdo->prepare(
                    'UPDATE customers
                     SET full_name = COALESCE(NULLIF(?, ""), full_name),
                         email = COALESCE(?, email),
                         phone = COALESCE(?, phone)
                     WHERE id = ?'
                )->execute([(string) ($name ?? ''), $email, $phone, $customerId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO customers (full_name, email, phone) VALUES (?,?,?)'
                )->execute([(string) ($name ?? ''), $email, $phone]);
                $customerId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO subscriptions
                    (customer_id, plan_id, key_code, starts_on, ends_on, expires_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, "active")'
            )->execute([$customerId, $planId, $pin, $today, $today, $expires]);
            $subId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO subscription_payments
                    (subscription_id, period_start, period_end, due_on, amount_cents, paid_at, method)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $subId, $today, $today, $today, $amountCents, $nowStr,
                $method === 'card' ? 'card' : 'cash',
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'db_error: ' . $e->getMessage()];
        }

        Db::logEvent($pdo, null, $pin, 'daily_ticket_sold', [
            'subscription_id' => $subId,
            'customer_id'     => $customerId,
            'amount_cents'    => $amountCents,
            'method'          => $method,
            'expires_at'      => $expires,
        ], $subId);

        try {
            (new MqttPublisher($cfg['mqtt']))->publishPinAdd($pin);
        } catch (Throwable $e) {
            error_log('MQTT pin_add (daily-ticket) failed: ' . $e->getMessage());
        }

        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($pin);
        $delivered = (new Dispatcher($cfg))->sendTicket(
            $pdo, null, $pin,
            $now->format('d/m/Y H:i'),
            $phone, $email, $qrUrl,
            ['brand' => I18n::t('entrance_title')],
            $name
        );

        return [
            'ok'              => true,
            'subscription_id' => $subId,
            'customer_id'     => $customerId,
            'pin'             => $pin,
            'qr_url'          => $qrUrl,
            'expires_at'      => $expires,
            'expires_human'   => (new DateTimeImmutable($expires))->format('d/m/Y H:i'),
            'delivered'       => $delivered,
        ];
    }
}
