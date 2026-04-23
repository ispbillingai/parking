<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Payment\Confirmer;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));
$amount = (int) ($body['amount_cents'] ?? 0);
$cmId   = isset($body['cashmatic_transaction_id'])
    ? (int) $body['cashmatic_transaction_id']
    : null;

if (strlen($pin) !== 6) {
    echo json_encode(['ok' => false, 'error' => 'bad pin']);
    exit;
}

echo json_encode((new Confirmer($cfg))->confirm($pin, $amount, $cmId));
