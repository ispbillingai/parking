<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;
use Parking\Notify\Template;

Auth::require('login.php');

$catalog = Template::catalog();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $channel = (string) ($_POST['channel'] ?? '');
        $event   = (string) ($_POST['event_key'] ?? '');
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = (string) ($_POST['body'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;

        $valid = false;
        foreach ($catalog as $c) {
            if ($c['channel'] === $channel && $c['event_key'] === $event) { $valid = true; break; }
        }
        if (!$valid) {
            Layout::flash(I18n::t('flash_template_invalid'), 'err');
        } elseif ($body === '') {
            Layout::flash(I18n::t('flash_template_body_required'), 'err');
        } else {
            $pdo->prepare(
                'INSERT INTO notification_templates (channel, event_key, subject, body, enabled)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), enabled = VALUES(enabled)'
            )->execute([$channel, $event, $channel === 'email' ? ($subject ?: null) : null, $body, $enabled]);
            Db::logEvent($pdo, null, null, 'admin_action', [
                'action' => 'template.save', 'channel' => $channel, 'event' => $event,
            ]);
            Layout::flash(I18n::t('flash_template_saved'));
        }
    } elseif ($action === 'reset') {
        $channel = (string) ($_POST['channel'] ?? '');
        $event   = (string) ($_POST['event_key'] ?? '');
        $fb = Template::fallback($channel, $event);
        if ($fb['body'] !== '') {
            $pdo->prepare(
                'INSERT INTO notification_templates (channel, event_key, subject, body, enabled)
                 VALUES (?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), enabled = 1'
            )->execute([$channel, $event, $fb['subject'], $fb['body']]);
            Layout::flash(I18n::t('flash_template_reset'));
        }
    }
    header('Location: notifications.php');
    exit;
}

// Load all stored rows keyed by "channel/event_key"
$stored = [];
foreach ($pdo->query('SELECT * FROM notification_templates') as $row) {
    $stored[$row['channel'] . '/' . $row['event_key']] = $row;
}

Layout::begin(I18n::t('nav_notifications'), 'notifications');
$csrf = Auth::csrfToken();
?>
<div class="card">
  <h2><?= htmlspecialchars(I18n::t('notif_intro_title')) ?></h2>
  <p class="muted" style="margin:0;font-size:13px;line-height:1.55">
    <?= htmlspecialchars(I18n::t('notif_intro_body')) ?>
  </p>
</div>

<?php foreach ($catalog as $c):
    $key = $c['channel'] . '/' . $c['event_key'];
    $row = $stored[$key] ?? null;
    $subject = $row['subject'] ?? Template::fallback($c['channel'], $c['event_key'])['subject'];
    $body    = $row['body']    ?? Template::fallback($c['channel'], $c['event_key'])['body'];
    $enabled = $row ? (int) $row['enabled'] === 1 : true;
?>
  <div class="card">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="badge <?= $c['channel'] === 'email' ? 'paid' : 'active' ?>">
        <?= htmlspecialchars($c['channel'] === 'email' ? 'Email' : 'WhatsApp') ?>
      </span>
      <code class="k"><?= htmlspecialchars($c['event_key']) ?></code>
      <?php if (!$enabled): ?><span class="badge expired"><?= htmlspecialchars(I18n::t('notif_disabled')) ?></span><?php endif; ?>
    </h2>
    <p class="muted" style="margin:0 0 14px;font-size:13px;line-height:1.55"><?= htmlspecialchars($c['description']) ?></p>

    <p style="margin:0 0 8px;font-size:12px;color:var(--muted);letter-spacing:.04em">
      <?= htmlspecialchars(I18n::t('notif_placeholders')) ?>:
      <?php foreach ($c['placeholders'] as $p): ?>
        <code class="k">{<?= htmlspecialchars($p) ?>}</code>
      <?php endforeach; ?>
    </p>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="channel" value="<?= htmlspecialchars($c['channel']) ?>">
      <input type="hidden" name="event_key" value="<?= htmlspecialchars($c['event_key']) ?>">

      <?php if ($c['channel'] === 'email'): ?>
        <label style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
          <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= htmlspecialchars(I18n::t('notif_subject')) ?></span>
          <input name="subject" value="<?= htmlspecialchars((string) $subject) ?>"
                 style="background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px">
        </label>
      <?php endif; ?>

      <label style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
        <span class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase"><?= htmlspecialchars(I18n::t('notif_body')) ?></span>
        <textarea name="body" rows="<?= $c['channel'] === 'email' ? 8 : 5 ?>"
                  style="background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px;font-family:Menlo,Consolas,monospace;line-height:1.5"><?= htmlspecialchars((string) $body) ?></textarea>
      </label>

      <label style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span><?= htmlspecialchars(I18n::t('notif_enabled')) ?></span>
      </label>

      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="btn primary"><?= htmlspecialchars(I18n::t('btn_save')) ?></button>
      </div>
    </form>

    <form method="post" style="margin-top:8px"
          onsubmit="return confirm('<?= htmlspecialchars(I18n::t('notif_confirm_reset')) ?>')">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="reset">
      <input type="hidden" name="channel" value="<?= htmlspecialchars($c['channel']) ?>">
      <input type="hidden" name="event_key" value="<?= htmlspecialchars($c['event_key']) ?>">
      <button type="submit" class="btn ghost"><?= htmlspecialchars(I18n::t('notif_reset_default')) ?></button>
    </form>
  </div>
<?php endforeach; ?>
<?php Layout::end();
