<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

$cur = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'mark_paid') {
        $id     = (int) ($_POST['id'] ?? 0);
        $method = (string) ($_POST['method'] ?? 'cash');
        if (!in_array($method, ['cash','card','bank','other'], true)) $method = 'cash';
        $stmt = $pdo->prepare(
            'UPDATE subscription_payments
             SET paid_at = COALESCE(paid_at, NOW()), method = ?
             WHERE id = ?'
        );
        $stmt->execute([$method, $id]);
        $row = $pdo->prepare('SELECT subscription_id, amount_cents FROM subscription_payments WHERE id=?');
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r) {
            Db::logEvent($pdo, null, null, 'subscription_payment', [
                'amount_cents' => (int) $r['amount_cents'],
                'method'       => $method,
                'payment_id'   => $id,
            ], (int) $r['subscription_id']);
        }
        Layout::flash(I18n::t('flash_payment_marked'));
    } elseif ($action === 'unmark_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE subscription_payments SET paid_at = NULL, method = NULL WHERE id=?')->execute([$id]);
        Layout::flash(I18n::t('flash_payment_unmarked'));
    }
    $back = 'payments.php' . (!empty($_GET['subscription_id']) ? '?subscription_id=' . (int) $_GET['subscription_id'] : '');
    header('Location: ' . $back);
    exit;
}

$subId    = (int) ($_GET['subscription_id'] ?? 0);
$onlyDue  = isset($_GET['only_due']);
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 50;
$offset   = ($page - 1) * $perPage;

$where = []; $args = [];
if ($subId > 0) { $where[] = 'sp.subscription_id = ?'; $args[] = $subId; }
if ($onlyDue)   { $where[] = 'sp.paid_at IS NULL'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM subscription_payments sp $whereSql");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sp.*, s.key_code, c.full_name AS customer_name, p.name AS plan_name
     FROM subscription_payments sp
     JOIN subscriptions s ON s.id = sp.subscription_id
     JOIN customers c ON c.id = s.customer_id
     JOIN subscription_plans p ON p.id = s.plan_id
     $whereSql
     ORDER BY sp.due_on DESC, sp.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$qs = fn (array $over) => http_build_query(array_merge($_GET, $over));
$pages = max(1, (int) ceil($total / $perPage));
$today = date('Y-m-d');

Layout::begin(I18n::t('nav_payments'), 'payments');
$csrf = Auth::csrfToken();
?>
<div class="card">
  <form class="filters" method="get">
    <?php if ($subId): ?>
      <label><?= htmlspecialchars(I18n::t('payments_subscription')) ?>
        <input value="#<?= (int) $subId ?>" disabled>
      </label>
      <input type="hidden" name="subscription_id" value="<?= (int) $subId ?>">
    <?php endif; ?>
    <label style="flex-direction:row;align-items:center;gap:8px">
      <input type="checkbox" name="only_due" <?= $onlyDue ? 'checked' : '' ?>>
      <?= htmlspecialchars(I18n::t('payments_only_due')) ?>
    </label>
    <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('filter_apply')) ?></button>
    <a class="btn ghost" href="payments.php"><?= htmlspecialchars(I18n::t('filter_clear')) ?></a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_payments')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th>#</th>
        <th><?= htmlspecialchars(I18n::t('sub_customer')) ?></th>
        <th><?= htmlspecialchars(I18n::t('sub_key')) ?></th>
        <th><?= htmlspecialchars(I18n::t('payments_period')) ?></th>
        <th><?= htmlspecialchars(I18n::t('payments_due')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_amount')) ?></th>
        <th><?= htmlspecialchars(I18n::t('payments_status')) ?></th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $isPaid = $r['paid_at'] !== null;
            $isOverdue = !$isPaid && $r['due_on'] <= $today;
          ?>
          <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td><?= htmlspecialchars($r['customer_name']) ?> <span class="muted">· <?= htmlspecialchars($r['plan_name']) ?></span></td>
            <td><code class="k"><?= htmlspecialchars($r['key_code']) ?></code></td>
            <td><?= htmlspecialchars($r['period_start']) ?> → <?= htmlspecialchars($r['period_end']) ?></td>
            <td><?= htmlspecialchars($r['due_on']) ?></td>
            <td class="num"><?= htmlspecialchars($money((int) $r['amount_cents'])) ?></td>
            <td>
              <?php if ($isPaid): ?>
                <span class="badge paid"><?= htmlspecialchars(I18n::t('payments_paid')) ?> · <?= htmlspecialchars($r['method'] ?? '') ?></span>
                <div class="muted" style="font-size:11px"><?= htmlspecialchars((new DateTime($r['paid_at']))->format('d/m/Y H:i')) ?></div>
              <?php elseif ($isOverdue): ?>
                <span class="badge expired"><?= htmlspecialchars(I18n::t('payments_overdue')) ?></span>
              <?php else: ?>
                <span class="badge due"><?= htmlspecialchars(I18n::t('payments_pending')) ?></span>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if (!$isPaid): ?>
                <form method="post" style="display:inline-flex;gap:4px">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <select name="method" style="padding:5px 8px;border-radius:8px;background:rgba(0,0,0,.3);color:var(--text);border:1px solid var(--border)">
                    <option value="cash"><?= htmlspecialchars(I18n::t('method_cash')) ?></option>
                    <option value="card"><?= htmlspecialchars(I18n::t('method_card')) ?></option>
                    <option value="bank"><?= htmlspecialchars(I18n::t('method_bank')) ?></option>
                    <option value="other"><?= htmlspecialchars(I18n::t('method_other')) ?></option>
                  </select>
                  <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_mark_paid')) ?></button>
                </form>
              <?php else: ?>
                <form method="post" onsubmit="return confirm('<?= htmlspecialchars(I18n::t('confirm_unmark')) ?>')">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="unmark_paid">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn ghost" type="submit"><?= htmlspecialchars(I18n::t('btn_unmark_paid')) ?></button>
                </form>
              <?php endif; ?>
            </td>
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
