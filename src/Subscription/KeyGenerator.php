<?php
declare(strict_types=1);

namespace Parking\Subscription;

use PDO;
use RuntimeException;

class KeyGenerator
{
    /**
     * Generates a unique, URL-safe key code (8 chars, base32 alphabet,
     * upper-case, ambiguous chars removed). Suitable for printing on a
     * physical key fob or NFC card.
     */
    public static function unique(PDO $pdo): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1
        $stmt = $pdo->prepare('SELECT 1 FROM subscriptions WHERE key_code = ? LIMIT 1');
        for ($i = 0; $i < 25; $i++) {
            $key = '';
            for ($j = 0; $j < 8; $j++) {
                $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt->execute([$key]);
            if (!$stmt->fetch()) return $key;
        }
        throw new RuntimeException('Unable to generate a unique subscription key');
    }
}
