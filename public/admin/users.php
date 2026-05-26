<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

$me   = Auth::user();
$meId = (int) ($me['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $role     = (string) ($_POST['role'] ?? 'admin');
        if (!in_array($role, ['admin', 'operator'], true)) $role = 'admin';

        if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Layout::flash(I18n::t('flash_user_min'), 'err');
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE username = ?');
            $exists->execute([$email]);
            if ($exists->fetchColumn()) {
                Layout::flash(I18n::t('flash_user_dup'), 'err');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare(
                    'INSERT INTO admin_users (username, password_hash, full_name, role)
                     VALUES (?,?,?,?)'
                )->execute([$email, $hash, $fullName, $role]);
                Db::logEvent($pdo, null, null, 'admin_user_added', [
                    'email'     => $email,
                    'full_name' => $fullName,
                    'role'      => $role,
                    'by'        => $me['username'] ?? '?',
                ]);
                Layout::flash(I18n::t('flash_user_added'));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $meId) {
            Layout::flash(I18n::t('flash_user_self_delete'), 'err');
        } else {
            $st = $pdo->prepare('SELECT username FROM admin_users WHERE id = ?');
            $st->execute([$id]);
            $email = (string) ($st->fetchColumn() ?: '');
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            Db::logEvent($pdo, null, null, 'admin_user_deleted', [
                'id'    => $id,
                'email' => $email,
                'by'    => $me['username'] ?? '?',
            ]);
            Layout::flash(I18n::t('flash_user_deleted'));
        }
    }

    header('Location: users.php');
    exit;
}

$users = $pdo->query(
    'SELECT id, username, full_name, role, last_login_at, created_at
     FROM admin_users ORDER BY id'
)->fetchAll();

$csrf = Auth::csrfToken();
Layout::begin(I18n::t('usr_title'), 'users');
?>
<p class="muted" style="margin:-4px 0 18px;max-width:760px"><?= htmlspecialchars(I18n::t('usr_intro')) ?></p>

<div class="card">
  <h2><?= htmlspecialchars(I18n::t('usr_new')) ?></h2>
  <form method="post" class="crud" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <label><?= htmlspecialchars(I18n::t('usr_email')) ?><input name="email" type="email" required placeholder="<?= htmlspecialchars(I18n::t('ph_email_example')) ?>"></label>
    <label><?= htmlspecialchars(I18n::t('usr_password')) ?><input name="password" type="password" required minlength="6"></label>
    <label><?= htmlspecialchars(I18n::t('usr_full_name')) ?><input name="full_name" placeholder="<?= htmlspecialchars(I18n::t('ph_full_name')) ?>"></label>
    <label><?= htmlspecialchars(I18n::t('usr_role')) ?>
      <select name="role">
        <option value="admin"><?= htmlspecialchars(I18n::t('usr_role_admin')) ?></option>
        <option value="operator"><?= htmlspecialchars(I18n::t('usr_role_operator')) ?></option>
      </select>
    </label>
    <div class="full"><button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button></div>
  </form>
</div>

<div class="card">
  <table class="t">
    <thead><tr>
      <th><?= htmlspecialchars(I18n::t('usr_email')) ?></th>
      <th><?= htmlspecialchars(I18n::t('usr_full_name')) ?></th>
      <th><?= htmlspecialchars(I18n::t('usr_role')) ?></th>
      <th><?= htmlspecialchars(I18n::t('usr_last_login')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['username']) ?><?= ((int) $u['id'] === $meId) ? ' <span class="badge active">' . htmlspecialchars(I18n::t('usr_you')) . '</span>' : '' ?></td>
          <td><?= htmlspecialchars((string) ($u['full_name'] ?? '')) ?></td>
          <td><?= htmlspecialchars(I18n::t('usr_role_' . $u['role'])) ?></td>
          <td><?= $u['last_login_at']
                ? htmlspecialchars((new DateTime($u['last_login_at']))->format('d/m/Y H:i'))
                : '<span class="muted">' . htmlspecialchars(I18n::t('usr_never')) . '</span>' ?></td>
          <td>
            <?php if ((int) $u['id'] !== $meId): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn danger" type="submit" onclick="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete'), ENT_QUOTES) ?>')"><?= htmlspecialchars(I18n::t('btn_delete')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php Layout::end();
