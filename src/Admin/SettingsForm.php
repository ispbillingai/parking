<?php
declare(strict_types=1);

namespace Parking\Admin;

use PDO;
use Parking\Db;
use Parking\I18n;

/**
 * Generic "edit config values" form used by the admin Settings, Printers and
 * Fiscal printers pages. Values are stored in the settings table under their
 * dot-key and overlaid on config/config.php by Settings::overlay().
 *
 * $groups = [[
 *   'title_key' => …, 'desc_key' => …,
 *   'test'      => 'printer',   // optional: "Test" button → api/gateway-test.php
 *   'fields'    => [[ 'key' => 'printer.host', 'type' => text|password|number|email|select|checkbox,
 *                     'label_key' => …, 'help_key'?, 'placeholder'?, 'options'? ], …],
 * ], …]
 *
 * Password fields are never echoed back; an empty submission keeps the stored
 * value. Any other empty field deletes the override (config.php value again).
 */
final class SettingsForm
{
    /** Handle the POST: store every field of every group, then redirect. */
    public static function save(PDO $pdo, array $groups, string $action, string $redirect): void
    {
        Auth::requirePost();

        $values = [];
        foreach ($groups as $g) {
            foreach ($g['fields'] as $f) {
                $name = $f['key'];
                $raw  = $_POST['s'][$name] ?? null;

                if ($f['type'] === 'checkbox') {
                    $values[$name] = isset($_POST['s'][$name]) ? '1' : '0';
                    continue;
                }
                if ($f['type'] === 'password') {
                    // Empty input means keep the existing stored value
                    $values[$name] = ($raw === null || $raw === '') ? '__keep__' : (string) $raw;
                    continue;
                }
                $values[$name] = $raw === null ? '' : trim((string) $raw);
            }
        }

        Settings::setMany($pdo, $values);
        // Never log the values themselves (passwords).
        Db::logEvent($pdo, null, null, 'admin_action', ['action' => $action, 'count' => count($values)]);
        Layout::flash(I18n::t('flash_settings_saved'));
        header('Location: ' . $redirect);
        exit;
    }

    /** Print the form (inside Layout::begin/end). */
    public static function render(PDO $pdo, array $cfg, array $groups): void
    {
        $stored = Settings::all($pdo);
        $flat   = self::flatten($cfg);
        $h      = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $inp    = 'background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px';

        $valFor = static function (string $key, string $type) use ($stored, $flat): string {
            if ($type === 'password') {
                return isset($stored[$key]) && $stored[$key] !== '' ? '__exists__' : '';
            }
            if (isset($stored[$key])) return (string) $stored[$key];
            if (isset($flat[$key]))   return is_bool($flat[$key]) ? ($flat[$key] ? '1' : '0') : (string) $flat[$key];
            return '';
        };
        ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $h(Auth::csrfToken()) ?>">

  <?php foreach ($groups as $g): ?>
    <div class="card">
      <h2><?= $h(I18n::t($g['title_key'])) ?></h2>
      <p class="muted" style="margin:0 0 14px;font-size:13px;line-height:1.55"><?= $h(I18n::t($g['desc_key'])) ?></p>
      <div class="grid k2">
        <?php foreach ($g['fields'] as $f):
            $key   = $f['key'];
            $type  = $f['type'];
            $val   = $valFor($key, $type);
            $label = I18n::t($f['label_key']);
            $help  = !empty($f['help_key']) ? I18n::t($f['help_key']) : null;
            $defaultHint = $flat[$key] ?? null;
            if (is_bool($defaultHint)) $defaultHint = $defaultHint ? '1' : '0';
        ?>
          <label style="display:flex;flex-direction:column;gap:6px<?= $type === 'checkbox' ? ';flex-direction:row;align-items:center;gap:10px' : '' ?>">
            <?php if ($type === 'checkbox'): ?>
              <input type="checkbox" name="s[<?= $h($key) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?>>
              <span><?= $h($label) ?>
                <?php if ($help): ?><br><span class="muted" style="font-size:11px"><?= $h($help) ?></span><?php endif; ?>
              </span>
            <?php elseif ($type === 'select'): ?>
              <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= $h($label) ?></span>
              <select name="s[<?= $h($key) ?>]" style="<?= $inp ?>">
                <?php foreach ($f['options'] as $optV => $optL): ?>
                  <option value="<?= $h($optV) ?>" <?= (string) $val === (string) $optV ? 'selected' : '' ?>><?= $h($optL) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($help): ?><span class="muted" style="font-size:11px"><?= $h($help) ?></span><?php endif; ?>
            <?php else:
                $inputType = in_array($type, ['password', 'email', 'number'], true) ? $type : 'text';
                $shown = $type === 'password' ? '' : $val;
                if ($type === 'password') {
                    // Never show a secret, not even the config.php one, as a hint.
                    $ph = ($val === '__exists__' || ($defaultHint ?? '') !== '') ? '••••••••' : '';
                } else {
                    $ph = $f['placeholder'] ?? ($defaultHint !== null ? (string) $defaultHint : '');
                }
            ?>
              <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= $h($label) ?></span>
              <input type="<?= $inputType ?>" name="s[<?= $h($key) ?>]" value="<?= $h($shown) ?>"
                     placeholder="<?= $h($ph) ?>" <?= $type === 'password' ? 'autocomplete="new-password"' : '' ?> style="<?= $inp ?>">
              <?php if ($help): ?><span class="muted" style="font-size:11px"><?= $h($help) ?></span><?php endif; ?>
              <?php if ($type !== 'password' && !isset($stored[$key]) && $defaultHint !== null && $defaultHint !== ''): ?>
                <span class="muted" style="font-size:11px"><?= $h(I18n::t('settings_default_hint', ['v' => (string) $defaultHint])) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($g['test'])): ?>
        <div style="margin-top:14px">
          <button type="button" class="btn" onclick="testDevice('<?= $h($g['test']) ?>', this)"><?= $h(I18n::t('gw_test')) ?></button>
          <span class="test-result" data-for="<?= $h($g['test']) ?>" style="margin-left:10px;font-size:13px"></span>
          <span class="muted" style="display:block;font-size:11px;margin-top:6px"><?= $h(I18n::t('dev_test_saved_hint')) ?></span>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card" style="display:flex;justify-content:flex-end;gap:10px">
    <button type="submit" class="btn primary"><?= $h(I18n::t('btn_save')) ?></button>
  </div>
</form>
<script>
// Tests the SAVED config, so save first.
async function testDevice(gateway, btn) {
  const out = document.querySelector('.test-result[data-for="' + gateway + '"]');
  const T = <?= json_encode(['ok' => I18n::t('gw_test_ok'), 'failed' => I18n::t('gw_test_failed')], JSON_UNESCAPED_UNICODE) ?>;
  out.textContent = '…'; out.style.color = '';
  if (btn) btn.disabled = true;
  try {
    const res = await fetch('../api/gateway-test.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ gateway })
    });
    const d = await res.json();
    out.style.color = d.ok ? 'var(--ok)' : 'var(--err)';
    out.textContent = d.ok ? T.ok + (d.state ? ' (' + d.state + ')' : '') : T.failed + ': ' + (d.error || '');
  } catch (e) { out.style.color = 'var(--err)'; out.textContent = e.message; }
  finally { if (btn) btn.disabled = false; }
}
</script>
        <?php
    }

    /**
     * Flatten $cfg into the same dot-notation keys the settings table uses,
     * so the form can show the config.php default as a hint.
     */
    private static function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $name = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $name);
            } else {
                $out[$name] = $v;
            }
        }
        return $out;
    }
}
