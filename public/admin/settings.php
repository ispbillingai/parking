<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\Settings;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

// --- Catalog of editable settings, grouped into form sections.
// Each entry: dot-key, type (text|password|number|email|select|checkbox),
// optional fallback (from $cfg) used as the placeholder in the form,
// and (for select) options. Password fields are not echoed back; an
// empty submission means "keep existing".
$groups = [
    [
        'title_key' => 'settings_group_whatsapp',
        'desc_key'  => 'settings_group_whatsapp_desc',
        'fields' => [
            ['key' => 'textmebot.api_key',  'type' => 'password', 'label_key' => 'settings_wa_api_key'],
            ['key' => 'textmebot.endpoint', 'type' => 'text',     'label_key' => 'settings_wa_endpoint',
             'placeholder' => 'https://api.textmebot.com/send.php'],
        ],
    ],
    [
        'title_key' => 'settings_group_email',
        'desc_key'  => 'settings_group_email_desc',
        'fields' => [
            ['key' => 'mailer.transport',  'type' => 'select', 'label_key' => 'settings_mail_transport',
             'options' => ['mail' => 'PHP mail()', 'smtp' => 'SMTP']],
            ['key' => 'mailer.from_email', 'type' => 'email',  'label_key' => 'settings_mail_from_email'],
            ['key' => 'mailer.from_name',  'type' => 'text',   'label_key' => 'settings_mail_from_name'],
            ['key' => 'mailer.smtp_host',  'type' => 'text',   'label_key' => 'settings_mail_smtp_host',
             'placeholder' => 'smtp.example.com', 'help_key' => 'settings_mail_smtp_help'],
            ['key' => 'mailer.smtp_port',  'type' => 'number', 'label_key' => 'settings_mail_smtp_port'],
            ['key' => 'mailer.smtp_secure','type' => 'select', 'label_key' => 'settings_mail_smtp_secure',
             'options' => ['' => 'none', 'tls' => 'STARTTLS', 'ssl' => 'SSL/TLS']],
            ['key' => 'mailer.smtp_user',  'type' => 'text',     'label_key' => 'settings_mail_smtp_user'],
            ['key' => 'mailer.smtp_pass',  'type' => 'password', 'label_key' => 'settings_mail_smtp_pass'],
        ],
    ],
    [
        'title_key' => 'settings_group_tariff',
        'desc_key'  => 'settings_group_tariff_desc',
        'fields' => [
            ['key' => 'tariff.currency_symbol', 'type' => 'text',   'label_key' => 'settings_tariff_currency'],
            ['key' => 'tariff.hourly_cents',    'type' => 'number', 'label_key' => 'settings_tariff_hourly',
             'help_key' => 'settings_tariff_cents_help'],
            ['key' => 'tariff.minimum_cents',   'type' => 'number', 'label_key' => 'settings_tariff_minimum'],
            ['key' => 'tariff.daily_cap_cents', 'type' => 'number', 'label_key' => 'settings_tariff_daily_cap',
             'help_key' => 'settings_tariff_zero_help'],
            ['key' => 'tariff.grace_minutes',   'type' => 'number', 'label_key' => 'settings_tariff_grace'],
        ],
    ],
    [
        'title_key' => 'settings_group_app',
        'desc_key'  => 'settings_group_app_desc',
        'fields' => [
            ['key' => 'app.pin_ttl_after_pay_minutes',   'type' => 'number',   'label_key' => 'settings_app_ttl'],
            ['key' => 'app.cashier_auto_reset_seconds',  'type' => 'number',   'label_key' => 'settings_app_reset'],
            ['key' => 'app.default_lang',                'type' => 'select',   'label_key' => 'settings_app_lang',
             'options' => ['en' => 'English', 'it' => 'Italiano']],
            ['key' => 'app.subscription_block_overdue',  'type' => 'checkbox', 'label_key' => 'settings_app_block_overdue',
             'help_key' => 'settings_app_block_overdue_help'],
        ],
    ],
];

// --- Save -------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
                if ($raw === null || $raw === '') {
                    $values[$name] = '__keep__';
                } else {
                    $values[$name] = (string) $raw;
                }
                continue;
            }
            $values[$name] = $raw === null ? '' : (string) $raw;
        }
    }

    Settings::setMany($pdo, $values);
    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'settings.save', 'count' => count($values)]);
    Layout::flash(I18n::t('flash_settings_saved'));
    header('Location: settings.php');
    exit;
}

$stored = Settings::all($pdo);
$flat   = self_flatten($cfg);

/**
 * Flatten the $cfg array into the same dot-notation keys the settings
 * table uses, so the form can show "config.php default" as a hint
 * next to each field.
 */
function self_flatten(array $arr, string $prefix = ''): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $name = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out += self_flatten($v, $name);
        } else {
            $out[$name] = $v;
        }
    }
    return $out;
}

$valFor = function (string $key, string $type) use ($stored, $flat) {
    if ($type === 'password') {
        return isset($stored[$key]) && $stored[$key] !== '' ? '__exists__' : '';
    }
    if (isset($stored[$key]))    return (string) $stored[$key];
    if (isset($flat[$key]))      return (string) $flat[$key];
    return '';
};

Layout::begin(I18n::t('nav_settings'), 'settings');
$csrf = Auth::csrfToken();
?>
<form method="post" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

  <?php foreach ($groups as $g): ?>
    <div class="card">
      <h2><?= htmlspecialchars(I18n::t($g['title_key'])) ?></h2>
      <p class="muted" style="margin:0 0 14px;font-size:13px;line-height:1.55"><?= htmlspecialchars(I18n::t($g['desc_key'])) ?></p>
      <div class="grid k2">
        <?php foreach ($g['fields'] as $f):
            $key = $f['key'];
            $type = $f['type'];
            $val = $valFor($key, $type);
            $label = I18n::t($f['label_key']);
            $help  = !empty($f['help_key']) ? I18n::t($f['help_key']) : null;
            $defaultHint = $flat[$key] ?? null;
        ?>
          <label style="display:flex;flex-direction:column;gap:6px<?= $type === 'checkbox' ? ';flex-direction:row;align-items:center;gap:10px' : '' ?>">
            <?php if ($type === 'checkbox'): ?>
              <input type="checkbox" name="s[<?= htmlspecialchars($key) ?>]" value="1" <?= $val === '1' || (string) $val === '1' ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($label) ?>
                <?php if ($help): ?><br><span class="muted" style="font-size:11px"><?= htmlspecialchars($help) ?></span><?php endif; ?>
              </span>
            <?php elseif ($type === 'select'): ?>
              <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= htmlspecialchars($label) ?></span>
              <select name="s[<?= htmlspecialchars($key) ?>]"
                      style="background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px">
                <?php foreach ($f['options'] as $optV => $optL): ?>
                  <option value="<?= htmlspecialchars((string) $optV) ?>" <?= (string) $val === (string) $optV ? 'selected' : '' ?>><?= htmlspecialchars($optL) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($help): ?><span class="muted" style="font-size:11px"><?= htmlspecialchars($help) ?></span><?php endif; ?>
            <?php else:
                $inputType = $type === 'password' ? 'password' : ($type === 'email' ? 'email' : ($type === 'number' ? 'number' : 'text'));
                $shown = ($type === 'password') ? '' : $val;
                $ph = $f['placeholder'] ?? ($type === 'password' && $val === '__exists__' ? '••••••••' : ($defaultHint !== null ? (string) $defaultHint : ''));
            ?>
              <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= htmlspecialchars($label) ?></span>
              <input type="<?= $inputType ?>" name="s[<?= htmlspecialchars($key) ?>]"
                     value="<?= htmlspecialchars($shown) ?>"
                     placeholder="<?= htmlspecialchars($ph) ?>"
                     style="background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px">
              <?php if ($help): ?><span class="muted" style="font-size:11px"><?= htmlspecialchars($help) ?></span><?php endif; ?>
              <?php if ($type !== 'password' && !isset($stored[$key]) && $defaultHint !== null && $defaultHint !== ''): ?>
                <span class="muted" style="font-size:11px"><?= htmlspecialchars(I18n::t('settings_default_hint', ['v' => (string) $defaultHint])) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card" style="display:flex;justify-content:flex-end;gap:10px">
    <button type="submit" class="btn primary"><?= htmlspecialchars(I18n::t('btn_save')) ?></button>
  </div>
</form>
<?php Layout::end();
