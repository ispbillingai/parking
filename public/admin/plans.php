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

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $code   = trim((string) ($_POST['code'] ?? ''));
        $name   = trim((string) ($_POST['name'] ?? ''));
        $period = (string) ($_POST['period'] ?? 'monthly');
        $price  = (int) round(((float) ($_POST['price'] ?? 0)) * 100);
        $active = isset($_POST['active']) ? 1 : 0;

        if (!in_array($period, ['weekly','monthly','annual'], true)) $period = 'monthly';
        if ($code === '' || $name === '' || $price < 0) {
            Layout::flash(I18n::t('flash_plan_min'), 'err');
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare(
                        'UPDATE subscription_plans SET code=?, name=?, period=?, price_cents=?, active=? WHERE id=?'
                    )->execute([$code, $name, $period, $price, $active, $id]);
                    Layout::flash(I18n::t('flash_plan_updated'));
                    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'plan.update', 'id' => $id]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO subscription_plans (code, name, period, price_cents, active) VALUES (?,?,?,?,?)'
                    )->execute([$code, $name, $period, $price, $active]);
                    Layout::flash(I18n::t('flash_plan_created'));
                    Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'plan.create', 'id' => (int) $pdo->lastInsertId()]);
                }
            } catch (PDOException $e) {
                Layout::flash(I18n::t('flash_plan_dup'), 'err');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM subscription_plans WHERE id=?')->execute([$id]);
            Layout::flash(I18n::t('flash_plan_deleted'));
            Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'plan.delete', 'id' => $id]);
        } catch (PDOException $e) {
            Layout::flash(I18n::t('flash_plan_in_use'), 'err');
        }
    }
    header('Location: plans.php');
    exit;
}

$rows = $pdo->query('SELECT * FROM subscription_plans ORDER BY active DESC, period, name')->fetchAll();

$edit = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($edit > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subscription_plans WHERE id=?');
    $stmt->execute([$edit]);
    $editing = $stmt->fetch() ?: null;
}

Layout::begin(I18n::t('nav_plans'), 'plans');
$csrf = Auth::csrfToken();
?>
<div class="grid k2">
  <div class="card">
    <h2><?= htmlspecialchars($editing ? I18n::t('plan_edit') : I18n::t('plan_new')) ?></h2>
    <form method="post" class="crud">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <label><?= htmlspecialchars(I18n::t('plan_code')) ?>
        <input name="code" required value="<?= htmlspecialchars($editing['code'] ?? '') ?>" placeholder="MONTH_BASIC">
      </label>
      <label><?= htmlspecialchars(I18n::t('plan_name')) ?>
        <input name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>" placeholder="<?= htmlspecialchars(I18n::t('plan_name_ph')) ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('plan_period')) ?>
        <select name="period">
          <?php foreach (['weekly','monthly','annual'] as $p): ?>
            <option value="<?= $p ?>" <?= ($editing['period'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('period_' . $p)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= htmlspecialchars(I18n::t('plan_price')) ?> (<?= htmlspecialchars($cur) ?>)
        <input type="number" step="0.01" min="0" name="price" required value="<?= htmlspecialchars(number_format(((int) ($editing['price_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
      </label>
      <label class="full" style="flex-direction:row;align-items:center;gap:10px">
        <input type="checkbox" name="active" <?= !$editing || $editing['active'] ? 'checked' : '' ?>>
        <?= htmlspecialchars(I18n::t('plan_active')) ?>
      </label>
      <div class="full" style="display:flex;gap:8px">
        <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="plans.php"><?= htmlspecialchars(I18n::t('btn_cancel')) ?></a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('plan_list')) ?></h2>
    <?php if (!$rows): ?>
      <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_plans')) ?></div>
    <?php else: ?>
      <table class="t">
        <thead><tr>
          <th><?= htmlspecialchars(I18n::t('plan_code')) ?></th>
          <th><?= htmlspecialchars(I18n::t('plan_name')) ?></th>
          <th><?= htmlspecialchars(I18n::t('plan_period')) ?></th>
          <th><?= htmlspecialchars(I18n::t('plan_price')) ?></th>
          <th></th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <tr>
              <td><code class="k"><?= htmlspecialchars($p['code']) ?></code></td>
              <td><?= htmlspecialchars($p['name']) ?></td>
              <td><span class="badge exited"><?= htmlspecialchars(I18n::t('period_' . $p['period'])) ?></span></td>
              <td class="num"><?= htmlspecialchars($money((int) $p['price_cents'])) ?></td>
              <td><?php if ($p['active']): ?><span class="badge active"><?= htmlspecialchars(I18n::t('plan_active')) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td class="row-actions">
                <a class="btn" href="?edit=<?= (int) $p['id'] ?>"><?= htmlspecialchars(I18n::t('btn_edit')) ?></a>
                <form method="post" onsubmit="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete')) ?>')">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn danger" type="submit"><?= htmlspecialchars(I18n::t('btn_delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php Layout::end();
