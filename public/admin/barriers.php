<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\Settings;
use Parking\Db;
use Parking\Gate\MqttPublisher;
use Parking\I18n;

Auth::require('login.php');

// --- Actions ----------------------------------------------------------------
// Every manual command is published over MQTT and recorded in gate_events
// with event_type 'barrier' so the activity shows up in the audit log.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requirePost();

    $action = (string) ($_POST['action'] ?? '');
    $user   = (string) (Auth::user()['username'] ?? '?');
    $mqtt   = new MqttPublisher($cfg['mqtt']);

    try {
        if ($action === 'open') {
            $barrier = (string) ($_POST['barrier'] ?? '');
            if (!in_array($barrier, ['entrance', 'exit'], true)) {
                Layout::flash(I18n::t('flash_barrier_unknown'), 'err');
            } else {
                $stmt = $pdo->prepare('SELECT name FROM barriers WHERE code = ?');
                $stmt->execute([$barrier]);
                $name = (string) ($stmt->fetchColumn() ?: $barrier);

                $mqtt->openBarrier($barrier);
                Db::logEvent($pdo, null, null, 'barrier', [
                    'action'  => 'open',
                    'barrier' => $barrier,
                    'user'    => $user,
                ]);
                Layout::flash(I18n::t('flash_barrier_opened', ['name' => $name]));
            }
        } elseif ($action === 'traffic') {
            $state = (string) ($_POST['state'] ?? '');
            $full  = $state === 'full';
            $mqtt->setParkingFull($full);
            Settings::set($pdo, 'gate.traffic_light', $full ? 'full' : 'free');
            Db::logEvent($pdo, null, null, 'barrier', [
                'action' => 'traffic_light',
                'state'  => $full ? 'full' : 'free',
                'user'   => $user,
            ]);
            Layout::flash(I18n::t('flash_barrier_traffic', [
                'state' => I18n::t($full ? 'bar_traffic_full' : 'bar_traffic_free'),
            ]));
        } elseif ($action === 'lock') {
            $state  = (string) ($_POST['state'] ?? '');
            $locked = $state === 'locked';
            $mqtt->setEntranceLock($locked);
            Settings::set($pdo, 'gate.entrance_lock', $locked ? 'locked' : 'unlocked');
            Db::logEvent($pdo, null, null, 'barrier', [
                'action' => 'entrance_lock',
                'state'  => $locked ? 'locked' : 'unlocked',
                'user'   => $user,
            ]);
            Layout::flash(I18n::t('flash_barrier_lock', [
                'state' => I18n::t($locked ? 'bar_locked' : 'bar_unlocked'),
            ]));
        }
    } catch (Throwable $e) {
        Layout::flash(I18n::t('flash_barrier_mqtt_err', ['err' => $e->getMessage()]), 'err');
    }

    header('Location: barriers.php');
    exit;
}

// --- Read current state ------------------------------------------------------

$barriers = $pdo->query('SELECT * FROM barriers ORDER BY code')->fetchAll();

$stored      = Settings::all($pdo);
$trafficFull = ($stored['gate.traffic_light'] ?? 'free') === 'full';
$entranceLk  = ($stored['gate.entrance_lock'] ?? 'unlocked') === 'locked';

$recent = $pdo->query(
    "SELECT created_at, details FROM gate_events
     WHERE event_type = 'barrier' ORDER BY id DESC LIMIT 15"
)->fetchAll();

$csrf = Auth::csrfToken();

$statusLabel = [
    'open'    => I18n::t('bar_status_open'),
    'closed'  => I18n::t('bar_status_closed'),
    'unknown' => I18n::t('bar_status_unknown'),
];

Layout::begin(I18n::t('bar_title'), 'barriers');
?>
<style>
.barrier-card{display:flex;flex-direction:column;gap:14px}
.barrier-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.barrier-head h2{margin:0}
.b-state{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700}
.b-dot{width:14px;height:14px;border-radius:50%;background:var(--muted)}
.b-dot.open{background:var(--ok);box-shadow:0 0 12px var(--ok)}
.b-dot.closed{background:var(--err);box-shadow:0 0 10px rgba(248,113,113,.5)}
.b-dot.unknown{background:var(--muted)}
.b-meta{color:var(--muted);font-size:12px}
.lights{display:flex;gap:14px;align-items:center;margin:6px 0}
.light{width:46px;height:46px;border-radius:50%;border:2px solid var(--border);opacity:.22}
.light.green{background:var(--ok)}
.light.red{background:var(--err)}
.light.on{opacity:1}
.light.green.on{box-shadow:0 0 22px var(--ok)}
.light.red.on{box-shadow:0 0 22px var(--err)}
.ctl-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ctl-row form{display:inline}
.pill{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;
  border:1px solid var(--border);background:rgba(255,255,255,.04)}
.pill.warn{background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.35);color:#fde68a}
.pill.ok{background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.35);color:#a7f3d0}

/* --- Barrier illustration: a boom arm that lifts when the gate is open --- */
.bz{position:relative;width:100%;max-width:320px;margin:2px auto;height:130px;
  border-radius:12px;overflow:hidden;border:1px solid var(--border);
  background:linear-gradient(180deg,#0d1538 0%,#182253 72%)}
.bz.open{box-shadow:inset 0 0 0 1px rgba(52,211,153,.35)}
.bz.closed{box-shadow:inset 0 0 0 1px rgba(248,113,113,.30)}
.bz-road{position:absolute;left:0;right:0;bottom:0;height:30px;
  background:#212b52;border-top:2px solid rgba(255,255,255,.06)}
.bz-road::before{content:"";position:absolute;left:0;right:0;top:14px;height:3px;
  background:repeating-linear-gradient(90deg,#f4c542 0 16px,transparent 16px 34px);opacity:.55}
.bz-car{position:absolute;bottom:27px;left:150px;font-size:22px;line-height:1;
  filter:drop-shadow(0 4px 4px rgba(0,0,0,.55))}
.bz-base{position:absolute;left:30px;bottom:25px;width:26px;height:9px;border-radius:3px;
  background:linear-gradient(180deg,#454f78,#2a3252)}
.bz-post{position:absolute;left:38px;bottom:30px;width:10px;height:32px;border-radius:2px;
  background:linear-gradient(90deg,#8893b7,#cdd5ec 45%,#5c668c)}
.bz-pivot{position:absolute;left:36px;bottom:54px;width:14px;height:14px;border-radius:50%;z-index:3;
  background:radial-gradient(circle at 35% 35%,#e3e9f9,#7c88ad);border:1px solid rgba(0,0,0,.35)}
.bz-boom{position:absolute;left:43px;bottom:57px;width:74px;height:9px;border-radius:5px;z-index:2;
  transform-origin:4px 4px;transform:rotate(0deg);
  transition:transform 1s cubic-bezier(.22,1,.36,1);
  background:repeating-linear-gradient(45deg,#e5484d 0 10px,#f4f6fb 10px 20px);
  border:1px solid rgba(0,0,0,.4);box-shadow:0 2px 6px rgba(0,0,0,.45)}
.bz-boom.open{transform:rotate(-78deg)}
.bz-boom.unknown{transform:rotate(-40deg);filter:grayscale(.85) brightness(.85)}
</style>

<p class="muted" style="margin:-4px 0 18px;max-width:760px"><?= htmlspecialchars(I18n::t('bar_intro')) ?></p>

<div class="grid k2">
  <?php foreach ($barriers as $b):
      $st  = (string) $b['status'];
      $name = (string) $b['name'];
  ?>
  <div class="card barrier-card">
    <div class="barrier-head">
      <h2><?= htmlspecialchars($name) ?></h2>
      <span class="b-state">
        <span class="b-dot <?= htmlspecialchars($st) ?>"></span>
        <?= htmlspecialchars($statusLabel[$st] ?? $st) ?>
      </span>
    </div>
    <div class="bz <?= htmlspecialchars($st) ?>">
      <div class="bz-road"></div>
      <div class="bz-car">&#x1F697;</div>
      <div class="bz-base"></div>
      <div class="bz-post"></div>
      <div class="bz-pivot"></div>
      <div class="bz-boom <?= $st === 'open' ? 'open' : ($st === 'unknown' ? 'unknown' : '') ?>"
           data-status="<?= htmlspecialchars($st) ?>"></div>
    </div>
    <div class="b-meta">
      <?= htmlspecialchars(I18n::t('bar_last_change')) ?>:
      <?= $b['status_at']
            ? htmlspecialchars((new DateTime($b['status_at']))->format('d/m/Y H:i:s'))
            : htmlspecialchars(I18n::t('bar_never')) ?>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="open">
      <input type="hidden" name="barrier" value="<?= htmlspecialchars((string) $b['code']) ?>">
      <button class="btn primary" type="submit"
              onclick="return confirm('<?= htmlspecialchars(I18n::t('bar_confirm_open', ['name' => $name]), ENT_QUOTES) ?>')">
        &#x1F6A7; <?= htmlspecialchars(I18n::t('bar_open_now')) ?>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid k2" style="margin-top:4px">
  <!-- Free / Full traffic light -->
  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('bar_traffic_title')) ?></h2>
    <p class="muted" style="font-size:13px"><?= htmlspecialchars(I18n::t('bar_traffic_desc')) ?></p>
    <div class="lights">
      <span class="light green <?= $trafficFull ? '' : 'on' ?>"></span>
      <span class="light red <?= $trafficFull ? 'on' : '' ?>"></span>
      <span class="pill <?= $trafficFull ? 'warn' : 'ok' ?>">
        <?= htmlspecialchars(I18n::t('bar_current')) ?>:
        <?= htmlspecialchars(I18n::t($trafficFull ? 'bar_traffic_full' : 'bar_traffic_free')) ?>
      </span>
    </div>
    <div class="ctl-row">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="traffic">
        <input type="hidden" name="state" value="free">
        <button class="btn" type="submit" <?= $trafficFull ? '' : 'disabled' ?>>
          &#x1F7E2; <?= htmlspecialchars(I18n::t('bar_set_free')) ?>
        </button>
      </form>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="traffic">
        <input type="hidden" name="state" value="full">
        <button class="btn" type="submit" <?= $trafficFull ? 'disabled' : '' ?>>
          &#x1F534; <?= htmlspecialchars(I18n::t('bar_set_full')) ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Entrance barrier lock -->
  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('bar_lock_title')) ?></h2>
    <p class="muted" style="font-size:13px"><?= htmlspecialchars(I18n::t('bar_lock_desc')) ?></p>
    <div class="lights">
      <span class="pill <?= $entranceLk ? 'warn' : 'ok' ?>">
        <?= htmlspecialchars(I18n::t('bar_current')) ?>:
        <?= htmlspecialchars(I18n::t($entranceLk ? 'bar_locked' : 'bar_unlocked')) ?>
      </span>
    </div>
    <div class="ctl-row">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="lock">
        <input type="hidden" name="state" value="locked">
        <button class="btn" type="submit" <?= $entranceLk ? 'disabled' : '' ?>>
          &#x1F512; <?= htmlspecialchars(I18n::t('bar_lock_btn')) ?>
        </button>
      </form>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="lock">
        <input type="hidden" name="state" value="unlocked">
        <button class="btn" type="submit" <?= $entranceLk ? '' : 'disabled' ?>>
          &#x1F513; <?= htmlspecialchars(I18n::t('bar_unlock_btn')) ?>
        </button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h2><?= htmlspecialchars(I18n::t('bar_recent_log')) ?></h2>
  <?php if (!$recent): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_data')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th><?= htmlspecialchars(I18n::t('col_when')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_event')) ?></th>
        <th><?= htmlspecialchars(I18n::t('col_details')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($recent as $e):
            $d = $e['details'] ? json_decode((string) $e['details'], true) : [];
            $act = (string) ($d['action'] ?? 'barrier');
        ?>
          <tr>
            <td><?= htmlspecialchars((new DateTime($e['created_at']))->format('d/m/Y H:i:s')) ?></td>
            <td><?= htmlspecialchars($act) ?></td>
            <td><code class="k"><?= htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-bottom:0"><?= htmlspecialchars(I18n::t('bar_listener_hint')) ?></p>
</div>

<script>
// Animate each boom from "closed" to its real state on load, so an open
// barrier visibly lifts on every refresh.
document.querySelectorAll('.bz-boom').forEach(function (b) {
  var state = b.dataset.status;
  if (state !== 'open' && state !== 'unknown') return;
  b.classList.remove(state);
  void b.offsetWidth;            // force reflow so the transition replays
  requestAnimationFrame(function () { b.classList.add(state); });
});

// Keep the open/closed status fresh while the page is left open.
setTimeout(() => location.reload(), 10000);
</script>
<?php Layout::end();
