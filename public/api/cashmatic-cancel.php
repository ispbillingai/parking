<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;

header('Content-Type: application/json');

$client = new SessionClient($cfg['cashmatic']);
$r = $client->cancelPayment();
$client->clearTransaction();

if (($r['code'] ?? -1) !== 0) {
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'CancelPayment failed',
    ]);
    exit;
}

echo json_encode(['ok' => true]);
