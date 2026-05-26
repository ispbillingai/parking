<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\I18n;

Auth::require('login.php');

$cur   = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');

$periodDays = (int) ($_GET['days'] ?? 30);
if (!in_array($periodDays, [30, 90, 365], true)) $periodDays = 30;

// Per-hour kiosk revenue (parking_sessions paid amounts) grouped by day.
$sqlKiosk = "SELECT DATE(paid_at) AS d,
                    SUM(amount_cents) AS cents,
                    COUNT(*) AS cnt
             FROM parking_sessions
             WHERE paid_at IS NOT NULL
               AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(paid_at)";
$st = $pdo->prepare($sqlKiosk);
$st->execute([$periodDays]);
$kioskRows = [];
foreach ($st->fetchAll() as $r) {
    $kioskRows[$r['d']] = ['cents' => (int) $r['cents'], 'cnt' => (int) $r['cnt']];
}

// Daily 18h passes (subscription_payments tied to a daily plan) grouped by day.
$sqlDaily = "SELECT DATE(sp.paid_at) AS d,
                    SUM(sp.amount_cents) AS cents,
                    COUNT(*) AS cnt
             FROM subscription_payments sp
             JOIN subscriptions s ON s.id = sp.subscription_id
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE p.period = 'daily'
               AND sp.paid_at IS NOT NULL
               AND sp.paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(sp.paid_at)";
$st = $pdo->prepare($sqlDaily);
$st->execute([$periodDays]);
$dailyRows = [];
foreach ($st->fetchAll() as $r) {
    $dailyRows[$r['d']] = ['cents' => (int) $r['cents'], 'cnt' => (int) $r['cnt']];
}

$days = array_unique(array_merge(array_keys($kioskRows), array_keys($dailyRows)));
rsort($days);

$totalK = array_sum(array_column($kioskRows, 'cents'));
$totalD = array_sum(array_column($dailyRows, 'cents'));

Layout::begin(I18n::t('rev_daily_title'), 'revenue_daily');
?>
<p class="muted" style="margin:-4px 0 14px;max-width:760px"><?= htmlspecialchars(I18n::t('rev_daily_intro')) ?></p>

<form class="filters" method="get">
  <label><?= htmlspecialchars(I18n::t('rev_period')) ?>
    <select name="days" onchange="this.form.submit()">
      <option value="30"  <?= $periodDays === 30  ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_30')) ?></option>
      <option value="90"  <?= $periodDays === 90  ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_90')) ?></option>
      <option value="365" <?= $periodDays === 365 ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_365')) ?></option>
    </select>
  </label>
</form>

<div class="grid k3">
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('rev_col_kiosk')) ?></div><div class="val"><?= htmlspecialchars($money((int) $totalK)) ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('rev_col_daily')) ?></div><div class="val"><?= htmlspecialchars($money((int) $totalD)) ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('rev_grand_total')) ?></div><div class="val"><?= htmlspecialchars($money((int) $totalK + (int) $totalD)) ?></div></div>
</div>

<div class="card" style="margin-top:18px">
  <?php if (!$days): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_data')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th><?= htmlspecialchars(I18n::t('rev_col_day')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_kiosk')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_daily')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_total')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_count')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($days as $d):
          $k = $kioskRows[$d] ?? ['cents' => 0, 'cnt' => 0];
          $dd = $dailyRows[$d] ?? ['cents' => 0, 'cnt' => 0];
          $tot = $k['cents'] + $dd['cents'];
          $cnt = $k['cnt']   + $dd['cnt'];
        ?>
          <tr>
            <td><?= htmlspecialchars((new DateTime($d))->format('d/m/Y')) ?></td>
            <td class="num"><?= htmlspecialchars($money($k['cents'])) ?></td>
            <td class="num"><?= htmlspecialchars($money($dd['cents'])) ?></td>
            <td class="num"><b><?= htmlspecialchars($money($tot)) ?></b></td>
            <td class="num"><?= $cnt ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php Layout::end();
