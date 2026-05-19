<?php
declare(strict_types=1);

namespace Parking\Subscription;

use PDO;

/**
 * Decides whether a presented key/tag may open a gate. The same rule is used
 * by the manual key-entry page (public/subscriber-entry.php) and by the
 * Wiegand tag reader handled in bin/mqtt-listener.php, so the logic lives
 * here once: look up the subscription, then reject inactive / expired /
 * overdue ones.
 *
 * check() is pure — it neither logs nor opens the gate. The caller decides
 * what to do with the verdict (web page vs. listener log their own way).
 */
final class AccessControl
{
    /**
     * Normalise a raw key/tag string the way the DB stores key_code:
     * uppercase, letters and digits only. Wiegand tags arrive as plain
     * numbers (e.g. "4169712"), which pass through unchanged.
     */
    public static function normalize(string $raw): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($raw)) ?? '';
    }

    /**
     * @return array{
     *   ok: bool,
     *   reason: ?string,           // empty_key|unknown_key|inactive|expired|overdue
     *   key: string,               // normalised key
     *   sub: ?array,               // subscription row joined with customer + plan
     *   overdue: int               // overdue cents (0 when not applicable)
     * }
     */
    public static function check(PDO $pdo, array $cfg, string $rawKey): array
    {
        $key = self::normalize($rawKey);
        $out = ['ok' => false, 'reason' => null, 'key' => $key, 'sub' => null, 'overdue' => 0];

        if ($key === '') {
            $out['reason'] = 'empty_key';
            return $out;
        }

        $stmt = $pdo->prepare(
            'SELECT s.*, c.full_name, c.plate, p.name AS plan_name, p.period
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.key_code = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $sub = $stmt->fetch();

        if (!$sub) {
            $out['reason'] = 'unknown_key';
            return $out;
        }
        $out['sub'] = $sub;

        $today  = date('Y-m-d');
        $reason = null;
        if ($sub['status'] !== 'active') {
            $reason = 'inactive';
        } elseif (!empty($sub['expires_at'])) {
            // Daily tickets carry a precise rolling-24h cutoff.
            if (strtotime((string) $sub['expires_at']) <= time()) $reason = 'expired';
        } elseif ($sub['ends_on'] < $today) {
            $reason = 'expired';
        }

        // Daily passes are paid upfront — no installment schedule to chase.
        $overdue = ($sub['period'] === 'daily')
            ? 0
            : Scheduler::overdueCents($pdo, (int) $sub['id']);
        $out['overdue'] = $overdue;

        $blockOverdue = (int) ($cfg['app']['subscription_block_overdue'] ?? 1);
        if (!$reason && $blockOverdue && $overdue > 0) {
            $reason = 'overdue';
        }

        $out['reason'] = $reason;
        $out['ok']     = $reason === null;
        return $out;
    }
}
