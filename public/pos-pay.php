<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\I18n;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$currencySymbol = (string) ($cfg['tariff']['currency_symbol'] ?? '€');

$browserCfg = [
    'ttl_minutes'        => (int) ($cfg['app']['pin_ttl_after_pay_minutes'] ?? 15),
    'currency_symbol'    => $currencySymbol,
    'auto_reset_seconds' => (int) ($cfg['app']['cashier_auto_reset_seconds'] ?? 8),
    'i18n' => [
        'invalid_pin'     => I18n::t('err_invalid_pin'),
        'session_missing' => I18n::t('err_session_missing'),
        'payment_failed'  => I18n::t('err_payment_failed'),
        'approved'        => I18n::t('pay_approved'),
        'received'        => I18n::t('pay_received'),
        'paid_label'      => I18n::t('pay_paid'),
    ],
];
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('pospay_title')) ?></title>
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
.brand{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase;background:var(--card)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:30px;margin:0;letter-spacing:-.01em;background:linear-gradient(90deg,#fff,#a5f3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
.screen{border:1px solid var(--border);border-radius:22px;padding:28px;margin-bottom:18px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));box-shadow:0 24px 60px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.06)}
.label{color:var(--muted);font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin:12px 0 6px}
.tip{color:var(--muted);font-size:14px;margin:0}
input{width:100%;font-size:32px;padding:14px 12px;border-radius:12px;background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--border);outline:none;letter-spacing:8px;text-align:center;font-weight:700;font-variant-numeric:tabular-nums}
input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
.amount{font-size:64px;font-weight:800;text-align:center;margin:18px 0 6px;letter-spacing:-.02em;font-variant-numeric:tabular-nums;background:linear-gradient(90deg,#a7f3d0,#38bdf8);-webkit-background-clip:text;background-clip:text;color:transparent}
.amount .cur{font-size:28px;color:var(--muted);-webkit-text-fill-color:var(--muted);background:none;margin-right:8px;font-weight:600}
button{font-size:16px;padding:14px 22px;cursor:pointer;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-weight:600}
button.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));border:none;color:#0b1020;box-shadow:0 10px 26px rgba(94,234,212,.22)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;font-size:16px;border-bottom:1px solid rgba(255,255,255,.06)}
.row:last-child{border-bottom:none}
.row span{color:var(--muted)}
.row b{font-variant-numeric:tabular-nums}
.ok{color:var(--ok)}.err{color:var(--err)}
.hidden{display:none}
.success-icon{width:72px;height:72px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;font-size:40px;font-weight:900;background:linear-gradient(135deg,#34d399,#10b981);color:#04231a}
.center{text-align:center}
.lang-switch{display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card)}
.lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
.header-right{display:flex;align-items:center;gap:10px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1><?= htmlspecialchars(I18n::t('pospay_title')) ?></h1>
    <div class="header-right">
      <nav class="lang-switch" aria-label="Language">
        <?php foreach (I18n::labels() as $label => $code): ?>
          <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('brand_kiosk')) ?></span>
    </div>
  </div>

  <div id="s1" class="screen">
    <div class="label"><?= htmlspecialchars(I18n::t('pay_insert_ticket')) ?></div>
    <p class="tip" style="margin:0 0 14px"><?= htmlspecialchars(I18n::t('pospay_intro')) ?></p>
    <input id="pin" type="text" maxlength="6" inputmode="numeric" autocomplete="off" autofocus>
    <div class="actions">
      <button id="lookup" class="primary"><?= htmlspecialchars(I18n::t('pay_lookup')) ?></button>
    </div>
    <p class="err" id="e1" style="margin-top:14px"></p>
  </div>

  <div id="s2" class="screen hidden">
    <div class="label"><?= htmlspecialchars(I18n::t('pay_summary')) ?></div>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_entry')) ?></span><b id="enteredAt"></b></div>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_duration')) ?></span><b id="duration"></b></div>
    <div class="amount"><span class="cur"><?= htmlspecialchars($currencySymbol) ?></span><span id="amount"></span></div>
    <div class="actions">
      <button id="payCard" class="primary"><?= htmlspecialchars(I18n::t('pay_by_card')) ?></button>
      <button id="back"><?= htmlspecialchars(I18n::t('pay_abort')) ?></button>
    </div>
    <p class="err" id="e2" style="margin-top:10px"></p>
  </div>

  <div id="s4" class="screen hidden center">
    <div class="success-icon">&#10003;</div>
    <h2 class="ok"><?= htmlspecialchars(I18n::t('pay_received')) ?></h2>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_entry')) ?></span><b id="rcptEntry"></b></div>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_duration')) ?></span><b id="rcptDuration"></b></div>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_paid')) ?></span><b id="rcptAmount"></b></div>
    <p class="tip" style="margin-top:14px"><?= htmlspecialchars(I18n::t('pay_scan_within')) ?> <b><span id="ttl"></span> <?= htmlspecialchars(I18n::t('pay_minutes')) ?></b></p>
    <div class="actions" style="justify-content:center;margin-top:14px">
      <button id="reset" class="primary"><?= htmlspecialchars(I18n::t('pay_new_transaction')) ?></button>
    </div>
  </div>
</div>

<script>
const CFG = <?= json_encode($browserCfg, JSON_UNESCAPED_SLASHES) ?>;
let session = null;
const $ = id => document.getElementById(id);
const fmt = c => (c / 100).toFixed(2);
const money = c => CFG.currency_symbol + ' ' + fmt(c);
const show = id => ['s1','s2','s4'].forEach(s => $(s).classList.toggle('hidden', s !== id));

async function post(path, body = null) {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: body === null ? '' : JSON.stringify(body),
  });
  return res.json();
}

function resetToStart() {
  session = null;
  $('pin').value = '';
  $('e1').textContent = '';
  show('s1');
  $('pin').focus();
}

$('lookup').onclick = async () => {
  $('e1').textContent = '';
  const pin = $('pin').value.trim();
  if (!/^\d{6}$/.test(pin)) { $('e1').textContent = CFG.i18n.invalid_pin; return; }
  try {
    const r = await fetch('api/scan-pin.php?pin=' + pin);
    const data = await r.json();
    if (!data.ok) { $('e1').textContent = data.error || CFG.i18n.session_missing; return; }
    session = data;
    if (data.already_paid) {
      $('rcptEntry').textContent    = data.entered_at_human;
      $('rcptDuration').textContent = data.duration_human;
      $('rcptAmount').textContent   = money(data.paid_amount_cents || 0);
      $('ttl').textContent          = CFG.ttl_minutes;
      show('s4');
      return;
    }
    $('enteredAt').textContent = data.entered_at_human;
    $('duration').textContent  = data.duration_human;
    $('amount').textContent    = fmt(data.amount_cents);
    show('s2');
  } catch (e) {
    $('e1').textContent = e.message;
  }
};

$('back').onclick = resetToStart;
$('reset').onclick = resetToStart;

$('payCard').onclick = async () => {
  $('e2').textContent = '';
  $('payCard').disabled = true;
  $('back').disabled = true;
  try {
    const r = await post('api/card-pay-cashier.php', {
      pin: session.pin,
      amount_cents: session.amount_cents,
    });
    if (!r.ok) {
      $('e2').textContent = (CFG.i18n.payment_failed || '') + (r.error || '');
      return;
    }
    $('rcptEntry').textContent    = session.entered_at_human;
    $('rcptDuration').textContent = session.duration_human;
    $('rcptAmount').textContent   = money(r.amount_cents);
    $('ttl').textContent          = CFG.ttl_minutes;
    show('s4');
  } catch (e) {
    $('e2').textContent = e.message;
  } finally {
    $('payCard').disabled = false;
    $('back').disabled = false;
  }
};

$('pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('lookup').click(); });
</script>
</body>
</html>
