<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Db;
use Parking\Tariff\Calculator;

header('Content-Type: application/json');

$pin = preg_replace('/\D/', '', (string) ($_REQUEST['pin'] ?? ''));
if (strlen($pin) !== 6) {
    echo json_encode(['ok' => false, 'error' => 'PIN non valido']);
    exit;
}

$pdo = Db::pdo($cfg['db']);
$stmt = $pdo->prepare(
    'SELECT * FROM parking_sessions
     WHERE pin = ? AND status IN ("active","paid")
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$pin]);
$s = $stmt->fetch();

if (!$s) {
    echo json_encode(['ok' => false, 'error' => 'Ticket non trovato']);
    exit;
}

$entered = new DateTimeImmutable($s['entered_at']);
$now     = new DateTimeImmutable();
$minutes = (int) floor(($now->getTimestamp() - $entered->getTimestamp()) / 60);
$dur     = sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);

if ($s['status'] === 'paid') {
    echo json_encode([
        'ok'                => true,
        'pin'               => $pin,
        'session_id'        => (int) $s['id'],
        'already_paid'      => true,
        'amount_cents'      => 0,
        'paid_amount_cents' => (int) ($s['amount_cents'] ?? 0),
        'entered_at_human'  => $entered->format('d/m/Y H:i'),
        'duration_human'    => $dur,
    ]);
    exit;
}

$calc   = new Calculator($cfg['tariff']);
$amount = $calc->amountCents($entered, $now);

Db::logEvent($pdo, (int) $s['id'], $pin, 'scan_at_pay', [
    'amount_cents' => $amount,
    'minutes'      => $minutes,
]);

echo json_encode([
    'ok'               => true,
    'pin'              => $pin,
    'session_id'       => (int) $s['id'],
    'amount_cents'     => $amount,
    'entered_at_human' => $entered->format('d/m/Y H:i'),
    'duration_human'   => $dur,
]);
