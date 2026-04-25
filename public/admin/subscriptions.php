<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;
use Parking\Subscription\KeyGenerator;
use Parking\Subscription\Scheduler;

Auth::require('login.php');

$cur = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id          = (int) ($_POST['id'] ?? 0);
        $customerId  = (int) ($_POST['customer_id'] ?? 0);
        $planId      = (int) ($_POST['plan_id'] ?? 0);
        $startsOn    = (string) ($_POST['starts_on'] ?? '');
        $endsOn      = (string) ($_POST['ends_on'] ?? '');
        $status      = (string) ($_POST['status'] ?? 'active');
        $notes       = trim((string) ($_POST['notes'] ?? ''));
        $keyCode     = trim((string) ($_POST['key_code'] ?? ''));

        if (!in_array($status, ['active','suspended','expired','cancelled'], true)) $status = 'active';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn)) $startsOn = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn))   $endsOn   = date('Y-m-d', strtotime('+1 year'));

        if ($customerId <= 0 || $planId <= 0) {
            Layout::flash(I18n::t('flash_sub_min'), 'err');
        } else {
            try {
                if ($id > 0) {
                    $finalKey = $keyCode !== '' ? $keyCode : $pdo->query("SELECT key_code FROM subscriptions WHERE id=$id")->fetchColumn();
                    $pdo->prepare(
                        'UPDATE subscriptions SET customer_id=?, plan_id=?, key_code=?, starts_on=?, ends_on=?, status=?, notes=? WHERE id=?'
                    )->execute([$customerId, $planId, $finalKey, $startsOn, $endsOn, $status, $notes ?: null, $id]);
                    Scheduler::rebuild($pdo, $id);
                    Layout::flash(I18n::t('flash_sub_updated'));
                    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'subscription.update', 'id' => $id]);
                } else {
                    $finalKey = $keyCode !== '' ? $keyCode : KeyGenerator::unique($pdo);
                    $pdo->prepare(
                        'INSERT INTO subscriptions (customer_id, plan_id, key_code, starts_on, ends_on, status, notes) VALUES (?,?,?,?,?,?,?)'
                    )->execute([$customerId, $planId, $finalKey, $startsOn, $endsOn, $status, $notes ?: null]);
                    $newId = (int) $pdo->lastInsertId();
                    Scheduler::rebuild($pdo, $newId);
                    Layout::flash(I18n::t('flash_sub_created', ['key' => $finalKey]));
                    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'subscription.create', 'id' => $newId, 'key' => $finalKey]);
                }
            } catch (PDOException $e) {
                Layout::flash(I18n::t('flash_sub_dup_key'), 'err');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM subscriptions WHERE id=?')->execute([$id]);
        Layout::flash(I18n::t('flash_sub_deleted'));
        Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'subscription.delete', 'id' => $id]);
    } elseif ($action === 'rebuild_schedule') {
        $id = (int) ($_POST['id'] ?? 0);
        $n = Scheduler::rebuild($pdo, $id);
        Layout::flash(I18n::t('flash_schedule_rebuilt', ['n' => $n]));
    }
    header('Location: subscriptions.php' . (!empty($_GET['customer_id']) ? '?customer_id=' . (int) $_GET['customer_id'] : ''));
    exit;
}

$customerFilter = (int) ($_GET['customer_id'] ?? 0);
$status         = (string) ($_GET['status'] ?? '');
$q              = trim((string) ($_GET['q'] ?? ''));
$edit           = (int) ($_GET['edit'] ?? 0);

$where = []; $args = [];
if ($customerFilter > 0) { $where[] = 's.customer_id = ?'; $args[] = $customerFilter; }
if (in_array($status, ['active','suspended','expired','cancelled'], true)) { $where[] = 's.status = ?'; $args[] = $status; }
if ($q !== '') {
    $where[] = '(s.key_code LIKE ? OR c.full_name LIKE ? OR c.plate LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT s.*, c.full_name AS customer_name, c.plate, p.name AS plan_name, p.period, p.price_cents,
            (SELECT COUNT(*) FROM subscription_payments sp WHERE sp.subscription_id = s.id AND sp.paid_at IS NULL AND sp.due_on <= CURDATE()) AS overdue_count,
            (SELECT COALESCE(SUM(sp.amount_cents),0) FROM subscription_payments sp WHERE sp.subscription_id = s.id AND sp.paid_at IS NULL AND sp.due_on <= CURDATE()) AS overdue_amount
     FROM subscriptions s
     JOIN customers c ON c.id = s.customer_id
     JOIN subscription_plans p ON p.id = s.plan_id
     $whereSql
     ORDER BY s.id DESC LIMIT 200"
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$editing = null;
if ($edit > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id=?');
    $stmt->execute([$edit]);
    $editing = $stmt->fetch() ?: null;
}

$customers = $pdo->query('SELECT id, full_name, plate FROM customers ORDER BY full_name')->fetchAll();
$plans     = $pdo->query("SELECT id, code, name, period, price_cents FROM subscription_plans WHERE active=1 ORDER BY period, name")->fetchAll();

Layout::begin(I18n::t('nav_subscriptions'), 'subscriptions');
$csrf = Auth::csrfToken();
?>
<div class="card">
  <h2><?= htmlspecialchars($editing ? I18n::t('sub_edit') : I18n::t('sub_new')) ?></h2>
  <?php if (!$customers || !$plans): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('sub_need_setup')) ?></div>
  <?php else: ?>
    <form method="post" class="crud">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <label><?= htmlspecialchars(I18n::t('sub_customer')) ?>
        <select name="customer_id" required>
          <option value=""></option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
              <?= ($editing['customer_id'] ?? $customerFilter) == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['full_name']) ?><?= $c['plate'] ? ' (' . htmlspecialchars($c['plate']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= htmlspecialchars(I18n::t('sub_plan')) ?>
        <select name="plan_id" required>
          <option value=""></option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= ($editing['plan_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['name']) ?> · <?= htmlspecialchars(I18n::t('period_' . $p['period'])) ?> · <?= htmlspecialchars($money((int) $p['price_cents'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= htmlspecialchars(I18n::t('sub_starts')) ?>
        <input type="date" name="starts_on" required value="<?= htmlspecialchars($editing['starts_on'] ?? date('Y-m-d')) ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('sub_ends')) ?>
        <input type="date" name="ends_on" required value="<?= htmlspecialchars($editing['ends_on'] ?? date('Y-m-d', strtotime('+1 year'))) ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('sub_status')) ?>
        <select name="status">
          <?php foreach (['active','suspended','expired','cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= ($editing['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('substatus_' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= htmlspecialchars(I18n::t('sub_key')) ?>
        <input name="key_code" value="<?= htmlspecialchars($editing['key_code'] ?? '') ?>" placeholder="<?= htmlspecialchars(I18n::t('sub_key_ph')) ?>">
      </label>
      <label class="full"><?= htmlspecialchars(I18n::t('cust_notes')) ?>
        <textarea name="notes"><?= htmlspecialchars($editing['notes'] ?? '') ?></textarea>
      </label>
      <div class="full" style="display:flex;gap:8px">
        <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="subscriptions.php"><?= htmlspecialchars(I18n::t('btn_cancel')) ?></a><?php endif; ?>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= htmlspecialchars(I18n::t('sub_list')) ?></h2>
  <form class="filters" method="get">
    <label><?= htmlspecialchars(I18n::t('filter_search')) ?>
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(I18n::t('sub_search_ph')) ?>">
    </label>
    <label><?= htmlspecialchars(I18n::t('filter_status')) ?>
      <select name="status">
        <option value=""><?= htmlspecialchars(I18n::t('filter_all')) ?></option>
        <?php foreach (['active','suspended','expired','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('substatus_' . $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($customerFilter): ?>
      <input type="hidden" name="customer_id" value="<?= (int) $customerFilter ?>">
    <?php endif; ?>
    <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('filter_apply')) ?></button>
    <a class="btn ghost" href="subscriptions.php"><?= htmlspecialchars(I18n::t('filter_clear')) ?></a>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_subs')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th>#</th>
        <th><?= htmlspecialchars(I18n::t('sub_key')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_customer')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_plan')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_period_label')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_status')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_overdue')) ?></th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $s): ?>
          <tr>
            <td>#<?= (int) $s['id'] ?></td>
            <td><code class="k"><?= htmlspecialchars($s['key_code']) ?></code></td>
            <td><?= htmlspecialchars($s['customer_name']) ?><?= $s['plate'] ? ' <span class="muted">(' . htmlspecialchars($s['plate']) . ')</span>' : '' ?></td>
            <td><?= htmlspecialchars($s['plan_name']) ?> <span class="muted">· <?= htmlspecialchars(I18n::t('period_' . $s['period'])) ?></span></td>
            <td><?= htmlspecialchars($s['starts_on']) ?> → <?= htmlspecialchars($s['ends_on']) ?></td>
            <td><span class="badge <?= $s['status'] === 'active' ? 'active' : 'expired' ?>"><?= htmlspecialchars(I18n::t('substatus_' . $s['status'])) ?></span></td>
            <td>
              <?php if ((int) $s['overdue_count'] > 0): ?>
                <span class="badge due"><?= (int) $s['overdue_count'] ?> · <?= htmlspecialchars($money((int) $s['overdue_amount'])) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <a class="btn" href="?edit=<?= (int) $s['id'] ?>"><?= htmlspecialchars(I18n::t('btn_edit')) ?></a>
              <a class="btn ghost" href="payments.php?subscription_id=<?= (int) $s['id'] ?>"><?= htmlspecialchars(I18n::t('btn_payments')) ?></a>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="rebuild_schedule">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn ghost" type="submit"><?= htmlspecialchars(I18n::t('btn_rebuild_schedule')) ?></button>
              </form>
              <form method="post" onsubmit="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete')) ?>')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn danger" type="submit"><?= htmlspecialchars(I18n::t('btn_delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php Layout::end();
