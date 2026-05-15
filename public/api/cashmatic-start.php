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

error_log('[cashmatic-start] REQUEST pin=' . $pin . ' amount_cents=' . $amount);

if (strlen($pin) !== 6 || $amount <= 0) {
    error_log('[cashmatic-start] REJECTED bad request pin=' . $pin . ' amount=' . $amount);
    echo json_encode(['ok' => false, 'error' => 'bad request']);
    exit;
}

$client = new SessionClient($cfg['cashmatic']);
$r = $client->startPayment($amount, $pin);

error_log('[cashmatic-start] StartPayment RAW: '
    . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (($r['code'] ?? -1) !== 0) {
    error_log('[cashmatic-start] FAILED code=' . ($r['code'] ?? -1)
        . ' message=' . ($r['message'] ?? '?'));
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'StartPayment failed',
        'code'  => $r['code'] ?? -1,
    ]);
    exit;
}

error_log('[cashmatic-start] OK payment started pin=' . $pin . ' amount_cents=' . $amount);
echo json_encode(['ok' => true]);
