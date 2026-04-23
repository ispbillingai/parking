<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;
use Parking\Payment\Confirmer;

header('Content-Type: application/json');

$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pin    = preg_replace('/\D/', '', (string) ($body['pin'] ?? ''));
$amount = (int) ($body['amount_cents'] ?? 0);

if (strlen($pin) !== 6 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'missing pin or amount']);
    exit;
}

$client = new SessionClient($cfg['cashmatic']);

$r = $client->lastTransaction();
if (($r['code'] ?? -1) !== 0) {
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'LastTransaction failed',
    ]);
    exit;
}

$d   = $r['data'] ?? [];
$end = $d['end'] ?? '?';

if ($end !== 'normal') {
    echo json_encode([
        'ok'    => false,
        'error' => "payment ended as '{$end}'",
        'end'   => $end,
    ]);
    exit;
}

$cmTxId      = isset($d['id']) ? (int) $d['id'] : null;
$notDisp     = (int) ($d['notDispensed'] ?? 0);

$res = (new Confirmer($cfg))->confirm($pin, $amount, $cmTxId);

if (!$res['ok']) {
    echo json_encode([
        'ok'    => false,
        'error' => $res['error'] ?? 'confirm failed',
    ]);
    exit;
}

echo json_encode([
    'ok'           => true,
    'end'          => 'normal',
    'amount_cents' => $amount,
    'notDispensed' => $notDisp,
    'cashmatic_id' => $cmTxId,
]);
