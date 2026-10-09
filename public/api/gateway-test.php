<?php
declare(strict_types=1);

/**
 * Admin: test that a card gateway's credentials reach the provider.
 *   { gateway: "pos" }  -> Ingenico status (RTS "Operative", or P17 's' command)
 *   { gateway: "dojo" } -> DojoClient::testConnection(); with no terminal id
 *                          saved it lists the account's terminals instead
 * Uses the SAVED config (config.php + admin overlay), so save before testing.
 */

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Auth;
use Parking\Admin\Settings;
use Parking\Db;
use Parking\Pos\DojoClient;
use Parking\Pos\Gateway;

header('Content-Type: application/json');

if (!Auth::user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$gw    = (string) ($input['gateway'] ?? '');

if ($gw === 'pos') {
    $r = Gateway::posClient($cfg['pos'] ?? [])->status();
    $state = (string) ($r['state'] ?? '');
    if (!empty($r['releases'])) {
        $state .= ' · ' . $r['releases'];
    }
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $state, 'error' => $r['error'] ?? null]);
    exit;
}
if ($gw === 'dojo') {
    $r = (new DojoClient($cfg['dojo'] ?? []))->testConnection();
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $r['state'] ?? '', 'error' => $r['error'] ?? null,
                      'terminals' => $r['terminals'] ?? null]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_gateway']);
