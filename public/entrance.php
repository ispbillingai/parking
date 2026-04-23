<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Db;
use Parking\I18n;
use Parking\Notify\TextMeBot;
use Parking\Pin\Generator;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$pdo = Db::pdo($cfg['db']);
$now = new DateTimeImmutable();

$phoneRaw = (string) ($_REQUEST['phone'] ?? '');
$phone = preg_replace('/[^\d+]/', '', $phoneRaw);
$phone = $phone === '' ? null : $phone;

$pin = Generator::unique($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO parking_sessions (pin, entered_at, customer_phone, status)
     VALUES (?, ?, ?, "active")'
);
$stmt->execute([$pin, $now->format('Y-m-d H:i:s'), $phone]);
$sessionId = (int) $pdo->lastInsertId();
Db::logEvent($pdo, $sessionId, $pin, 'entry', $phone ? ['phone' => $phone] : []);

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($pin);
$serial = 'N° ' . str_pad((string) $sessionId, 6, '0', STR_PAD_LEFT);
$plate  = '—';

$waSent = false;
if ($phone && !empty($cfg['textmebot']['api_key'])) {
    $msg = I18n::t('entrance_title') . "\n"
         . I18n::t('entrance_entry') . ' ' . $now->format('d/m/Y H:i') . "\n"
         . I18n::t('entrance_pin') . ": $pin\n" . $qrUrl;
    $res = (new TextMeBot($cfg['textmebot']))->sendWhatsapp($phone, $msg);
    $waSent = (bool) $res['ok'];
    Db::logEvent(
        $pdo,
        $sessionId,
        $pin,
        $waSent ? 'whatsapp_sent' : 'whatsapp_fail',
        ['phone' => $phone, 'http' => $res['http'] ?? null]
    );
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'pin'        => $pin,
        'qr_url'     => $qrUrl,
        'entered_at' => $now->format(DATE_ATOM),
        'phone'      => $phone,
        'whatsapp'   => $waSent,
    ]);
    exit;
}
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('entrance_title')) ?></title>
<style>
  :root{
    --bg:#0b1020;
    --bg-2:#0f1530;
    --card:rgba(255,255,255,.04);
    --border:rgba(255,255,255,.1);
    --text:#e7ecf5;
    --muted:#9aa4bf;
    --accent:#5eead4;
    --accent-2:#38bdf8;
    --ok:#34d399;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    min-height:100vh;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
    color:var(--text);
    background:
      radial-gradient(1100px 700px at 10% -10%, #1b2555 0%, transparent 55%),
      radial-gradient(900px 600px at 110% 110%, #0b3b53 0%, transparent 50%),
      linear-gradient(180deg,var(--bg) 0%, var(--bg-2) 100%);
    display:flex;align-items:center;justify-content:center;padding:24px;
  }
  .ticket{
    width:100%;max-width:420px;text-align:center;
    padding:30px 28px 24px;
    border-radius:24px;
    border:1px solid var(--border);
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));
    box-shadow:0 30px 80px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.06);
    backdrop-filter:blur(10px);
  }
  .brand{
    display:inline-flex;align-items:center;gap:8px;
    padding:6px 12px;border:1px solid var(--border);border-radius:999px;
    color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase;
  }
  .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
  h1{font-size:30px;margin:14px 0 4px;letter-spacing:-.01em}
  .serial{color:var(--muted);margin:0 0 4px;font-size:12px;letter-spacing:.22em;text-transform:uppercase}
  .sep{
    height:1px;margin:14px -4px;
    background-image:linear-gradient(90deg,var(--border) 50%,transparent 0);
    background-size:10px 1px;background-repeat:repeat-x;
  }
  .meta{display:flex;flex-direction:column;gap:10px;margin:6px 0 4px;text-align:left}
  .row{display:flex;align-items:baseline;gap:10px}
  .row .k{
    color:var(--muted);font-size:11px;letter-spacing:.18em;text-transform:uppercase;
    flex:0 0 auto;white-space:nowrap;
  }
  .row .fill{
    flex:1 1 auto;height:1px;align-self:end;transform:translateY(-4px);
    background-image:linear-gradient(90deg,var(--border) 50%,transparent 0);
    background-size:6px 1px;background-repeat:repeat-x;
  }
  .row .v{
    flex:0 0 auto;color:var(--text);font-size:15px;font-weight:700;
    font-variant-numeric:tabular-nums;letter-spacing:.04em;
  }
  .qr-wrap{
    display:inline-block;padding:12px;border-radius:16px;margin:4px 0 2px;
    background:#fff;box-shadow:0 10px 30px rgba(94,234,212,.15);
  }
  .qr-wrap img{display:block;width:240px;height:240px}
  .pin-label{color:var(--muted);font-size:11px;letter-spacing:.22em;text-transform:uppercase;margin-top:14px}
  .pin{
    font-size:46px;font-weight:800;letter-spacing:10px;margin:4px 0 2px;
    color:var(--text);font-variant-numeric:tabular-nums;
  }
  .note{color:var(--muted);font-size:13px;margin:10px auto 0;max-width:320px;line-height:1.5}
  .wa{
    margin-top:14px;color:#062b1f;
    background:linear-gradient(135deg,#34d399,#10b981);
    padding:10px 14px;border-radius:12px;font-size:13px;font-weight:600;
    box-shadow:0 8px 24px rgba(16,185,129,.25);
  }
  .noprint{margin-top:22px}
  .noprint button{
    font-size:15px;padding:12px 22px;border-radius:12px;cursor:pointer;
    border:1px solid var(--border);
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    color:#0b1020;font-weight:700;letter-spacing:.02em;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .noprint button:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(94,234,212,.25)}
  .lang-switch{
    position:fixed;top:18px;right:18px;display:flex;gap:4px;
    padding:4px;border:1px solid var(--border);border-radius:999px;
    background:rgba(255,255,255,.04);backdrop-filter:blur(8px);
  }
  .lang-switch a{
    display:inline-block;padding:6px 12px;border-radius:999px;
    color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.08em;
    text-decoration:none;transition:color .15s ease, background .15s ease;
  }
  .lang-switch a:hover{color:var(--text)}
  .lang-switch a.active{
    background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;
  }
  @media print{
    @page{size:80mm auto;margin:6mm}
    body{background:#fff;color:#000;display:block;padding:0}
    .ticket{
      border:2px solid #000;border-radius:0;box-shadow:none;background:#fff;color:#000;
      max-width:none;width:auto;padding:14px 14px 16px;
    }
    .brand,.noprint,.lang-switch{display:none}
    h1{color:#000;font-size:22px}
    .serial,.pin-label,.note{color:#000}
    .row .k{color:#000}
    .row .v{color:#000}
    .row .fill,.sep{background-image:linear-gradient(90deg,#000 50%,transparent 0);opacity:.5}
    .qr-wrap{box-shadow:none;padding:0;border-radius:0}
    .qr-wrap img{width:55mm;height:55mm}
    .pin{
      color:#000;-webkit-text-fill-color:#000;background:none;
      font-size:40px;letter-spacing:8px;
      border:2px solid #000;border-radius:0;padding:6px 10px;display:inline-block;
    }
    .wa{background:none;color:#000;border:1px dashed #000;box-shadow:none}
  }
</style>
</head>
<body onload="window.print()">
  <nav class="lang-switch" aria-label="Language">
    <?php foreach (I18n::labels() as $label => $code): ?>
      <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="ticket">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('brand_ticket')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('entrance_heading')) ?></h1>
    <div class="serial"><?= htmlspecialchars($serial) ?></div>
    <div class="sep"></div>
    <div class="meta">
      <div class="row">
        <span class="k"><?= htmlspecialchars(I18n::t('entrance_entry_time')) ?></span>
        <span class="fill"></span>
        <span class="v"><?= htmlspecialchars($now->format('d/m/Y H:i')) ?></span>
      </div>
      <div class="row">
        <span class="k"><?= htmlspecialchars(I18n::t('entrance_plate')) ?></span>
        <span class="fill"></span>
        <span class="v"><?= htmlspecialchars($plate) ?></span>
      </div>
    </div>
    <div class="sep"></div>
    <div class="qr-wrap"><img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR code"></div>
    <div class="pin-label"><?= htmlspecialchars(I18n::t('entrance_pin')) ?></div>
    <div class="pin"><?= htmlspecialchars($pin) ?></div>
    <div class="sep"></div>
    <div class="note"><?= htmlspecialchars(I18n::t('entrance_note')) ?></div>
    <?php if ($waSent): ?><div class="wa"><?= htmlspecialchars(I18n::t('entrance_whatsapp', ['phone' => $phone])) ?></div><?php endif; ?>
    <div class="noprint"><button onclick="location.href='entrance.php'"><?= htmlspecialchars(I18n::t('entrance_new')) ?></button></div>
  </div>
</body>
</html>
