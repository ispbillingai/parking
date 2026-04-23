<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Db;
use Parking\Gate\MqttPublisher;

header('Content-Type: application/json');

$pin = preg_replace('/\D/', '', (string) ($_REQUEST['pin'] ?? ''));
if (strlen($pin) !== 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad pin']);
    exit;
}

$pdo = Db::pdo($cfg['db']);
$pdo->beginTransaction();

$stmt = $pdo->prepare(
    'SELECT * FROM parking_sessions
     WHERE pin = ? AND status = "paid"
     FOR UPDATE'
);
$stmt->execute([$pin]);
$s = $stmt->fetch();

if (!$s) {
    $pdo->rollBack();
    Db::logEvent($pdo, null, $pin, 'denied', ['reason' => 'pin not paid or unknown']);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'denied']);
    exit;
}

$paidAt = new DateTimeImmutable($s['paid_at']);
$ttlMin = (int) ($cfg['app']['pin_ttl_after_pay_minutes'] ?? 15);
if ((new DateTimeImmutable())->getTimestamp() - $paidAt->getTimestamp() > $ttlMin * 60) {
    $pdo->prepare('UPDATE parking_sessions SET status = "expired" WHERE id = ?')->execute([$s['id']]);
    $pdo->commit();
    Db::logEvent($pdo, (int) $s['id'], $pin, 'denied', ['reason' => 'ttl expired']);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

$pdo->prepare('UPDATE parking_sessions SET status = "exited", exited_at = ? WHERE id = ?')
    ->execute([date('Y-m-d H:i:s'), $s['id']]);
$pdo->commit();

Db::logEvent($pdo, (int) $s['id'], $pin, 'scan_at_exit');

try {
    (new MqttPublisher($cfg['mqtt']))->publishRelayOpen();
    Db::logEvent($pdo, (int) $s['id'], $pin, 'gate_open');
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    Db::logEvent($pdo, (int) $s['id'], $pin, 'denied', [
        'reason' => 'mqtt error',
        'err'    => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'gate error']);
}
