<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\I18n;

$pdo  = Db::pdo($cfg['db']);
$cfg  = Settings::overlay($cfg, $pdo);
$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$plan = $pdo->query(
    "SELECT id, name, price_cents FROM subscription_plans
     WHERE period = 'daily' AND active = 1
     ORDER BY id LIMIT 1"
)->fetch();

$currencySymbol = (string) ($cfg['tariff']['currency_symbol'] ?? '€');

$browserCfg = [
    'currency_symbol'    => $currencySymbol,
    'auto_reset_seconds' => (int) ($cfg['app']['cashier_auto_reset_seconds'] ?? 8),
    'i18n' => [
        'phone_required'  => I18n::t('totem_err_phone_required'),
        'email_required'  => I18n::t('totem_err_email_required'),
        'no_plan'         => I18n::t('daily_no_plan'),
        'start_payment'   => I18n::t('err_start_payment'),
        'waiting'         => I18n::t('status_waiting'),
        'payment_failed'  => I18n::t('err_payment_failed'),
        'not_dispensed'   => I18n::t('warn_not_dispensed'),
        'received'        => I18n::t('pay_received'),
    ],
];
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('daily_title')) ?></title>
<style>
:root{--bg:#0b1020;--bg-2:#0f1530;--card:rgba(255,255,255,.04);--border:rgba(255,255,255,.1);--text:#e7ecf5;--muted:#9aa4bf;--accent:#5eead4;--accent-2:#38bdf8;--ok:#34d399;--err:#f87171;--warn:#fbbf24}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);
  background:radial-gradient(1100px 700px at 10% -10%,#1b2555 0%,transparent 55%),radial-gradient(900px 600px at 110% 110%,#0b3b53 0%,transparent 50%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  padding:24px;
}
.container{max-width:640px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:10px;flex-wrap:wrap}
.brand{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase;background:var(--card);backdrop-filter:blur(8px)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:30px;margin:0;letter-spacing:-.01em;background:linear-gradient(90deg,#fff,#a5f3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
.screen{border:1px solid var(--border);border-radius:22px;padding:28px;margin-bottom:18px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));box-shadow:0 24px 60px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.06);backdrop-filter:blur(10px)}
.screen h2{margin:0 0 12px;font-size:22px;font-weight:700}
.label{color:var(--muted);font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin:12px 0 6px}
input{width:100%;font-size:16px;padding:13px 14px;border-radius:10px;background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--border);outline:none}
input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.amount{font-size:64px;font-weight:800;text-align:center;margin:18px 0 6px;letter-spacing:-.02em;font-variant-numeric:tabular-nums;background:linear-gradient(90deg,#a7f3d0,#38bdf8);-webkit-background-clip:text;background-clip:text;color:transparent}
.amount .cur{font-size:28px;color:var(--muted);-webkit-text-fill-color:var(--muted);background:none;margin-right:8px;font-weight:600}
button{font-size:16px;padding:14px 22px;cursor:pointer;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-weight:600;transition:transform .12s ease,background .15s ease,border-color .15s ease}
button:hover{background:rgba(255,255,255,.1);transform:translateY(-1px)}
button.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));border:none;color:#0b1020;box-shadow:0 10px 26px rgba(94,234,212,.22)}
button.danger{background:linear-gradient(135deg,#f87171,#ef4444);border:none;color:#fff;box-shadow:0 10px 26px rgba(239,68,68,.22)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.status{font-size:15px;margin:14px 0;color:var(--muted);min-height:1.4em}
.row-line{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:16px;border-bottom:1px solid rgba(255,255,255,.06)}
.row-line:last-child{border-bottom:none}
.row-line span{color:var(--muted)}
.row-line b{font-variant-numeric:tabular-nums;font-weight:700}
.ok{color:var(--ok)}.err{color:var(--err)}
.hidden{display:none}
.success-icon,.fail-icon{width:72px;height:72px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;font-size:40px;font-weight:900}
.success-icon{background:linear-gradient(135deg,#34d399,#10b981);color:#04231a;box-shadow:0 12px 40px rgba(16,185,129,.35)}
.center{text-align:center}
.pin{font-size:54px;font-weight:800;letter-spacing:12px;margin:14px 0 4px;color:var(--text);font-variant-numeric:tabular-nums}
.qr-wrap{display:inline-block;padding:12px;border-radius:14px;background:#fff;margin:8px 0 4px}
.qr-wrap img{display:block;width:200px;height:200px}
.delivered{margin-top:10px;display:flex;flex-direction:column;gap:6px;align-items:center}
.pill{display:inline-block;padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600}
.pill.ok{background:linear-gradient(135deg,#34d399,#10b981);color:#062b1f}
.pill.fail{background:rgba(248,113,113,.20);color:#fecaca;border:1px solid rgba(248,113,113,.4)}
.tip{color:var(--muted);font-size:14px;margin-top:8px}
.err-box{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.35);color:#fecaca;padding:10px 12px;border-radius:10px;font-size:13px;margin-top:12px}
.lang-switch{display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card)}
.lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
.header-right{display:flex;align-items:center;gap:10px}
@media (max-width:760px){
  body{padding:14px}
  h1{font-size:22px}
  .screen{padding:18px;border-radius:16px}
  .row{grid-template-columns:1fr;gap:6px}
  .amount{font-size:48px}.amount .cur{font-size:22px}
  .pin{font-size:42px;letter-spacing:8px}
}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1><?= htmlspecialchars(I18n::t('daily_title')) ?></h1>
    <div class="header-right">
      <nav class="lang-switch" aria-label="<?= htmlspecialchars(I18n::t('a11y_language')) ?>">
        <?php foreach (I18n::labels() as $label => $code): ?>
          <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('brand_kiosk')) ?></span>
    </div>
  </div>

<?php if (!$plan): ?>
  <div class="screen center">
    <h2><?= htmlspecialchars(I18n::t('daily_no_plan')) ?></h2>
    <p class="tip"><?= htmlspecialchars(I18n::t('daily_no_plan_hint')) ?></p>
  </div>
<?php else: ?>

<div id="s1" class="screen">
  <h2><?= htmlspecialchars(I18n::t('daily_subtitle')) ?></h2>
  <p class="tip" style="margin:0 0 14px"><?= htmlspecialchars(I18n::t('daily_help')) ?></p>

  <div class="amount">
    <span class="cur"><?= htmlspecialchars($currencySymbol) ?></span><span id="planPrice"><?= number_format(((int) $plan['price_cents']) / 100, 2, '.', '') ?></span>
  </div>
  <p class="tip center" style="margin:0 0 18px"><?= htmlspecialchars($plan['name']) ?> · <?= htmlspecialchars(I18n::t('daily_validity_note')) ?></p>

  <label class="label"><?= htmlspecialchars(I18n::t('totem_phone')) ?></label>
  <input id="phone" name="phone" placeholder="+39..." inputmode="tel" autocomplete="tel">

  <label class="label"><?= htmlspecialchars(I18n::t('totem_email')) ?></label>
  <input id="email" name="email" type="email" placeholder="<?= htmlspecialchars(I18n::t('ph_email_example')) ?>" autocomplete="email">

  <label class="label"><?= htmlspecialchars(I18n::t('totem_name_optional')) ?></label>
  <input id="name" name="name" placeholder="<?= htmlspecialchars(I18n::t('totem_name_ph')) ?>" autocomplete="name">

  <div class="err-box hidden" id="e1"></div>

  <div class="actions">
    <button id="buy" class="primary"><?= htmlspecialchars(I18n::t('daily_buy_cash')) ?></button>
    <button id="buyCard" class="primary"><?= htmlspecialchars(I18n::t('daily_buy_card')) ?></button>
  </div>
</div>

<div id="s2" class="screen hidden">
  <h2><?= htmlspecialchars(I18n::t('pay_in_progress')) ?></h2>
  <div class="row-line"><span><?= htmlspecialchars(I18n::t('pay_requested')) ?></span><b><span id="req"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row-line"><span><?= htmlspecialchars(I18n::t('pay_inserted')) ?></span><b><span id="ins"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row-line"><span><?= htmlspecialchars(I18n::t('pay_dispensed')) ?></span><b><span id="disp"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row-line"><span><?= htmlspecialchars(I18n::t('pay_not_dispensed')) ?></span><b><span id="nd" class="err"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="status" id="opstatus"></div>
  <div class="actions"><button id="cancel" class="danger"><?= htmlspecialchars(I18n::t('pay_cancel')) ?></button></div>
</div>

<div id="s3" class="screen hidden center">
  <div class="success-icon">&#10003;</div>
  <h2 class="ok"><?= htmlspecialchars(I18n::t('daily_ready')) ?></h2>
  <div class="qr-wrap"><img id="qrImg" src="" alt="QR"></div>
  <div class="label"><?= htmlspecialchars(I18n::t('entrance_pin')) ?></div>
  <div class="pin" id="okPin"></div>
  <p class="tip"><?= htmlspecialchars(I18n::t('daily_valid_until')) ?>: <b id="expHuman"></b></p>
  <div class="delivered" id="delivered"></div>
  <div class="actions" style="justify-content:center;margin-top:14px">
    <button id="reset" class="primary"><?= htmlspecialchars(I18n::t('pay_new_transaction')) ?></button>
  </div>
</div>

<?php endif; ?>
</div>

<script>
const CFG = <?= json_encode($browserCfg, JSON_UNESCAPED_SLASHES) ?>;
let pollHandle = null, finishing = false, autoResetHandle = null;

const $ = id => document.getElementById(id);
const fmt = c => (c / 100).toFixed(2);
const show = id => ['s1','s2','s3'].forEach(s => $(s) && $(s).classList.toggle('hidden', s !== id));

async function postJson(path, body) {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: body ? JSON.stringify(body) : '',
  });
  return res.json();
}

function showErr(msg) {
  const e = $('e1');
  e.textContent = msg;
  e.classList.remove('hidden');
}
function clearErr() { $('e1').classList.add('hidden'); $('e1').textContent = ''; }

function resetToStart() {
  if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
  if (autoResetHandle) { clearInterval(autoResetHandle); autoResetHandle = null; }
  finishing = false;
  show('s1');
}

function collect() {
  const phone = $('phone').value.trim();
  const email = $('email').value.trim();
  const name  = $('name').value.trim();
  if (!phone) { showErr(CFG.i18n.phone_required); return null; }
  if (!email) { showErr(CFG.i18n.email_required); return null; }
  return { phone, email, name };
}
function setBuyDisabled(b) {
  if ($('buy'))     $('buy').disabled = b;
  if ($('buyCard')) $('buyCard').disabled = b;
}
function renderSuccess(r) {
  $('qrImg').src = r.qr_url;
  $('okPin').textContent = r.pin;
  $('expHuman').textContent = r.expires_human;
  const d = $('delivered');
  d.innerHTML = '';
  if (r.delivered && r.delivered.whatsapp === true)  d.insertAdjacentHTML('beforeend', '<span class="pill ok">&#10003; WhatsApp</span>');
  if (r.delivered && r.delivered.whatsapp === false) d.insertAdjacentHTML('beforeend', '<span class="pill fail">&#10007; WhatsApp</span>');
  if (r.delivered && r.delivered.email === true)     d.insertAdjacentHTML('beforeend', '<span class="pill ok">&#10003; Email</span>');
  if (r.delivered && r.delivered.email === false)    d.insertAdjacentHTML('beforeend', '<span class="pill fail">&#10007; Email</span>');
  show('s3');
}

if ($('buy')) {
  $('buy').onclick = async () => {
    clearErr();
    const fields = collect(); if (!fields) return;
    setBuyDisabled(true);
    try {
      const start = await postJson('api/daily-ticket-start.php', fields);
      if (!start.ok) {
        showErr((CFG.i18n.start_payment || '') + (start.error || ''));
        setBuyDisabled(false);
        return;
      }
      $('req').textContent  = fmt(start.amount_cents);
      $('ins').textContent  = '0.00';
      $('disp').textContent = '0.00';
      $('nd').textContent   = '0.00';
      $('opstatus').textContent = CFG.i18n.waiting;
      show('s2');
      pollHandle = setInterval(pollActive, 300);
    } catch (e) {
      showErr(e.message);
      setBuyDisabled(false);
    }
  };

  $('buyCard').onclick = async () => {
    clearErr();
    const fields = collect(); if (!fields) return;
    setBuyDisabled(true);
    try {
      const r = await postJson('api/daily-ticket-card.php', fields);
      if (!r.ok) {
        showErr((CFG.i18n.payment_failed || '') + (r.error || ''));
        setBuyDisabled(false);
        return;
      }
      renderSuccess(r);
    } catch (e) {
      showErr(e.message);
      setBuyDisabled(false);
    }
  };

  $('cancel').onclick = async () => {
    try { await postJson('api/cashmatic-cancel.php', null); } catch (e) {}
    if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
    setBuyDisabled(false);
    show('s1');
  };

  $('reset').onclick = () => { setBuyDisabled(false); window.location.reload(); };
}

async function pollActive() {
  if (finishing) return;
  try {
    const r = await postJson('api/cashmatic-poll.php', null);
    if (!r.ok) { $('opstatus').textContent = r.error || ''; return; }
    $('req').textContent  = fmt(r.requested);
    $('ins').textContent  = fmt(r.inserted);
    $('disp').textContent = fmt(r.dispensed);
    $('nd').textContent   = fmt(r.notDispensed);

    if (r.operation !== 'idle') return;
    if (finishing) return;
    finishing = true;
    clearInterval(pollHandle); pollHandle = null;

    const finish = await postJson('api/daily-ticket-finish.php', null);
    if (!finish.ok) {
      alert((CFG.i18n.payment_failed || '') + (finish.error || finish.end || ''));
      show('s1');
      setBuyDisabled(false);
      return;
    }
    renderSuccess(finish);
  } catch (e) {
    $('opstatus').textContent = e.message;
  }
}
</script>
</body>
</html>
