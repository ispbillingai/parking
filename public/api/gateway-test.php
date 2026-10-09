<?php
declare(strict_types=1);

/**
 * Admin: test that a device / gateway is reachable with the saved settings.
 *   { gateway: "pos" }       -> Ingenico status (RTS "Operative", or P17 's' command)
 *   { gateway: "dojo" }      -> DojoClient::testConnection(); with no terminal id
 *                               saved it lists the account's terminals instead
 *   { gateway: "cashmatic" } -> Cashmatic login (no payment started)
 *   { gateway: "printer" }   -> prints a test slip on the ticket printer
 *   { gateway: "fiscal" }    -> Epson RT queryPrinterStatus (no receipt)
 * Uses the SAVED config (config.php + admin overlay), so save before testing.
 */

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Auth;
use Parking\Admin\Settings;
use Parking\Cashmatic\Client as CashmaticClient;
use Parking\Db;
use Parking\Fiscal\Client as FiscalClient;
use Parking\I18n;
use Parking\Pos\DojoClient;
use Parking\Pos\Gateway;
use Parking\Printer\Thermal;

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
I18n::init($cfg['app']['default_lang'] ?? null);

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

if ($gw === 'cashmatic') {
    // A login proves the address and credentials reach the machine; it starts
    // no payment, so nothing is taken and no drawer opens.
    if (empty($cfg['cashmatic']['base_url'])) {
        echo json_encode(['ok' => false, 'error' => 'not_configured']);
        exit;
    }
    $r  = (new CashmaticClient($cfg['cashmatic']))->login();
    $ok = ($r['code'] ?? -1) === 0;
    echo json_encode(['ok' => $ok, 'state' => $ok ? 'login ok' : '',
                      'error' => $ok ? null : ($r['message'] ?? $r['error'] ?? 'login_failed')]);
    exit;
}
if ($gw === 'printer') {
    // Prints a short test slip on the ticket printer.
    $r = (new Thermal($cfg['printer'] ?? []))->printTest('TEST', [
        I18n::t('prn_test_line'),
        date('d/m/Y H:i:s'),
        ($cfg['printer']['host'] ?? '') . ':' . ($cfg['printer']['port'] ?? 9100),
        'àèìòù',
    ]);
    echo json_encode(['ok' => !empty($r['ok']), 'state' => !empty($r['ok']) ? ($r['bytes'] . ' bytes') : '',
                      'error' => $r['error'] ?? null]);
    exit;
}
if ($gw === 'fiscal') {
    // Status query only: no receipt is opened or printed.
    $r = (new FiscalClient($cfg['fiscal_printer'] ?? []))->status();
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $r['state'] ?? '', 'error' => $r['error'] ?? null]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_gateway']);
