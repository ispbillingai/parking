<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\I18n;
use Parking\Notify\Dispatcher;
use Parking\Notify\Mailer;
use Parking\Pin\Generator;

$pdo  = Db::pdo($cfg['db']);
$cfg  = Settings::overlay($cfg, $pdo);
$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$result = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $delivery = (string) ($_POST['delivery'] ?? 'print'); // print | whatsapp | email | both
    $name     = trim((string) ($_POST['name'] ?? ''));
    $emailRaw = trim((string) ($_POST['email'] ?? ''));
    $phoneRaw = (string) ($_POST['phone'] ?? '');
    $phone    = preg_replace('/[^\d+]/', '', $phoneRaw);
    $email    = $emailRaw !== '' ? $emailRaw : null;

    if (in_array($delivery, ['whatsapp','both'], true) && !$phone) {
        $error = I18n::t('totem_err_phone_required');
    } elseif (in_array($delivery, ['email','both'], true) && (!$email || !Mailer::isValid($email))) {
        $error = I18n::t('totem_err_email_required');
    } else {
        $now = new DateTimeImmutable();
        $pin = Generator::unique($pdo);

        $customerId = null;
        if ($name !== '' || $email || $phone) {
            $find = $pdo->prepare(
                'SELECT id FROM customers
                 WHERE (email IS NOT NULL AND email = ?) OR (phone IS NOT NULL AND phone = ?)
                 LIMIT 1'
            );
            $find->execute([$email, $phone]);
            $row = $find->fetch();
            if ($row) {
                $customerId = (int) $row['id'];
                $pdo->prepare(
                    'UPDATE customers
                     SET full_name = COALESCE(NULLIF(?, ""), full_name),
                         email = COALESCE(?, email),
                         phone = COALESCE(?, phone)
                     WHERE id = ?'
                )->execute([$name, $email, $phone ?: null, $customerId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO customers (full_name, email, phone) VALUES (?,?,?)'
                )->execute([$name, $email, $phone ?: null]);
                $customerId = (int) $pdo->lastInsertId();
            }
        }

        $deliveryChan = match ($delivery) {
            'whatsapp', 'email' => $delivery,
            'both'              => 'email',
            default             => 'print',
        };

        $stmt = $pdo->prepare(
            'INSERT INTO parking_sessions
                (pin, entered_at, customer_id, customer_phone, customer_email,
                 entry_channel, delivery_channel, status)
             VALUES (?, ?, ?, ?, ?, "totem", ?, "active")'
        );
        $stmt->execute([
            $pin, $now->format('Y-m-d H:i:s'), $customerId,
            $phone ?: null, $email, $deliveryChan,
        ]);
        $sessionId = (int) $pdo->lastInsertId();
        Db::logEvent($pdo, $sessionId, $pin, 'entry', [
            'channel'  => 'totem',
            'delivery' => $delivery,
            'customer' => $customerId,
        ]);

        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($pin);

        $delivered = ['whatsapp' => null, 'email' => null];
        if ($delivery !== 'print') {
            $sendPhone = in_array($delivery, ['whatsapp','both'], true) ? $phone : null;
            $sendEmail = in_array($delivery, ['email','both'], true) ? $email : null;
            $delivered = (new Dispatcher($cfg))->sendTicket(
                $pdo, $sessionId, $pin,
                $now->format('d/m/Y H:i'),
                $sendPhone, $sendEmail, $qrUrl,
                ['brand' => I18n::t('entrance_title')],
                $name !== '' ? $name : null
            );
        }

        $result = [
            'pin'        => $pin,
            'qr_url'     => $qrUrl,
            'entered_at' => $now->format('d/m/Y H:i'),
            'phone'      => $phone,
            'email'      => $email,
            'delivery'   => $delivery,
            'delivered'  => $delivered,
        ];
    }
}
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('totem_title')) ?></title>
<style>
:root{--bg:#0b1020;--bg-2:#0f1530;--card:rgba(255,255,255,.04);--border:rgba(255,255,255,.1);--text:#e7ecf5;--muted:#9aa4bf;--accent:#5eead4;--accent-2:#38bdf8;--ok:#34d399;--err:#f87171}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);
  background:radial-gradient(1100px 700px at 10% -10%,#1b2555 0%,transparent 55%),radial-gradient(900px 600px at 110% 110%,#0b3b53 0%,transparent 50%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.box{
  width:100%;max-width:520px;padding:30px 28px;border-radius:24px;border:1px solid var(--border);
  background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));
  box-shadow:0 30px 80px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.06);
  backdrop-filter:blur(10px);
}
.brand{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:30px;margin:14px 0 6px;letter-spacing:-.01em}
p.sub{color:var(--muted);margin:0 0 20px;font-size:14px}
.choices{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
.choice{
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
  padding:14px 8px;border:1px solid var(--border);border-radius:12px;cursor:pointer;
  background:rgba(255,255,255,.03);font-size:13px;font-weight:600;color:var(--text);
  transition:border-color .15s ease,background .15s ease,transform .12s ease;
}
.choice:hover{transform:translateY(-1px);background:rgba(255,255,255,.06)}
.choice.active{border-color:var(--accent);background:rgba(94,234,212,.10);color:var(--text)}
.choice .ico{font-size:22px}
label{display:block;color:var(--muted);font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin:12px 0 6px}
input{
  width:100%;font-size:16px;padding:13px 14px;border-radius:10px;
  background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--border);outline:none;
}
input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
button.go{
  margin-top:20px;width:100%;font-size:17px;padding:14px;border-radius:12px;border:none;font-weight:700;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;
  box-shadow:0 12px 30px rgba(94,234,212,.25);
}
.err{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.35);color:#fecaca;padding:10px 12px;border-radius:10px;font-size:13px;margin-top:12px}
.ok-card{text-align:center}
.pin{font-size:48px;font-weight:800;letter-spacing:10px;margin:8px 0;color:var(--text);font-variant-numeric:tabular-nums}
.qr-wrap{display:inline-block;padding:12px;border-radius:14px;background:#fff;margin:6px 0 8px}
.qr-wrap img{display:block;width:200px;height:200px}
.delivered{margin-top:12px;display:flex;flex-direction:column;gap:6px}
.delivered .pill{display:inline-block;padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600}
.pill.ok{background:linear-gradient(135deg,#34d399,#10b981);color:#062b1f}
.pill.fail{background:rgba(248,113,113,.20);color:#fecaca;border:1px solid rgba(248,113,113,.4)}
.lang-switch{position:fixed;top:18px;right:18px;display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card);backdrop-filter:blur(8px)}
.lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
.muted{color:var(--muted);font-size:13px}
.actions{display:flex;gap:10px;justify-content:center;margin-top:18px}
.btn{padding:10px 18px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-weight:600;text-decoration:none;cursor:pointer}
.btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;border:none}
@media (max-width:760px){
  body{padding:14px;align-items:flex-start;padding-top:64px}
  .box{padding:22px 18px;border-radius:18px}
  h1{font-size:24px;margin-top:10px}
  p.sub{font-size:13px}
  .row{grid-template-columns:1fr;gap:6px}
  .choices{grid-template-columns:repeat(3,1fr);gap:6px}
  .choice{padding:10px 4px;font-size:11px}
  .choice .ico{font-size:18px}
  input{font-size:15px;padding:11px 12px}
  button.go{font-size:15px;padding:13px}
  .pin{font-size:38px;letter-spacing:8px}
  .qr-wrap img{width:170px;height:170px}
  .lang-switch{top:10px;right:10px;padding:3px}
  .lang-switch a{padding:5px 10px;font-size:11px}
}
@media (max-width:420px){
  .pin{font-size:32px;letter-spacing:6px}
  h1{font-size:22px}
}
</style>
</head>
<body>
<nav class="lang-switch" aria-label="Language">
  <?php foreach (I18n::labels() as $label => $code): ?>
    <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($result): ?>
  <div class="box ok-card">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('totem_brand')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('totem_ticket_ready')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('entrance_entry_time')) ?>: <?= htmlspecialchars($result['entered_at']) ?></p>
    <div class="qr-wrap"><img src="<?= htmlspecialchars($result['qr_url']) ?>" alt="QR"></div>
    <div class="muted"><?= htmlspecialchars(I18n::t('entrance_pin')) ?></div>
    <div class="pin"><?= htmlspecialchars($result['pin']) ?></div>

    <div class="delivered">
      <?php if ($result['delivered']['whatsapp'] === true): ?>
        <span class="pill ok">&#10003; WhatsApp &rarr; <?= htmlspecialchars($result['phone']) ?></span>
      <?php elseif ($result['delivered']['whatsapp'] === false): ?>
        <span class="pill fail">&#10007; WhatsApp <?= htmlspecialchars(I18n::t('totem_send_failed')) ?></span>
      <?php endif; ?>
      <?php if ($result['delivered']['email'] === true): ?>
        <span class="pill ok">&#10003; Email &rarr; <?= htmlspecialchars($result['email']) ?></span>
      <?php elseif ($result['delivered']['email'] === false): ?>
        <span class="pill fail">&#10007; Email <?= htmlspecialchars(I18n::t('totem_send_failed')) ?></span>
      <?php endif; ?>
    </div>

    <div class="actions">
      <button class="btn" onclick="window.print()"><?= htmlspecialchars(I18n::t('totem_print')) ?></button>
      <a class="btn primary" href="totem.php"><?= htmlspecialchars(I18n::t('totem_new')) ?></a>
    </div>
  </div>
<?php else: ?>
  <form class="box" method="post">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('totem_brand')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('totem_title')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('totem_subtitle')) ?></p>

    <div class="choices" role="radiogroup">
      <label class="choice active">
        <input type="radio" name="delivery" value="print" checked hidden>
        <span class="ico">&#x1F5A8;</span><?= htmlspecialchars(I18n::t('totem_print_only')) ?>
      </label>
      <label class="choice">
        <input type="radio" name="delivery" value="whatsapp" hidden>
        <span class="ico">&#x1F4AC;</span>WhatsApp
      </label>
      <label class="choice">
        <input type="radio" name="delivery" value="email" hidden>
        <span class="ico">&#x2709;&#xFE0F;</span>Email
      </label>
    </div>

    <label><?= htmlspecialchars(I18n::t('totem_name_optional')) ?></label>
    <input name="name" placeholder="<?= htmlspecialchars(I18n::t('totem_name_ph')) ?>" autocomplete="name">

    <div class="row" id="contactFields">
      <div>
        <label><?= htmlspecialchars(I18n::t('totem_phone')) ?></label>
        <input name="phone" placeholder="+39..." inputmode="tel" autocomplete="tel">
      </div>
      <div>
        <label><?= htmlspecialchars(I18n::t('totem_email')) ?></label>
        <input name="email" type="email" placeholder="you@example.com" autocomplete="email">
      </div>
    </div>

    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <button class="go" type="submit"><?= htmlspecialchars(I18n::t('totem_get_ticket')) ?></button>
  </form>

  <script>
  document.querySelectorAll('.choice').forEach(c => {
    c.addEventListener('click', () => {
      document.querySelectorAll('.choice').forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      c.querySelector('input').checked = true;
    });
  });
  </script>
<?php endif; ?>
</body>
</html>
