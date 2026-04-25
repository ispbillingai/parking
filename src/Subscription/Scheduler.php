<?php
declare(strict_types=1);

namespace Parking\Subscription;

use DateTimeImmutable;
use PDO;

/**
 * Builds the payment schedule for a subscription. Given a start date,
 * an end date, and a plan period (weekly/monthly/annual), it generates one
 * subscription_payments row per period — due on the period_start.
 */
class Scheduler
{
    public static function rebuild(PDO $pdo, int $subscriptionId): int
    {
        $sub = $pdo->prepare(
            'SELECT s.*, p.period, p.price_cents
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.id = ? LIMIT 1'
        );
        $sub->execute([$subscriptionId]);
        $row = $sub->fetch();
        if (!$row) return 0;

        // Drop any unpaid future installments and rebuild the schedule. Paid
        // entries are kept so historical records aren't destroyed.
        $pdo->prepare(
            'DELETE FROM subscription_payments
             WHERE subscription_id = ? AND paid_at IS NULL'
        )->execute([$subscriptionId]);

        $start = new DateTimeImmutable((string) $row['starts_on']);
        $end   = new DateTimeImmutable((string) $row['ends_on']);
        $price = (int) $row['price_cents'];
        $period = (string) $row['period'];

        $existing = $pdo->prepare(
            'SELECT 1 FROM subscription_payments
             WHERE subscription_id = ? AND period_start = ? LIMIT 1'
        );

        $insert = $pdo->prepare(
            'INSERT INTO subscription_payments
                (subscription_id, period_start, period_end, due_on, amount_cents)
             VALUES (?, ?, ?, ?, ?)'
        );

        $count = 0;
        $cursor = $start;
        while ($cursor <= $end) {
            $next = self::advance($cursor, $period);
            $periodEnd = $next->modify('-1 day');
            if ($periodEnd > $end) $periodEnd = $end;

            $existing->execute([$subscriptionId, $cursor->format('Y-m-d')]);
            if (!$existing->fetch()) {
                $insert->execute([
                    $subscriptionId,
                    $cursor->format('Y-m-d'),
                    $periodEnd->format('Y-m-d'),
                    $cursor->format('Y-m-d'),
                    $price,
                ]);
                $count++;
            }
            $cursor = $next;
        }
        return $count;
    }

    public static function advance(DateTimeImmutable $d, string $period): DateTimeImmutable
    {
        return match ($period) {
            'weekly'  => $d->modify('+7 days'),
            'monthly' => $d->modify('+1 month'),
            'annual'  => $d->modify('+1 year'),
            default   => $d->modify('+1 month'),
        };
    }

    /** Sum of overdue (unpaid + due_on < today) amount for a subscription. */
    public static function overdueCents(PDO $pdo, int $subscriptionId, ?string $todayYmd = null): int
    {
        $today = $todayYmd ?? date('Y-m-d');
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_cents),0) FROM subscription_payments
             WHERE subscription_id = ? AND paid_at IS NULL AND due_on <= ?'
        );
        $stmt->execute([$subscriptionId, $today]);
        return (int) $stmt->fetchColumn();
    }
}
