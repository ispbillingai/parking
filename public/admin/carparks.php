<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Db;
use Parking\I18n;

Auth::require('login.php');

$user = (string) (Auth::user()['username'] ?? '?');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_park') {
        $code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '' || $name === '') {
            Layout::flash(I18n::t('flash_cp_min'), 'err');
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM car_parks WHERE code = ?');
            $exists->execute([$code]);
            if ($exists->fetchColumn()) {
                Layout::flash(I18n::t('flash_cp_dup_code'), 'err');
            } else {
                $pdo->prepare('INSERT INTO car_parks (code, name) VALUES (?,?)')->execute([$code, $name]);
                Db::logEvent($pdo, null, null, 'admin_action', [
                    'action' => 'car_park_create', 'code' => $code, 'name' => $name, 'user' => $user,
                ]);
                Layout::flash(I18n::t('flash_cp_created'));
            }
        }
    } elseif ($action === 'delete_park') {
        $id = (int) ($_POST['id'] ?? 0);
        $used = $pdo->prepare('SELECT COUNT(*) FROM barriers WHERE car_park_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            Layout::flash(I18n::t('flash_cp_in_use'), 'err');
        } else {
            $pdo->prepare('DELETE FROM car_parks WHERE id = ?')->execute([$id]);
            Db::logEvent($pdo, null, null, 'admin_action', [
                'action' => 'car_park_delete', 'id' => $id, 'user' => $user,
            ]);
            Layout::flash(I18n::t('flash_cp_deleted'));
        }
    } elseif ($action === 'add_barrier') {
        $code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $dir  = (string) ($_POST['direction'] ?? '');
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $parkId = (int) ($_POST['car_park_id'] ?? 0);
        if ($code === '' || $name === '' || !in_array($dir, ['entrance', 'exit'], true) || $parkId <= 0) {
            Layout::flash(I18n::t('flash_barrier_min'), 'err');
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM barriers WHERE code = ?');
            $exists->execute([$code]);
            if ($exists->fetchColumn()) {
                Layout::flash(I18n::t('flash_barrier_dup'), 'err');
            } else {
                $pdo->prepare(
                    'INSERT INTO barriers (code, car_park_id, name, direction, mqtt_control_topic)
                     VALUES (?,?,?,?,?)'
                )->execute([$code, $parkId, $name, $dir, $topic === '' ? null : $topic]);
                Db::logEvent($pdo, null, null, 'admin_action', [
                    'action' => 'barrier_add', 'code' => $code, 'direction' => $dir,
                    'car_park_id' => $parkId, 'topic' => $topic, 'user' => $user,
                ]);
                Layout::flash(I18n::t('flash_barrier_added'));
            }
        }
    } elseif ($action === 'delete_barrier') {
        $code = (string) ($_POST['code'] ?? '');
        if ($code === 'entrance' || $code === 'exit') {
            Layout::flash(I18n::t('flash_barrier_unknown'), 'err');
        } else {
            $pdo->prepare('DELETE FROM barriers WHERE code = ?')->execute([$code]);
            Db::logEvent($pdo, null, null, 'admin_action', [
                'action' => 'barrier_delete', 'code' => $code, 'user' => $user,
            ]);
            Layout::flash(I18n::t('flash_barrier_deleted'));
        }
    }

    header('Location: carparks.php');
    exit;
}

$parks = $pdo->query('SELECT * FROM car_parks ORDER BY id')->fetchAll();
$barriers = $pdo->query('SELECT * FROM barriers ORDER BY car_park_id, direction, code')->fetchAll();
$byPark = [];
foreach ($barriers as $b) $byPark[(int) $b['car_park_id']][] = $b;

$csrf = Auth::csrfToken();
Layout::begin(I18n::t('cp_title'), 'carparks');
?>
<p class="muted" style="margin:-4px 0 18px;max-width:760px"><?= htmlspecialchars(I18n::t('cp_intro')) ?></p>

<div class="card">
  <h2><?= htmlspecialchars(I18n::t('cp_new')) ?></h2>
  <form method="post" class="crud">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="create_park">
    <label><?= htmlspecialchars(I18n::t('cp_code')) ?><input name="code" required placeholder="<?= htmlspecialchars(I18n::t('ph_park_code')) ?>"></label>
    <label><?= htmlspecialchars(I18n::t('cp_name')) ?><input name="name" required placeholder="<?= htmlspecialchars(I18n::t('ph_park_name')) ?>"></label>
    <div class="full"><button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button></div>
  </form>
</div>

<?php foreach ($parks as $p): $list = $byPark[(int) $p['id']] ?? []; ?>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <h2 style="margin:0"><?= htmlspecialchars($p['code'] === 'default' ? I18n::t('cp_default_name') : $p['name']) ?> <code class="k"><?= htmlspecialchars($p['code']) ?></code></h2>
      <?php if (!$list && (string) $p['code'] !== 'default'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="delete_park">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn danger" type="submit" onclick="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete'), ENT_QUOTES) ?>')"><?= htmlspecialchars(I18n::t('btn_delete')) ?></button>
        </form>
      <?php endif; ?>
    </div>

    <h3 style="margin:14px 0 6px;font-size:14px;color:var(--muted)"><?= htmlspecialchars(I18n::t('cp_barriers')) ?></h3>
    <?php if (!$list): ?>
      <div class="empty"><?= htmlspecialchars(I18n::t('cp_no_barriers')) ?></div>
    <?php else: ?>
      <table class="t">
        <thead><tr>
          <th><?= htmlspecialchars(I18n::t('cp_barrier_code')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cp_barrier_name')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cp_barrier_dir')) ?></th>
          <th><?= htmlspecialchars(I18n::t('cp_barrier_topic')) ?></th>
          <th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($list as $b): ?>
            <tr>
              <td><code class="k"><?= htmlspecialchars($b['code']) ?></code></td>
              <td><?= htmlspecialchars($b['name']) ?></td>
              <td><?= htmlspecialchars(I18n::t('cp_dir_' . $b['direction'])) ?></td>
              <td><code class="k"><?= htmlspecialchars((string) ($b['mqtt_control_topic'] ?? '')) ?></code></td>
              <td>
                <?php if ($b['code'] !== 'entrance' && $b['code'] !== 'exit'): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete_barrier">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($b['code']) ?>">
                    <button class="btn danger" type="submit" onclick="return confirm('<?= htmlspecialchars(I18n::t('confirm_delete'), ENT_QUOTES) ?>')"><?= htmlspecialchars(I18n::t('btn_delete')) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="margin:18px 0 6px;font-size:14px;color:var(--muted)"><?= htmlspecialchars(I18n::t('cp_add_barrier')) ?></h3>
    <form method="post" class="crud">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="add_barrier">
      <input type="hidden" name="car_park_id" value="<?= (int) $p['id'] ?>">
      <label><?= htmlspecialchars(I18n::t('cp_barrier_code')) ?><input name="code" required placeholder="<?= htmlspecialchars(I18n::t('ph_barrier_code')) ?>"></label>
      <label><?= htmlspecialchars(I18n::t('cp_barrier_name')) ?><input name="name" required placeholder="<?= htmlspecialchars(I18n::t('ph_barrier_name')) ?>"></label>
      <label><?= htmlspecialchars(I18n::t('cp_barrier_dir')) ?>
        <select name="direction">
          <option value="entrance"><?= htmlspecialchars(I18n::t('cp_dir_entrance')) ?></option>
          <option value="exit"><?= htmlspecialchars(I18n::t('cp_dir_exit')) ?></option>
        </select>
      </label>
      <label class="full"><?= htmlspecialchars(I18n::t('cp_barrier_topic')) ?>
        <input name="topic" placeholder="<?= htmlspecialchars(I18n::t('ph_mqtt_topic')) ?>">
        <small style="color:var(--muted);font-size:12px;margin-top:4px"><?= htmlspecialchars(I18n::t('cp_barrier_topic_help')) ?></small>
      </label>
      <div class="full"><button class="btn primary" type="submit"><?= htmlspecialchars(I18n::t('btn_save')) ?></button></div>
    </form>
  </div>
<?php endforeach; ?>
<?php Layout::end();
