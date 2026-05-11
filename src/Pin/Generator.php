<?php
declare(strict_types=1);

namespace Parking\Pin;

use PDO;
use RuntimeException;

class Generator
{
    public static function unique(PDO $pdo): string
    {
        $session = $pdo->prepare(
            'SELECT 1 FROM parking_sessions
             WHERE pin = ? AND status IN ("active","paid") LIMIT 1'
        );
        // Daily-ticket subscriptions use a 6-digit PIN as key_code, so an active
        // pass must not collide with a live kiosk session.
        $sub = $pdo->prepare(
            'SELECT 1 FROM subscriptions
             WHERE key_code = ? AND status = "active"
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        for ($i = 0; $i < 25; $i++) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $session->execute([$pin]);
            if ($session->fetch()) continue;
            $sub->execute([$pin]);
            if ($sub->fetch()) continue;
            return $pin;
        }
        throw new RuntimeException('Unable to generate a unique PIN');
    }
}
