<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

// --- Mutations -------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $name  = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? ''));
        $plate = strtoupper(trim((string) ($_POST['plate'] ?? '')));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '' && $email === '' && $phone === '') {
            Layout::flash(I18n::t('flash_customer_min'), 'err');
        } else {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE customers SET full_name=?, email=?, phone=?, plate=?, notes=? WHERE id=?'
                )->execute([$name, $email ?: null, $phone ?: null, $plate ?: null, $notes ?: null, $id]);
                Layout::flash(I18n::t('flash_customer_updated'));
                Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'customer.update', 'id' => $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO customers (full_name, email, phone, plate, notes) VALUES (?,?,?,?,?)'
                )->execute([$name, $email ?: null, $phone ?: null, $plate ?: null, $notes ?: null]);
                $newId = (int) $pdo->lastInsertId();
                Layout::flash(I18n::t('flash_customer_created'));
                Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'customer.create', 'id' => $newId]);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$id]);
            Layout::flash(I18n::t('flash_customer_deleted'));
            Db::logEvent($pdo, null, null, 'admin_action', ['action' => 'customer.delete', 'id' => $id]);
        }
    }
    header('Location: customers.php' . (!empty($_GET['q']) ? '?q=' . urlencode((string) $_GET['q']) : ''));
    exit;
}

// --- Read ------------------------------------------------------------------

$q     = trim((string) ($_GET['q'] ?? ''));
$edit  = (int) ($_GET['edit'] ?? 0);

$where = ''; $args = [];
if ($q !== '') {
    $where = 'WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR plate LIKE ?';
    $like  = '%' . $q . '%';
    $args  = [$like, $like, $like, $like];
}

$stmt = $pdo->prepare(
    "SELECT c.*,
       (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id = c.id AND s.status='active' AND s.ends_on >= CURDATE()) AS active_subs
     FROM customers c $where
     ORDER BY c.id DESC LIMIT 200"
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$editing = null;
if ($edit > 0) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([$edit]);
    $editing = $stmt->fetch() ?: null;
}

Layout::begin(I18n::t('nav_customers'), 'customers');
$csrf = Auth::csrfToken();
?>
<div class="grid k2">
  <div class="card">
    <h2><?= htmlspecialchars($editing ? I18n::t('cust_edit') : I18n::t('cust_new')) ?></h2>
    <form method="post" class="crud">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <label class="full"><?= htmlspecialchars(I18n::t('cust_name')) ?>
        <input name="full_name" required value="<?= htmlspecialchars($editing['full_name'] ?? '') ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('cust_email')) ?>
        <input type="email" name="email" value="<?= htmlspecialchars($editing['email'] ?? '') ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('cust_phone')) ?>
        <input name="phone" placeholder="+39..." value="<?= htmlspecialchars($editing['phone'] ?? '') ?>">
      </label>
      <label><?= htmlspecialchars(I18n::t('cust_plate')) ?>
        <input name="plate" value="<?= htmlspecialchars($editing['plate'] ?? '') ?>">
      </label>
      <label class="full"><?= htmlspecialchars(I18n::t('cust_notes')) ?>
        <textarea name="notes"><?= htmlspecialchars($editing['notes'] ?? '') ?></textarea>
      </label>
      <div class="full" style="display:flex;gap:8px">
        <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="customers.php"><?= htmlspecialchars(I18n::t('btn_cancel')) ?></a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('cust_list')) ?></h2>
    <form class="filters" method="get">
      <label><?= htmlspecialchars(I18n::t('filter_search')) ?>
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(I18n::t('cust_search_ph')) ?>">
      </label>
      <button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('filter_apply')) ?></button>
      <a class="btn ghost" href="customers.php"><?= htmlspecialchars(I18n::t('filter_clear')) ?></a>
    </form>
    <?php if (!$rows): ?>
      <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_customers')) ?></div>
    <?php else: ?>
      <table class="t">
        <thead><tr>
          <th>#</th>
          <th><?= htmlspecialchars(I18n::t('cust_name')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cust_contact')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cust_plate')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cust_active_subs')) ?></th>
          <th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td>#<?= (int) $c['id'] ?></td>
              <td><?= htmlspecialchars($c['full_name']) ?></td>
              <td>
                <?= $c['email'] ? htmlspecialchars($c['email']) . '<br>' : '' ?>
                <?= $c['phone'] ? '<span class="muted">' . htmlspecialchars($c['phone']) . '</span>' : '' ?>
              </td>
              <td><?= htmlspecialchars($c['plate'] ?? '') ?></td>
              <td><?= (int) $c['active_subs'] ?></td>
              <td class="row-actions">
                <a class="btn" href="?edit=<?= (int) $c['id'] ?><?= $q ? '&q=' . urlencode($q) : '' ?>"><?= htmlspecialchars(I18n::t('btn_edit')) ?></a>
                <a class="btn ghost" href="subscriptions.php?customer_id=<?= (int) $c['id'] ?>"><?= htmlspecialchars(I18n::t('btn_view_subs')) ?></a>
                <form method="post" onsubmit="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete')) ?>')">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
