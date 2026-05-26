<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\EventHumanizer;
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
            $st = $pdo->prepare('SELECT * FROM barriers WHERE code = ? LIMIT 1');
            $st->execute([$barrier]);
            $row = $st->fetch();
            if (!$row) {
                Layout::flash(I18n::t('flash_barrier_unknown'), 'err');
            } else {
                $direction = (string) ($row['direction'] ?? $barrier);
                $name = (string) $row['name'];
                $mqtt->openBarrier($direction, $row['mqtt_control_topic'] ?? null);
                Db::logEvent($pdo, null, null, 'barrier', [
                    'action'      => 'open',
                    'barrier'     => $barrier,
                    'direction'   => $direction,
                    'car_park_id' => (int) ($row['car_park_id'] ?? 0),
                    'user'        => $user,
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

$barriers = $pdo->query(
    "SELECT b.*, cp.name AS car_park_name, cp.code AS car_park_code
     FROM barriers b
     LEFT JOIN car_parks cp ON cp.id = b.car_park_id
     ORDER BY COALESCE(cp.name, ''), b.direction, b.code"
)->fetchAll();

// Seeded "default" car park gets a translated label; everything else
// is admin-provided text and renders as-is.
$parkLabel = static function (?string $code, ?string $name): string {
    if ($code === 'default') return I18n::t('cp_default_name');
    return (string) ($name ?? '—');
};

$barriersByPark = [];
foreach ($barriers as $b) {
    $key = $parkLabel($b['car_park_code'] ?? null, $b['car_park_name'] ?? null);
    $barriersByPark[$key][] = $b;
}

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

/* --- Barrier illustration: front-on view, boom lifts when the gate opens --- */
.bz{position:relative;width:100%;max-width:340px;margin:2px auto;height:140px;
  border-radius:12px;overflow:hidden;border:1px solid var(--border);
  background:linear-gradient(180deg,#0d1538 0%,#10183f 55%,#0b1130 100%)}
.bz.open{box-shadow:inset 0 0 0 1px rgba(52,211,153,.35)}
.bz.closed{box-shadow:inset 0 0 0 1px rgba(248,113,113,.30)}
/* the road recedes from the viewer — a perspective trapezoid... */
.bz-road{position:absolute;left:0;right:0;bottom:0;height:104px;
  background:linear-gradient(180deg,#2a3360,#1b2446);
  clip-path:polygon(38% 0,62% 0,100% 100%,0 100%)}
/* ...with a vertical centre lane line going away from the viewer. */
.bz-lane{position:absolute;left:50%;bottom:0;width:54px;height:104px;
  transform:translateX(-50%);
  clip-path:polygon(44% 0,56% 0,70% 100%,30% 100%);
  background:repeating-linear-gradient(0deg,#f4c542 0 15px,transparent 15px 34px);opacity:.7}
.bz-car{position:absolute;bottom:24px;left:50%;margin-left:-23px;width:46px;height:40px;z-index:2;
  filter:drop-shadow(0 5px 5px rgba(0,0,0,.5))}
.bz-car span{position:absolute}
.bz-car .cw{bottom:0;width:11px;height:11px;border-radius:50%;
  background:radial-gradient(circle at 40% 40%,#3a3f4d,#0d1018)}
.bz-car .cw.l{left:3px}
.bz-car .cw.r{right:3px}
.bz-car .cbody{bottom:5px;left:1px;width:44px;height:20px;border-radius:7px;
  background:linear-gradient(180deg,#f0564f,#be2b29)}
.bz-car .ccabin{bottom:19px;left:9px;width:28px;height:15px;border-radius:8px 8px 3px 3px;
  background:linear-gradient(180deg,#f0564f,#cf322f)}
.bz-car .cglass{bottom:21px;left:12px;width:22px;height:10px;border-radius:5px 5px 2px 2px;
  background:linear-gradient(180deg,#cfe3f2,#8fb4d0)}
.bz-car .cgrille{bottom:8px;left:10px;width:26px;height:4px;border-radius:2px;background:rgba(0,0,0,.45)}
.bz-car .clight{bottom:9px;width:8px;height:6px;border-radius:2px;
  background:#ffe487;box-shadow:0 0 7px #ffd24d}
.bz-car .clight.l{left:4px}
.bz-car .clight.r{right:4px}
.bz-base{position:absolute;left:13px;bottom:32px;width:30px;height:10px;border-radius:3px;
  background:linear-gradient(180deg,#454f78,#2a3252);z-index:3}
.bz-post{position:absolute;left:22px;bottom:36px;width:12px;height:36px;border-radius:2px;
  background:linear-gradient(90deg,#8893b7,#cdd5ec 45%,#5c668c);z-index:3}
.bz-pivot{position:absolute;left:19px;bottom:66px;width:17px;height:17px;border-radius:50%;z-index:5;
  background:radial-gradient(circle at 35% 35%,#e3e9f9,#7c88ad);border:1px solid rgba(0,0,0,.35)}
/* the boom is long enough to bar the car; when open it stands up out of frame */
.bz-boom{position:absolute;left:27px;bottom:70px;width:250px;height:12px;border-radius:6px;z-index:4;
  transform-origin:6px 6px;transform:rotate(0deg);
  transition:transform 1s cubic-bezier(.22,1,.36,1);
  background:repeating-linear-gradient(45deg,#e5484d 0 13px,#f4f6fb 13px 26px);
  border:1px solid rgba(0,0,0,.4);box-shadow:0 3px 8px rgba(0,0,0,.45)}
.bz-boom.open{transform:rotate(-84deg)}
.bz-boom.unknown{transform:rotate(-45deg);filter:grayscale(.85) brightness(.85)}
</style>

<p class="muted" style="margin:-4px 0 18px;max-width:760px"><?= htmlspecialchars(I18n::t('bar_intro')) ?></p>

<?php foreach ($barriersByPark as $parkName => $list): ?>
  <h2 style="margin:8px 0 10px"><?= htmlspecialchars($parkName) ?></h2>
  <div class="grid k2">
    <?php foreach ($list as $b):
        $st   = (string) $b['status'];
        $code = (string) $b['code'];
        // Prefer a translated name; fall back to the DB name for any
        // barrier code without a bar_name_* string.
        $name = I18n::t('bar_name_' . $code);
        if ($name === 'bar_name_' . $code) $name = (string) $b['name'];
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
      <div class="bz-lane"></div>
      <div class="bz-car">
        <span class="cw l"></span><span class="cw r"></span>
        <span class="cbody"></span>
        <span class="ccabin"></span>
        <span class="cglass"></span>
        <span class="cgrille"></span>
        <span class="clight l"></span><span class="clight r"></span>
      </div>
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
<?php endforeach; ?>

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
            if (!is_array($d)) $d = [];
            $act = (string) ($d['action'] ?? 'barrier');
            $actKey = 'bar_action_' . $act;
            $actLbl = I18n::t($actKey);
            if ($actLbl === $actKey) $actLbl = $act;
            $detail = EventHumanizer::render('barrier', $d);
        ?>
          <tr>
            <td><?= htmlspecialchars((new DateTime($e['created_at']))->format('d/m/Y H:i:s')) ?></td>
            <td><?= htmlspecialchars($actLbl) ?></td>
            <td>
              <?php if ($detail !== null): ?>
                <?= htmlspecialchars($detail) ?>
              <?php else: ?>
                <code class="k"><?= htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code>
              <?php endif; ?>
            </td>
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
