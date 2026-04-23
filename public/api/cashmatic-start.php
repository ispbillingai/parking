<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));
$amount = (int) ($body['amount_cents'] ?? 0);

if (strlen($pin) !== 6 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad request']);
    exit;
}

$client = new SessionClient($cfg['cashmatic']);
$r = $client->startPayment($amount, $pin);

if (($r['code'] ?? -1) !== 0) {
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'StartPayment failed',
        'code'  => $r['code'] ?? -1,
    ]);
    exit;
}

echo json_encode(['ok' => true]);
