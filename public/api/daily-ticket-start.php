<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Settings;
use Parking\Cashmatic\SessionClient;
use Parking\Db;
use Parking\Notify\Mailer;
use Parking\Pin\Generator;

$pdo = Db::pdo($cfg['db']);
$cfg = Settings::overlay($cfg, $pdo);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
$phone = preg_replace('/[^\d+]/', '', (string) ($body['phone'] ?? '')) ?: null;
$email = trim((string) ($body['email'] ?? '')) ?: null;
$name  = trim((string) ($body['name']  ?? '')) ?: null;

if (!$phone) {
    echo json_encode(['ok' => false, 'error' => 'phone_required']);
    exit;
}
if (!$email || !Mailer::isValid($email)) {
    echo json_encode(['ok' => false, 'error' => 'email_required']);
    exit;
}

$plan = $pdo->query(
    "SELECT id, price_cents, name FROM subscription_plans
     WHERE period = 'daily' AND active = 1
     ORDER BY id LIMIT 1"
)->fetch();
if (!$plan || (int) $plan['price_cents'] <= 0) {
    echo json_encode(['ok' => false, 'error' => 'no_active_daily_plan']);
    exit;
}

$pin    = Generator::unique($pdo);
$amount = (int) $plan['price_cents'];

$_SESSION['daily_ticket'] = [
    'pin'          => $pin,
    'phone'        => $phone,
    'email'        => $email,
    'name'         => $name,
    'plan_id'      => (int) $plan['id'],
    'plan_name'    => (string) $plan['name'],
    'amount_cents' => $amount,
    'started_at'   => time(),
];

$client = new SessionClient($cfg['cashmatic']);
$r = $client->startPayment($amount, $pin);

if (($r['code'] ?? -1) !== 0) {
    unset($_SESSION['daily_ticket']);
    echo json_encode([
        'ok'    => false,
        'error' => $r['message'] ?? 'StartPayment failed',
        'code'  => $r['code'] ?? -1,
    ]);
    exit;
}

echo json_encode([
    'ok'           => true,
    'amount_cents' => $amount,
]);
