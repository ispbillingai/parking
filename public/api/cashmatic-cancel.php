<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;

header('Content-Type: application/json');

$client = new SessionClient($cfg['cashmatic']);
$r = $client->cancelPayment();

error_log('[cashmatic-cancel] CancelPayment RAW: '
    . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$client->clearTransaction();

if (($r['code'] ?? -1) !== 0) {
    error_log('[cashmatic-cancel] FAILED code=' . ($r['code'] ?? -1)
        . ' message=' . ($r['message'] ?? '?'));
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'CancelPayment failed',
    ]);
    exit;
}

error_log('[cashmatic-cancel] OK cancelled');
echo json_encode(['ok' => true]);
