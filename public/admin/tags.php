<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');
    $tag    = trim((string) ($_POST['tag_code'] ?? ''));

    if ($action === 'dismiss' && $tag !== '') {
        $pdo->prepare('DELETE FROM unregistered_tags WHERE tag_code = ?')->execute([$tag]);
        Layout::flash(I18n::t('flash_tag_dismissed'));
        Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'tag.dismiss', 'tag' => $tag]);
    }
    header('Location: tags.php');
    exit;
}

$rows = $pdo->query(
    'SELECT tag_code, last_gate, scan_count, first_seen_at, last_seen_at
     FROM unregistered_tags
     ORDER BY last_seen_at DESC
     LIMIT 200'
)->fetchAll();

Layout::begin(I18n::t('nav_tags'), 'tags');
$csrf = Auth::csrfToken();
?>
<div class="card">
  <h2><?= htmlspecialchars(I18n::t('tags_unreg_title')) ?></h2>
  <p class="muted"><?= htmlspecialchars(I18n::t('tags_unreg_help')) ?></p>
  <?php if (!$rows): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('tags_empty')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th><?= htmlspecialchars(I18n::t('tags_code')) ?></th>
        <th><?= htmlspecialchars(I18n::t('tags_last_gate')) ?></th>
        <th><?= htmlspecialchars(I18n::t('tags_scans')) ?></th>
        <th><?= htmlspecialchars(I18n::t('tags_first_seen')) ?></th>
        <th><?= htmlspecialchars(I18n::t('tags_last_seen')) ?></th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $t): ?>
          <tr>
            <td><code class="k"><?= htmlspecialchars($t['tag_code']) ?></code></td>
            <td><?= $t['last_gate'] ? htmlspecialchars(I18n::t('nav_barriers') . ' · ' . $t['last_gate']) : '<span class="muted">—</span>' ?></td>
            <td class="num"><?= (int) $t['scan_count'] ?></td>
            <td><?= htmlspecialchars((string) $t['first_seen_at']) ?></td>
            <td><?= htmlspecialchars((string) $t['last_seen_at']) ?></td>
            <td class="row-actions">
              <a class="btn primary" href="subscriptions.php?key=<?= urlencode($t['tag_code']) ?>"><?= htmlspecialchars(I18n::t('tags_assign')) ?></a>
              <form method="post" onsubmit="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete')) ?>')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="tag_code" value="<?= htmlspecialchars($t['tag_code']) ?>">
                <button class="btn danger" type="submit"><?= htmlspecialchars(I18n::t('tags_dismiss')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php Layout::end();
