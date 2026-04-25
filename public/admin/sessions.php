<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\I18n;

Auth::require('login.php');

$cur = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (?int $c) => $c === null ? '—' : $cur . ' ' . number_format($c / 100, 2, '.', ',');

// --- Filters (persisted via querystring; survive page reload) ---------------

$status   = (string) ($_GET['status'] ?? '');
$channel  = (string) ($_GET['channel'] ?? '');
$q        = trim((string) ($_GET['q'] ?? ''));
$dateFrom = (string) ($_GET['from'] ?? '');
$dateTo   = (string) ($_GET['to'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

$where = []; $args = [];
$validStatus = ['active','paid','exited','expired','cancelled'];
$validChan   = ['gate','totem','api'];

if (in_array($status, $validStatus, true))  { $where[] = 's.status = ?';        $args[] = $status; }
if (in_array($channel, $validChan, true))   { $where[] = 's.entry_channel = ?'; $args[] = $channel; }
if ($q !== '') {
    $where[] = '(s.pin LIKE ? OR s.customer_phone LIKE ? OR s.customer_email LIKE ? OR c.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 's.entered_at >= ?'; $args[] = $dateFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $where[] = 's.entered_at <= ?'; $args[] = $dateTo   . ' 23:59:59'; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (function () use ($pdo, $whereSql, $args) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM parking_sessions s
         LEFT JOIN customers c ON c.id = s.customer_id $whereSql"
    );
    $stmt->execute($args);
    return $stmt->fetchColumn();
})();

$stmt = $pdo->prepare(
    "SELECT s.*, c.full_name AS customer_name
     FROM parking_sessions s
     LEFT JOIN customers c ON c.id = s.customer_id
     $whereSql
     ORDER BY s.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$qs = function (array $over) {
    return http_build_query(array_merge($_GET, $over));
};
$pages = max(1, (int) ceil($total / $perPage));

Layout::begin(I18n::t('nav_sessions'), 'sessions');
?>
<div class="card">
  <form class="filters" method="get">
    <label><?= htmlspecialchars(I18n::t('filter_search')) ?>
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(I18n::t('filter_search_ph')) ?>">
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_status')) ?>
      <select name="status">
        <option value=""><?= htmlspecialchars(I18n::t('filter_all')) ?></option>
        <?php foreach ($validStatus as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_channel')) ?>
      <select name="channel">
        <option value=""><?= htmlspecialchars(I18n::t('filter_all')) ?></option>
        <?php foreach ($validChan as $c): ?>
          <option value="<?= $c ?>" <?= $channel === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_from')) ?>
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_to')) ?>
      <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
    </label>
    <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('filter_apply')) ?></button>
    <a class="btn ghost" href="sessions.php"><?= htmlspecialchars(I18n::t('filter_clear')) ?></a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_sessions')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th>#</th>
        <th>PIN</th>
        <th><?= htmlspecialchars(I18n::t('col_customer')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_channel')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_entered')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_paid')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_exited')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_status')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_amount')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td><code class="k"><?= htmlspecialchars($r['pin']) ?></code></td>
            <td>
              <?php if ($r['customer_name']): ?>
                <?= htmlspecialchars($r['customer_name']) ?>
              <?php elseif ($r['customer_email'] || $r['customer_phone']): ?>
                <span class="muted"><?= htmlspecialchars($r['customer_email'] ?: $r['customer_phone']) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><span class="badge exited"><?= htmlspecialchars($r['entry_channel']) ?></span></td>
            <td><?= htmlspecialchars((new DateTime($r['entered_at']))->format('d/m/Y H:i')) ?></td>
            <td><?= $r['paid_at']   ? htmlspecialchars((new DateTime($r['paid_at']))->format('d/m H:i'))   : '<span class="muted">—</span>' ?></td>
            <td><?= $r['exited_at'] ? htmlspecialchars((new DateTime($r['exited_at']))->format('d/m H:i')) : '<span class="muted">—</span>' ?></td>
            <td><span class="badge <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="num"><?= htmlspecialchars($money(isset($r['amount_cents']) ? (int) $r['amount_cents'] : null)) ?></td>
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
