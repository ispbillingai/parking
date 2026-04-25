<?php
declare(strict_types=1);

namespace Parking;

use PDO;

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(array $cfg): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['name'],
                $cfg['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function logEvent(
        PDO $pdo,
        ?int $sessionId,
        ?string $pin,
        string $eventType,
        array $details = [],
        ?int $subscriptionId = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO gate_events (session_id, subscription_id, pin, event_type, details)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $subscriptionId,
            $pin,
            $eventType,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
