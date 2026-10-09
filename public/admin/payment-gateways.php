<?php
declare(strict_types=1);

/**
 * Admin: Payment Gateways (same rules as the Focacciami POS).
 *
 * Choose which CARD gateway the kiosks offer — Ingenico (RTS service, or
 * Protocol 17 straight to the terminal over TCP), Dojo (Dojo Cloud API),
 * both, or none — and edit each gateway's connection details in one place.
 * Values go to the settings table (pos.*, dojo.*, payment.card_gateway) and
 * are overlaid on config/config.php by Settings::overlay(); a blank field
 * falls back to the config.php value.
 *
 * The Cashmatic cash machine's connection (cashmatic.*) is edited here too;
 * the cash button does not depend on the card gateway choice.
 *
 * The Dojo secret key and the Cashmatic password are WRITE-ONLY: never
 * rendered back. Leave the field blank to keep the stored value.
 */

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\Settings;
use Parking\Db;
use Parking\I18n;
use Parking\Pos\Gateway;

Auth::require('login.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();

    $active = (string) ($_POST['active'] ?? 'both');
    if (!in_array($active, Gateway::CHOICES, true)) {
        $active = 'both';
    }
    $mode   = ($_POST['p_mode'] ?? '') === 'p17' ? 'p17' : 'rts';
    $secret = trim((string) ($_POST['d_secret'] ?? ''));

    Settings::setMany($pdo, [
        'payment.card_gateway'   => $active,
        'pos.mode'               => $mode,
        'pos.base_url'           => Gateway::posNormUrl((string) ($_POST['p_url'] ?? ''), $mode),
        'pos.terminal_name'      => trim((string) ($_POST['p_terminal'] ?? '')),
        'pos.ecr_id'             => trim((string) ($_POST['p_ecr'] ?? '')),
        'pos.protocol_type'      => trim((string) ($_POST['p_protocol'] ?? '0')),
        'pos.connect_timeout'    => (string) (int) ($_POST['p_connect'] ?? 5),
        'pos.read_timeout'       => (string) (int) ($_POST['p_read'] ?? 90),
        'dojo.base_url'          => Gateway::normUrl((string) ($_POST['d_url'] ?? 'https://api.dojo.tech')),
        'dojo.secret_key'        => $secret !== '' ? $secret : '__keep__',
        'dojo.terminal_id'       => trim((string) ($_POST['d_terminal'] ?? '')),
        'dojo.version'           => trim((string) ($_POST['d_version'] ?? '2026-02-27')),
        'dojo.capture_mode'      => ($_POST['d_capture'] ?? 'Auto') === 'Manual' ? 'Manual' : 'Auto',
        'dojo.reseller_id'       => trim((string) ($_POST['d_reseller'] ?? '')),
        'dojo.software_house_id' => trim((string) ($_POST['d_swhouse'] ?? '')),
        'dojo.read_timeout'      => (string) (int) ($_POST['d_read'] ?? 20),
        'dojo.poll_interval_ms'  => (string) (int) ($_POST['d_poll'] ?? 1500),
        'dojo.verify_ssl'        => isset($_POST['d_verify']) ? '1' : '0',
        // Cashmatic (cash machine): the password is write-only like the Dojo secret.
        'cashmatic.base_url'     => Gateway::posNormUrl((string) ($_POST['cm_url'] ?? ''), 'rts'),
        'cashmatic.username'     => trim((string) ($_POST['cm_username'] ?? '')),
        'cashmatic.password'     => (string) ($_POST['cm_password'] ?? '') !== '' ? (string) $_POST['cm_password'] : '__keep__',
        'cashmatic.verify_ssl'   => isset($_POST['cm_verify']) ? '1' : '0',
    ]);

    // Never log the secret key.
    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'payment_gateways.save', 'active' => $active]);
    Layout::flash(I18n::t('flash_settings_saved'));
    header('Location: payment-gateways.php');
    exit;
}

$active    = Gateway::active($cfg);
$pos        = $cfg['pos']  ?? [];
$dojo       = $cfg['dojo'] ?? [];
$dojoKeySet = !empty($dojo['secret_key']);
$cm         = $cfg['cashmatic'] ?? [];
$cmPwSet    = !empty($cm['password']);
$posP17     = Gateway::posMode($pos) === 'p17';

$v   = static fn($val, $d = '') => htmlspecialchars((string) ($val ?? $d), ENT_QUOTES, 'UTF-8');
$t   = static fn(string $k) => htmlspecialchars(I18n::t($k), ENT_QUOTES, 'UTF-8');
$inp = 'background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px;width:100%;box-sizing:border-box';
$lbl = 'muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase';

Layout::begin(I18n::t('nav_gateways'), 'gateways');
$csrf = Auth::csrfToken();
?>
<style>
.gw-head{display:flex;align-items:center;justify-content:space-between;cursor:pointer;margin:0}
.gw-tag{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;margin-left:10px}
.gw-on{background:rgba(52,211,153,.15);color:var(--ok)}
.gw-off{background:rgba(255,255,255,.06);color:var(--muted)}
.gw-body{margin-top:14px}
.gw-body.collapsed{display:none}
.gw-adv{border-top:1px dashed var(--border);margin-top:14px;padding-top:14px}
.gw-field{display:flex;flex-direction:column;gap:6px}
.picker{display:flex;flex-wrap:wrap;gap:12px}
.picker label{flex:1;min-width:150px;border:1px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;display:flex;align-items:center;gap:10px}
.picker input:checked + span{font-weight:700;color:var(--accent)}
.test-result{margin-left:10px;font-size:13px}
</style>

<p class="muted" style="margin:0 0 14px;font-size:13px;line-height:1.55"><?= $t('gw_help') ?></p>

<form method="post" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $v($csrf) ?>">

  <div class="card">
    <h2><?= $t('gw_active_title') ?></h2>
    <div class="picker">
      <?php foreach (['pos' => 'gw_ingenico', 'dojo' => 'gw_dojo', 'both' => 'gw_both', 'none' => 'gw_none'] as $val => $key): ?>
        <label><input type="radio" name="active" value="<?= $val ?>" <?= $active === $val ? 'checked' : '' ?>><span><?= $t($key) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Ingenico -->
  <div class="card">
    <h2 class="gw-head" onclick="toggleGw('pos')">
      <span><?= $t('gw_ingenico') ?>
        <?php $on = in_array($active, ['pos', 'both'], true); ?>
        <span class="gw-tag <?= $on ? 'gw-on' : 'gw-off' ?>"><?= $t($on ? 'gw_tag_active' : 'gw_tag_inactive') ?></span></span>
      <span>&#x25BE;</span>
    </h2>
    <div class="gw-body" id="gw-pos">
      <div class="grid k2">
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_pos_connection') ?></span>
          <select name="p_mode" style="<?= $inp ?>" onchange="posModeUi(this)">
            <option value="rts" <?= $posP17 ? '' : 'selected' ?>><?= $t('gw_pos_mode_rts') ?></option>
            <option value="p17" <?= $posP17 ? 'selected' : '' ?>><?= $t('gw_pos_mode_p17') ?></option>
          </select></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_ip_or_url') ?></span>
          <input type="text" name="p_url" style="<?= $inp ?>" value="<?= $v($pos['base_url'] ?? '') ?>"
                 placeholder="<?= $posP17 ? '192.168.1.26:PORT' : 'http://192.164.1.21/WebDoremiposWS' ?>"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_terminal_name') ?></span>
          <input type="text" name="p_terminal" style="<?= $inp ?>" value="<?= $v($pos['terminal_name'] ?? '') ?>"
                 placeholder="<?= $posP17 ? '00000000' : 'Ingenico-XXXX' ?>"></label>
        <label class="gw-field p17-only" <?= $posP17 ? '' : 'style="display:none"' ?>><span class="<?= $lbl ?>"><?= $t('gw_pos_ecr_id') ?></span>
          <input type="text" name="p_ecr" style="<?= $inp ?>" value="<?= $v($pos['ecr_id'] ?? '') ?>" placeholder="00000001"></label>
      </div>
      <p class="muted p17-only" style="font-size:12px;<?= $posP17 ? '' : 'display:none' ?>"><?= $t('gw_pos_p17_hint') ?></p>
      <div class="grid k3" style="margin-top:14px">
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_protocol_type') ?></span>
          <input type="text" name="p_protocol" style="<?= $inp ?>" value="<?= $v($pos['protocol_type'] ?? '0') ?>"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_connect_timeout') ?></span>
          <input type="number" name="p_connect" style="<?= $inp ?>" value="<?= $v($pos['connect_timeout'] ?? 5) ?>"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_read_timeout') ?></span>
          <input type="number" name="p_read" style="<?= $inp ?>" value="<?= $v($pos['read_timeout'] ?? 90) ?>"></label>
      </div>
      <div style="margin-top:14px">
        <button type="button" class="btn" onclick="testGw('pos', this)"><?= $t('gw_test') ?></button>
        <span class="test-result" data-for="pos"></span>
      </div>
    </div>
  </div>

  <!-- Dojo -->
  <div class="card">
    <h2 class="gw-head" onclick="toggleGw('dojo')">
      <span><?= $t('gw_dojo') ?>
        <?php $on = in_array($active, ['dojo', 'both'], true); ?>
        <span class="gw-tag <?= $on ? 'gw-on' : 'gw-off' ?>"><?= $t($on ? 'gw_tag_active' : 'gw_tag_inactive') ?></span></span>
      <span>&#x25BE;</span>
    </h2>
    <div class="gw-body" id="gw-dojo">
      <div class="grid k2">
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_dojo_secret') ?></span>
          <input type="password" name="d_secret" style="<?= $inp ?>" autocomplete="new-password"
                 placeholder="<?= $dojoKeySet ? $t('gw_dojo_secret_set') : 'sk_prod_… / sk_sandbox_…' ?>">
          <span class="muted" style="font-size:11px"><?= $t('gw_dojo_secret_hint') ?></span></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_dojo_terminal') ?></span>
          <input type="text" name="d_terminal" style="<?= $inp ?>" value="<?= $v($dojo['terminal_id'] ?? '') ?>" placeholder="tm_…">
          <span class="muted" style="font-size:11px"><?= $t('gw_dojo_terminal_hint') ?></span></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_api_version') ?></span>
          <input type="text" name="d_version" style="<?= $inp ?>" value="<?= $v($dojo['version'] ?? '2026-02-27') ?>"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_capture_mode') ?></span>
          <select name="d_capture" style="<?= $inp ?>">
            <option value="Auto"   <?= ($dojo['capture_mode'] ?? 'Auto') !== 'Manual' ? 'selected' : '' ?>>Auto</option>
            <option value="Manual" <?= ($dojo['capture_mode'] ?? 'Auto') === 'Manual' ? 'selected' : '' ?>>Manual</option>
          </select></label>
      </div>

      <div class="gw-adv">
        <p class="muted" style="margin:0 0 10px;font-size:12px"><?= $t('gw_advanced') ?> — <?= $t('gw_dojo_headers_hint') ?></p>
        <div class="grid k3">
          <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_ip_or_url') ?> (API)</span>
            <input type="text" name="d_url" style="<?= $inp ?>" value="<?= $v($dojo['base_url'] ?? 'https://api.dojo.tech') ?>"></label>
          <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_read_timeout') ?></span>
            <input type="number" name="d_read" style="<?= $inp ?>" value="<?= $v($dojo['read_timeout'] ?? 20) ?>"></label>
          <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_poll_interval') ?></span>
            <input type="number" name="d_poll" style="<?= $inp ?>" value="<?= $v($dojo['poll_interval_ms'] ?? 1500) ?>"></label>
          <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_reseller_id') ?></span>
            <input type="text" name="d_reseller" style="<?= $inp ?>" value="<?= $v($dojo['reseller_id'] ?? '') ?>" placeholder="reseller1"></label>
          <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_software_house_id') ?></span>
            <input type="text" name="d_swhouse" style="<?= $inp ?>" value="<?= $v($dojo['software_house_id'] ?? '') ?>" placeholder="softwareHouse1"></label>
          <label style="display:flex;align-items:center;gap:10px;align-self:end">
            <input type="checkbox" name="d_verify" value="1" <?= ($dojo['verify_ssl'] ?? true) ? 'checked' : '' ?>> <?= $t('gw_verify_ssl') ?></label>
        </div>
      </div>

      <div style="margin-top:14px">
        <button type="button" class="btn" onclick="testGw('dojo', this)"><?= $t('gw_test') ?></button>
        <span class="test-result" data-for="dojo"></span>
      </div>
    </div>
  </div>

  <!-- Cashmatic (cash machine) -->
  <div class="card">
    <h2 class="gw-head" onclick="toggleGw('cashmatic')">
      <span><?= $t('gw_cashmatic') ?>
        <?php $on = !empty($cm['base_url']); ?>
        <span class="gw-tag <?= $on ? 'gw-on' : 'gw-off' ?>"><?= $t($on ? 'gw_tag_active' : 'gw_tag_inactive') ?></span></span>
      <span>&#x25BE;</span>
    </h2>
    <div class="gw-body" id="gw-cashmatic">
      <p class="muted" style="margin:0 0 14px;font-size:12px"><?= $t('gw_cashmatic_help') ?></p>
      <div class="grid k2">
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_ip_or_url') ?></span>
          <input type="text" name="cm_url" style="<?= $inp ?>" value="<?= $v($cm['base_url'] ?? '') ?>" placeholder="https://100.x.y.z:50301"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_cm_username') ?></span>
          <input type="text" name="cm_username" style="<?= $inp ?>" value="<?= $v($cm['username'] ?? '') ?>" autocomplete="off"></label>
        <label class="gw-field"><span class="<?= $lbl ?>"><?= $t('gw_cm_password') ?></span>
          <input type="password" name="cm_password" style="<?= $inp ?>" autocomplete="new-password"
                 placeholder="<?= $cmPwSet ? $t('gw_cm_password_set') : '' ?>"></label>
        <label style="display:flex;align-items:center;gap:10px;align-self:end">
          <input type="checkbox" name="cm_verify" value="1" <?= !empty($cm['verify_ssl']) ? 'checked' : '' ?>> <?= $t('gw_verify_ssl') ?></label>
      </div>
      <div style="margin-top:14px">
        <button type="button" class="btn" onclick="testGw('cashmatic', this)"><?= $t('gw_test') ?></button>
        <span class="test-result" data-for="cashmatic"></span>
      </div>
    </div>
  </div>

  <div class="card" style="display:flex;justify-content:flex-end;gap:10px">
    <button type="submit" class="btn primary"><?= $t('btn_save') ?></button>
  </div>
</form>

<script>
const GW_I18N = <?= json_encode([
    'ok'     => I18n::t('gw_test_ok'),
    'failed' => I18n::t('gw_test_failed'),
    'found'  => I18n::t('gw_dojo_terminals_found'),
    'none'   => I18n::t('gw_dojo_no_terminals'),
    'use'    => I18n::t('gw_dojo_use_terminal'),
], JSON_UNESCAPED_UNICODE) ?>;
const ACTIVE = <?= json_encode($active) ?>;

// Collapse gateways that are not active so the page opens on what matters.
function toggleGw(gw) { document.getElementById('gw-' + gw).classList.toggle('collapsed'); }
['pos', 'dojo'].forEach(gw => {
  if (!(ACTIVE === gw || ACTIVE === 'both')) document.getElementById('gw-' + gw).classList.add('collapsed');
});

function posModeUi(sel) {
  const p17 = sel.value === 'p17', box = sel.closest('.gw-body');
  box.querySelectorAll('.p17-only').forEach(el => el.style.display = p17 ? '' : 'none');
  box.querySelector('[name=p_url]').placeholder = p17 ? '192.168.1.26:PORT' : 'http://192.164.1.21/WebDoremiposWS';
  box.querySelector('[name=p_terminal]').placeholder = p17 ? '00000000' : 'Ingenico-XXXX';
}

// Tests the SAVED config, so save first.
async function testGw(gateway, btn) {
  const out = document.querySelector('.test-result[data-for="' + gateway + '"]');
  out.textContent = '…'; out.style.color = '';
  if (btn) btn.disabled = true;
  try {
    const res = await fetch('../api/gateway-test.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ gateway })
    });
    const data = await res.json();
    if (data.ok && Array.isArray(data.terminals)) {
      // No terminal id saved yet: list the account's terminals to pick from.
      out.style.color = 'var(--ok)';
      out.textContent = data.terminals.length ? GW_I18N.found + ': ' : GW_I18N.none;
      data.terminals.forEach(t => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'btn'; b.style.margin = '4px';
        b.textContent = GW_I18N.use + ' ' + t.id + (t.label ? ' — ' + t.label : '') + (t.status ? ' (' + t.status + ')' : '');
        b.onclick = () => { document.querySelector('[name=d_terminal]').value = t.id; };
        out.appendChild(b);
      });
    }
    else if (data.ok) { out.style.color = 'var(--ok)'; out.textContent = GW_I18N.ok + (data.state ? ' (' + data.state + ')' : ''); }
    else { out.style.color = 'var(--err)'; out.textContent = GW_I18N.failed + ': ' + (data.error || ''); }
  } catch (e) { out.style.color = 'var(--err)'; out.textContent = e.message; }
  finally { if (btn) btn.disabled = false; }
}
</script>
<?php Layout::end();
