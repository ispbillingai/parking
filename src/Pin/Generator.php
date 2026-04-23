<?php
declare(strict_types=1);

namespace Parking\Pin;

use PDO;
use RuntimeException;

class Generator
{
    public static function unique(PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM parking_sessions
             WHERE pin = ? AND status IN ("active","paid") LIMIT 1'
        );
        for ($i = 0; $i < 25; $i++) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $stmt->execute([$pin]);
            if (!$stmt->fetch()) {
                return $pin;
            }
        }
        throw new RuntimeException('Unable to generate a unique PIN');
    }
}
