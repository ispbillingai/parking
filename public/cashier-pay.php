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
        'start_payment'   => I18n::t('err_start_payment'),
        'waiting'         => I18n::t('status_waiting'),
        'payment_failed'  => I18n::t('err_payment_failed'),
        'not_dispensed'   => I18n::t('warn_not_dispensed'),
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
<title><?= htmlspecialchars(I18n::t('cashierpay_title')) ?></title>
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
    --err:#f87171;
    --warn:#fbbf24;
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
    padding:24px;
  }
  .container{max-width:640px;margin:0 auto}
  .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:10px;flex-wrap:wrap}
  .brand{
    display:inline-flex;align-items:center;gap:8px;
    padding:6px 12px;border:1px solid var(--border);border-radius:999px;
    color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase;
    background:var(--card);backdrop-filter:blur(8px);
  }
  .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
  h1{
    font-size:30px;margin:0;letter-spacing:-.01em;
    background:linear-gradient(90deg,#fff,#a5f3fc);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .screen{
    border:1px solid var(--border);border-radius:22px;padding:28px;margin-bottom:18px;
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));
    box-shadow:0 24px 60px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
    backdrop-filter:blur(10px);
  }
  .screen h2{margin:0 0 12px;font-size:22px;font-weight:700}
  .label{color:var(--muted);font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
  input[type=text]{
    font-size:42px;letter-spacing:14px;padding:16px 12px;width:100%;text-align:center;
    background:rgba(0,0,0,.35);color:#fff;border:1px solid var(--border);
    border-radius:14px;outline:none;font-variant-numeric:tabular-nums;font-weight:700;
    transition:border-color .15s ease, box-shadow .15s ease;
  }
  input[type=text]:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
  .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
  button{
    font-size:16px;padding:14px 22px;cursor:pointer;border-radius:12px;
    border:1px solid var(--border);
    background:rgba(255,255,255,.05);color:var(--text);font-weight:600;
    transition:transform .12s ease, background .15s ease, border-color .15s ease;
  }
  button:hover{background:rgba(255,255,255,.1);transform:translateY(-1px)}
  button.primary{
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    border:none;color:#0b1020;box-shadow:0 10px 26px rgba(94,234,212,.22);
  }
  button.primary:hover{box-shadow:0 14px 34px rgba(94,234,212,.32)}
  button.danger{
    background:linear-gradient(135deg,#f87171,#ef4444);
    border:none;color:#fff;box-shadow:0 10px 26px rgba(239,68,68,.22);
  }
  .amount{
    font-size:64px;font-weight:800;text-align:center;margin:18px 0 6px;letter-spacing:-.02em;
    font-variant-numeric:tabular-nums;
    background:linear-gradient(90deg,#a7f3d0,#38bdf8);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .amount .cur{
    font-size:28px;color:var(--muted);-webkit-text-fill-color:var(--muted);
    background:none;margin-right:8px;font-weight:600;
  }
  .status{font-size:15px;margin:14px 0;color:var(--muted);min-height:1.4em}
  .row{
    display:flex;justify-content:space-between;align-items:center;
    padding:12px 0;font-size:16px;
    border-bottom:1px solid rgba(255,255,255,.06);
  }
  .row:last-child{border-bottom:none}
  .row span{color:var(--muted)}
  .row b{font-variant-numeric:tabular-nums;font-weight:700}
  .ok{color:var(--ok)}
  .err{color:var(--err)}
  .hidden{display:none}
  .success-icon,.fail-icon{
    width:72px;height:72px;margin:0 auto 14px;border-radius:50%;
    display:grid;place-items:center;font-size:40px;font-weight:900;
  }
  .success-icon{
    background:linear-gradient(135deg,#34d399,#10b981);
    color:#04231a;box-shadow:0 12px 40px rgba(16,185,129,.35);
  }
  .fail-icon{
    background:linear-gradient(135deg,#f87171,#ef4444);
    color:#2a0707;box-shadow:0 12px 40px rgba(239,68,68,.35);
  }
  .center{text-align:center}
  .tip{color:var(--muted);font-size:14px;margin-top:8px}
  .ttl-badge{
    display:inline-block;margin-top:6px;padding:6px 12px;border-radius:999px;
    background:rgba(94,234,212,.12);color:var(--accent);border:1px solid rgba(94,234,212,.3);
    font-weight:600;font-size:14px;
  }
  .warn{
    margin-top:12px;padding:10px 14px;border-radius:12px;font-size:14px;
    background:rgba(251,191,36,.12);color:var(--warn);border:1px solid rgba(251,191,36,.3);
  }
  .receipt{
    max-width:320px;margin:14px auto 18px;padding:12px 16px;
    border:1px solid var(--border);border-radius:14px;
    background:rgba(255,255,255,.03);text-align:left;
  }
  .lang-switch{display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card);backdrop-filter:blur(8px)}
  .lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.08em;text-decoration:none;transition:color .15s ease, background .15s ease}
  .lang-switch a:hover{color:var(--text)}
  .lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
  .header-right{display:flex;align-items:center;gap:10px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1><?= htmlspecialchars(I18n::t('cashierpay_title')) ?></h1>
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
  <p class="tip" style="margin:0 0 14px"><?= htmlspecialchars(I18n::t('pay_scan_or_type')) ?></p>
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
    <button id="pay" class="primary"><?= htmlspecialchars(I18n::t('pay_start')) ?></button>
    <button id="back"><?= htmlspecialchars(I18n::t('pay_abort')) ?></button>
  </div>
</div>

<div id="s3" class="screen hidden">
  <h2><?= htmlspecialchars(I18n::t('pay_in_progress')) ?></h2>
  <div class="row"><span><?= htmlspecialchars(I18n::t('pay_requested')) ?></span><b><span id="req"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row"><span><?= htmlspecialchars(I18n::t('pay_inserted')) ?></span><b><span id="ins"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row"><span><?= htmlspecialchars(I18n::t('pay_dispensed')) ?></span><b><span id="disp"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="row"><span><?= htmlspecialchars(I18n::t('pay_not_dispensed')) ?></span><b><span id="nd" class="err"></span> <?= htmlspecialchars($currencySymbol) ?></b></div>
  <div class="status" id="opstatus"></div>
  <div class="actions"><button id="cancel" class="danger"><?= htmlspecialchars(I18n::t('pay_cancel')) ?></button></div>
</div>

<div id="s4" class="screen hidden center">
  <div id="statusIcon" class="success-icon">&#10003;</div>
  <h2 id="s4Title" class="ok"><?= htmlspecialchars(I18n::t('pay_received')) ?></h2>
  <div class="receipt">
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_entry')) ?></span><b id="rcptEntry"></b></div>
    <div class="row"><span><?= htmlspecialchars(I18n::t('pay_duration')) ?></span><b id="rcptDuration"></b></div>
    <div class="row"><span id="rcptAmountLabel"><?= htmlspecialchars(I18n::t('pay_paid')) ?></span><b id="rcptAmount"></b></div>
  </div>
  <div id="ndWarn" class="warn hidden"></div>
  <p class="tip" id="s4Tip"><?= htmlspecialchars(I18n::t('pay_scan_within')) ?></p>
  <span class="ttl-badge" id="ttlBadge"><span id="ttl"></span> <?= htmlspecialchars(I18n::t('pay_minutes')) ?></span>
  <p class="tip" style="margin-top:16px"><?= htmlspecialchars(I18n::t('pay_ready_in')) ?> <b id="readyIn"></b>s</p>
  <div class="actions" style="justify-content:center;margin-top:14px">
    <button id="reset" class="primary"><?= htmlspecialchars(I18n::t('pay_new_transaction')) ?></button>
  </div>
</div>
</div>

<script>
const CFG = <?= json_encode($browserCfg, JSON_UNESCAPED_SLASHES) ?>;
let session = null, pollHandle = null, autoResetHandle = null, finishing = false;

const $ = id => document.getElementById(id);
const fmt = c => (c / 100).toFixed(2);
const money = c => CFG.currency_symbol + ' ' + fmt(c);
const show = id => ['s1','s2','s3','s4'].forEach(s => $(s).classList.toggle('hidden', s !== id));

async function post(path, body = null) {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: body === null ? '' : JSON.stringify(body),
  });
  return res.json();
}

function renderSuccess(amountCents, notDispensed) {
  $('statusIcon').className = 'success-icon';
  $('statusIcon').innerHTML = '&#10003;';
  $('s4Title').className = 'ok';
  $('s4Title').textContent = CFG.i18n.received;
  $('rcptEntry').textContent     = session.entered_at_human;
  $('rcptDuration').textContent  = session.duration_human;
  $('rcptAmountLabel').textContent = CFG.i18n.paid_label;
  $('rcptAmount').textContent    = money(amountCents);
  $('ttl').textContent           = CFG.ttl_minutes;
  $('s4Tip').classList.remove('hidden');
  $('ttlBadge').classList.remove('hidden');
  if (notDispensed > 0) {
    $('ndWarn').textContent = CFG.i18n.not_dispensed.replace('{amount}', fmt(notDispensed));
    $('ndWarn').classList.remove('hidden');
  } else {
    $('ndWarn').classList.add('hidden');
  }
}

function renderAlreadyPaid(data) {
  $('statusIcon').className = 'success-icon';
  $('statusIcon').innerHTML = '&#10003;';
  $('s4Title').className = 'ok';
  $('s4Title').textContent = CFG.i18n.approved;
  $('rcptEntry').textContent     = data.entered_at_human;
  $('rcptDuration').textContent  = data.duration_human;
  $('rcptAmountLabel').textContent = CFG.i18n.paid_label;
  $('rcptAmount').textContent    = money(data.paid_amount_cents || 0);
  $('ttl').textContent           = CFG.ttl_minutes;
  $('s4Tip').classList.remove('hidden');
  $('ttlBadge').classList.remove('hidden');
  $('ndWarn').classList.add('hidden');
}

function resetToStart() {
  if (autoResetHandle) { clearInterval(autoResetHandle); autoResetHandle = null; }
  if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
  finishing = false;
  session = null;
  $('pin').value = '';
  $('e1').textContent = '';
  show('s1');
  $('pin').focus();
}

function startAutoReset(seconds) {
  if (autoResetHandle) clearInterval(autoResetHandle);
  let left = seconds;
  $('readyIn').textContent = left;
  autoResetHandle = setInterval(() => {
    left--;
    $('readyIn').textContent = left;
    if (left <= 0) resetToStart();
  }, 1000);
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
      renderAlreadyPaid(data);
      show('s4');
      startAutoReset(CFG.auto_reset_seconds);
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

$('pay').onclick = async () => {
  try {
    const sp = await post('api/cashmatic-start.php', {
      pin: session.pin,
      amount_cents: session.amount_cents,
    });
    if (!sp.ok) throw new Error(CFG.i18n.start_payment + (sp.error || ''));

    $('req').textContent  = fmt(session.amount_cents);
    $('ins').textContent  = '0.00';
    $('disp').textContent = '0.00';
    $('nd').textContent   = '0.00';
    $('opstatus').textContent = CFG.i18n.waiting;
    show('s3');

    pollHandle = setInterval(pollActive, 300);
  } catch (e) {
    alert(e.message);
  }
};

async function pollActive() {
  if (finishing) return;
  try {
    const r = await post('api/cashmatic-poll.php');
    if (!r.ok) { $('opstatus').textContent = r.error || ''; return; }
    $('req').textContent  = fmt(r.requested);
    $('ins').textContent  = fmt(r.inserted);
    $('disp').textContent = fmt(r.dispensed);
    $('nd').textContent   = fmt(r.notDispensed);

    if (r.operation !== 'idle') return;
    if (finishing) return;
    finishing = true;
    clearInterval(pollHandle); pollHandle = null;

    const finish = await post('api/cashmatic-finish.php', {
      pin: session.pin,
      amount_cents: session.amount_cents,
    });
    if (!finish.ok) {
      alert(CFG.i18n.payment_failed + (finish.error || finish.end || ''));
      show('s2');
      return;
    }
    renderSuccess(finish.amount_cents, finish.notDispensed || 0);
    show('s4');
    startAutoReset(CFG.auto_reset_seconds);
  } catch (e) {
    $('opstatus').textContent = e.message;
  }
}

$('cancel').onclick = async () => {
  try { await post('api/cashmatic-cancel.php'); } catch (e) {}
  if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
  show('s2');
};

$('reset').onclick = resetToStart;

$('pin').addEventListener('keydown', e => { if (e.key === 'Enter') $('lookup').click(); });
</script>
</body>
</html>
