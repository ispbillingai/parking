<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Db;
use Parking\Gate\MqttPublisher;
use Parking\I18n;
use Parking\Subscription\Scheduler;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$pdo = Db::pdo($cfg['db']);

$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['key'])) {
    $keyCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($_POST['key'] ?? $_GET['key'] ?? ''))));

    if ($keyCode === '') {
        $result = ['ok' => false, 'reason' => 'empty_key'];
    } else {
        $stmt = $pdo->prepare(
            'SELECT s.*, c.full_name, c.plate, p.name AS plan_name, p.period
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.key_code = ? LIMIT 1'
        );
        $stmt->execute([$keyCode]);
        $sub = $stmt->fetch();

        if (!$sub) {
            Db::logEvent($pdo, null, null, 'denied', ['reason' => 'subscription not found', 'key' => $keyCode]);
            $result = ['ok' => false, 'reason' => 'unknown_key'];
        } else {
            $today = date('Y-m-d');
            $reason = null;
            if ($sub['status'] !== 'active') $reason = 'inactive';
            elseif ($sub['ends_on'] < $today) $reason = 'expired';

            $overdue = Scheduler::overdueCents($pdo, (int) $sub['id']);
            $blockOverdue = (int) ($cfg['app']['subscription_block_overdue'] ?? 1);
            if (!$reason && $blockOverdue && $overdue > 0) $reason = 'overdue';

            if ($reason) {
                Db::logEvent($pdo, null, null, 'denied', [
                    'reason' => $reason,
                    'key'    => $keyCode,
                    'overdue_cents' => $overdue,
                ], (int) $sub['id']);
                $result = [
                    'ok'      => false,
                    'reason'  => $reason,
                    'sub'     => $sub,
                    'overdue' => $overdue,
                ];
            } else {
                Db::logEvent($pdo, null, null, 'subscription_entry', [
                    'key'      => $keyCode,
                    'customer' => $sub['full_name'],
                ], (int) $sub['id']);
                try {
                    (new MqttPublisher($cfg['mqtt']))->publishRelayOpen();
                    Db::logEvent($pdo, null, null, 'gate_open', ['key' => $keyCode], (int) $sub['id']);
                    $result = ['ok' => true, 'sub' => $sub];
                } catch (Throwable $e) {
                    Db::logEvent($pdo, null, null, 'denied', [
                        'reason' => 'mqtt_error', 'err' => $e->getMessage(),
                    ], (int) $sub['id']);
                    $result = ['ok' => false, 'reason' => 'gate_error'];
                }
            }
        }
    }
}

$cur = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('subentry_title')) ?></title>
<style>
:root{--bg:#0b1020;--bg-2:#0f1530;--card:rgba(255,255,255,.04);--border:rgba(255,255,255,.1);--text:#e7ecf5;--muted:#9aa4bf;--accent:#5eead4;--accent-2:#38bdf8;--ok:#34d399;--err:#f87171;--warn:#fbbf24}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);
  background:radial-gradient(1100px 700px at 10% -10%,#1b2555 0%,transparent 55%),radial-gradient(900px 600px at 110% 110%,#0b3b53 0%,transparent 50%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.box{
  width:100%;max-width:460px;padding:30px 28px;border-radius:24px;border:1px solid var(--border);
  background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));
  box-shadow:0 30px 80px rgba(0,0,0,.45);
  text-align:center;
}
.brand{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:28px;margin:14px 0 8px}
p.sub{color:var(--muted);margin:0 0 18px;font-size:14px}
input{
  width:100%;font-size:32px;padding:16px 12px;letter-spacing:8px;text-align:center;font-weight:700;
  border-radius:12px;background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--border);outline:none;
  font-variant-numeric:tabular-nums;text-transform:uppercase;
}
input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
button{
  margin-top:18px;width:100%;font-size:17px;padding:14px;border-radius:12px;border:none;font-weight:700;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;
  box-shadow:0 12px 30px rgba(94,234,212,.25);
}
.icon-circle{width:96px;height:96px;border-radius:50%;display:grid;place-items:center;font-size:54px;font-weight:900;margin:6px auto 14px}
.icon-ok{background:linear-gradient(135deg,#34d399,#10b981);color:#04231a;box-shadow:0 14px 40px rgba(16,185,129,.4)}
.icon-fail{background:linear-gradient(135deg,#f87171,#ef4444);color:#2a0707;box-shadow:0 14px 40px rgba(239,68,68,.4)}
.icon-warn{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#3a2700;box-shadow:0 14px 40px rgba(245,158,11,.4)}
.detail{padding:10px 14px;border:1px solid var(--border);border-radius:12px;margin-top:12px;font-size:14px;text-align:left}
.detail .k{color:var(--muted);font-size:11px;letter-spacing:.14em;text-transform:uppercase}
.actions{display:flex;gap:10px;margin-top:18px;justify-content:center}
.btn{padding:10px 18px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-weight:600;text-decoration:none;cursor:pointer}
.btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;border:none}
.lang-switch{position:fixed;top:18px;right:18px;display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card);backdrop-filter:blur(8px)}
.lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
@media (max-width:760px){
  body{padding:14px;align-items:flex-start;padding-top:64px}
  .box{padding:22px 18px;border-radius:18px}
  h1{font-size:22px}
  input{font-size:24px;letter-spacing:5px;padding:14px 10px}
  button{font-size:15px;padding:13px}
  .icon-circle{width:78px;height:78px;font-size:42px}
  .detail{font-size:13px}
  .lang-switch{top:10px;right:10px;padding:3px}
  .lang-switch a{padding:5px 10px;font-size:11px}
}
@media (max-width:420px){
  input{font-size:20px;letter-spacing:4px}
  h1{font-size:20px}
}
</style>
</head>
<body>
<nav class="lang-switch" aria-label="Language">
  <?php foreach (I18n::labels() as $label => $code): ?>
    <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($result && $result['ok']): ?>
  <div class="box">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('subentry_brand')) ?></span>
    <div class="icon-circle icon-ok">&#10003;</div>
    <h1><?= htmlspecialchars(I18n::t('subentry_welcome')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('subentry_gate_open')) ?></p>
    <div class="detail">
      <div class="k"><?= htmlspecialchars(I18n::t('sub_customer')) ?></div>
      <div><strong><?= htmlspecialchars($result['sub']['full_name']) ?></strong></div>
    </div>
    <div class="detail">
      <div class="k"><?= htmlspecialchars(I18n::t('sub_plan')) ?></div>
      <div><?= htmlspecialchars($result['sub']['plan_name']) ?> · <?= htmlspecialchars(I18n::t('period_' . $result['sub']['period'])) ?></div>
    </div>
    <div class="detail">
      <div class="k"><?= htmlspecialchars(I18n::t('sub_ends')) ?></div>
      <div><?= htmlspecialchars($result['sub']['ends_on']) ?></div>
    </div>
    <div class="actions">
      <a class="btn primary" href="subscriber-entry.php"><?= htmlspecialchars(I18n::t('subentry_again')) ?></a>
    </div>
  </div>
<?php elseif ($result): ?>
  <div class="box">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('subentry_brand')) ?></span>
    <?php
      $isOverdue = $result['reason'] === 'overdue';
      $iconClass = $isOverdue ? 'icon-warn' : 'icon-fail';
      $glyph     = $isOverdue ? '!' : '&#10007;';
    ?>
    <div class="icon-circle <?= $iconClass ?>"><?= $glyph ?></div>
    <h1><?= htmlspecialchars(I18n::t('subentry_denied')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('subentry_reason_' . $result['reason'])) ?></p>
    <?php if (!empty($result['sub'])): ?>
      <div class="detail">
        <div class="k"><?= htmlspecialchars(I18n::t('sub_customer')) ?></div>
        <div><strong><?= htmlspecialchars($result['sub']['full_name']) ?></strong></div>
      </div>
      <?php if (!empty($result['overdue']) && (int) $result['overdue'] > 0): ?>
        <div class="detail">
          <div class="k"><?= htmlspecialchars(I18n::t('sub_overdue_amount')) ?></div>
          <div><?= htmlspecialchars($money((int) $result['overdue'])) ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="actions">
      <a class="btn primary" href="subscriber-entry.php"><?= htmlspecialchars(I18n::t('subentry_try_again')) ?></a>
    </div>
  </div>
<?php else: ?>
  <form class="box" method="post">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('subentry_brand')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('subentry_title')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('subentry_subtitle')) ?></p>
    <input name="key" maxlength="20" autocomplete="off" autofocus required placeholder="ABCD1234">
    <button type="submit"><?= htmlspecialchars(I18n::t('subentry_open_gate')) ?></button>
  </form>
<?php endif; ?>
</body>
</html>
