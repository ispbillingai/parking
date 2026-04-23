<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;

header('Content-Type: application/json');

$client = new SessionClient($cfg['cashmatic']);
$r = $client->activeTransaction();

if (($r['code'] ?? -1) !== 0) {
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'ActiveTransaction failed',
        'code'  => $r['code'] ?? -1,
    ]);
    exit;
}

$d = $r['data'] ?? [];
echo json_encode([
    'ok'           => true,
    'operation'    => $d['operation']    ?? 'idle',
    'requested'    => (int) ($d['requested']    ?? 0),
    'inserted'     => (int) ($d['inserted']     ?? 0),
    'dispensed'    => (int) ($d['dispensed']    ?? 0),
    'notDispensed' => (int) ($d['notDispensed'] ?? 0),
]);
