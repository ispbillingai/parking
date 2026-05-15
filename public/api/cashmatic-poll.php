<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Cashmatic\SessionClient;

header('Content-Type: application/json');

$client = new SessionClient($cfg['cashmatic']);
$r = $client->activeTransaction();

// Full raw Cashmatic reply. This is the line that reveals WHY a payment
// does not complete: the live `operation` state and the amount fields.
// The front-end (cashier-pay.php) only proceeds to "finish" when
// operation === 'idle'; anything else makes it keep polling forever.
error_log('[cashmatic-poll] ActiveTransaction RAW: '
    . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (($r['code'] ?? -1) !== 0) {
    error_log('[cashmatic-poll] NON-ZERO CODE code=' . ($r['code'] ?? -1)
        . ' message=' . ($r['message'] ?? '?')
        . ' -> returning ok:false (front-end shows error and KEEPS POLLING — never finishes)');
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'ActiveTransaction failed',
        'code'  => $r['code'] ?? -1,
    ]);
    exit;
}

$d = is_array($r['data'] ?? null) ? $r['data'] : [];

$out = [
    'ok'           => true,
    'operation'    => $d['operation']    ?? 'idle',
    'requested'    => (int) ($d['requested']    ?? 0),
    'inserted'     => (int) ($d['inserted']     ?? 0),
    'dispensed'    => (int) ($d['dispensed']    ?? 0),
    'notDispensed' => (int) ($d['notDispensed'] ?? 0),
];

error_log('[cashmatic-poll] data keys=[' . implode(',', array_keys($d)) . ']'
    . ' operation=' . var_export($d['operation'] ?? null, true)
    . ' requested=' . $out['requested']
    . ' inserted='  . $out['inserted']
    . ' dispensed=' . $out['dispensed']
    . ' notDispensed=' . $out['notDispensed']
    . ' => front-end will ' . ($out['operation'] === 'idle'
        ? 'FINISH (operation idle)'
        : 'KEEP POLLING (operation is not "idle")'));

echo json_encode($out);
