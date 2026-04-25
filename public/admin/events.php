<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\I18n;

Auth::require('login.php');

$type     = (string) ($_GET['type'] ?? '');
$pin      = trim((string) ($_GET['pin'] ?? ''));
$dateFrom = (string) ($_GET['from'] ?? '');
$dateTo   = (string) ($_GET['to'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 50;
$offset   = ($page - 1) * $perPage;

$validTypes = [
    'entry','scan_at_pay','payment_start','payment_ok','payment_fail',
    'scan_at_exit','gate_open','denied','whatsapp_sent','whatsapp_fail',
    'email_sent','email_fail','subscription_entry','subscription_exit',
    'subscription_payment','admin_login','admin_logout','admin_action',
];

$where = []; $args = [];
if (in_array($type, $validTypes, true)) { $where[] = 'event_type = ?'; $args[] = $type; }
if ($pin !== '')                        { $where[] = 'pin LIKE ?';     $args[] = '%' . $pin . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 'created_at >= ?'; $args[] = $dateFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $where[] = 'created_at <= ?'; $args[] = $dateTo   . ' 23:59:59'; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM gate_events $whereSql");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM gate_events $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$qs = fn (array $over) => http_build_query(array_merge($_GET, $over));
$pages = max(1, (int) ceil($total / $perPage));

Layout::begin(I18n::t('nav_events'), 'events');
?>
<div class="card">
  <form class="filters" method="get">
    <label><?= htmlspecialchars(I18n::t('filter_event_type')) ?>
      <select name="type">
        <option value=""><?= htmlspecialchars(I18n::t('filter_all')) ?></option>
        <?php foreach ($validTypes as $t): ?>
          <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>PIN
      <input name="pin" value="<?= htmlspecialchars($pin) ?>" maxlength="6">
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_from')) ?>
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_to')) ?>
      <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
    </label>
    <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('filter_apply')) ?></button>
    <a class="btn ghost" href="events.php"><?= htmlspecialchars(I18n::t('filter_clear')) ?></a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_events')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th>#</th>
        <th><?= htmlspecialchars(I18n::t('col_when')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_event')) ?></th>
        <th>PIN</th>
        <th><?= htmlspecialchars(I18n::t('col_session')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_subscription')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_details')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td><?= htmlspecialchars((new DateTime($r['created_at']))->format('d/m/Y H:i:s')) ?></td>
            <td><?= htmlspecialchars($r['event_type']) ?></td>
            <td><?= $r['pin'] ? '<code class="k">' . htmlspecialchars($r['pin']) . '</code>' : '<span class="muted">—</span>' ?></td>
            <td><?= $r['session_id']      ? '#' . (int) $r['session_id']      : '<span class="muted">—</span>' ?></td>
            <td><?= $r['subscription_id'] ? '#' . (int) $r['subscription_id'] : '<span class="muted">—</span>' ?></td>
            <td><?php
              if (!$r['details']) { echo '<span class="muted">—</span>'; }
              else {
                $d = json_decode((string) $r['details'], true);
                echo '<code class="k">' . htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
              }
            ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pager">
      <span><?= htmlspecialchars(I18n::t('pager_summary', ['from' => $offset + 1, 'to' => min($offset + $perPage, $total), 'total' => $total])) ?></span>
      <span>
        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= htmlspecialchars($qs(['page' => max(1, $page - 1)])) ?>">&larr; <?= htmlspecialchars(I18n::t('pager_prev')) ?></a>
        <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="?<?= htmlspecialchars($qs(['page' => min($pages, $page + 1)])) ?>"><?= htmlspecialchars(I18n::t('pager_next')) ?> &rarr;</a>
      </span>
    </div>
  <?php endif; ?>
</div>
<?php Layout::end();
