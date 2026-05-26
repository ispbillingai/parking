<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\I18n;

Auth::require('login.php');

$cur   = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');

$periodDays = (int) ($_GET['days'] ?? 365);
if (!in_array($periodDays, [90, 365, 730], true)) $periodDays = 365;

// Paid installments for non-daily plans, grouped by year-month with a
// per-method breakdown so the report is useful for cash vs. card vs. bank.
$sql = "SELECT DATE_FORMAT(sp.paid_at, '%Y-%m') AS ym,
               COALESCE(sp.method, 'other')      AS method,
               SUM(sp.amount_cents)              AS cents,
               COUNT(*)                          AS cnt
        FROM subscription_payments sp
        JOIN subscriptions s ON s.id = sp.subscription_id
        JOIN subscription_plans p ON p.id = s.plan_id
        WHERE p.period <> 'daily'
          AND sp.paid_at IS NOT NULL
          AND sp.paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY ym, method
        ORDER BY ym DESC";
$st = $pdo->prepare($sql);
$st->execute([$periodDays]);

$grouped = [];     // ym => ['total' => int, 'cnt' => int, 'methods' => [method => cents]]
$totalAll = 0;
foreach ($st->fetchAll() as $r) {
    $ym = $r['ym'];
    if (!isset($grouped[$ym])) $grouped[$ym] = ['total' => 0, 'cnt' => 0, 'methods' => []];
    $grouped[$ym]['total']            += (int) $r['cents'];
    $grouped[$ym]['cnt']              += (int) $r['cnt'];
    $grouped[$ym]['methods'][$r['method']] = (int) $r['cents'];
    $totalAll += (int) $r['cents'];
}

$methodLabel = fn (string $m) => I18n::t('method_' . $m);

Layout::begin(I18n::t('rev_subs_title'), 'revenue_subs');
?>
<p class="muted" style="margin:-4px 0 14px;max-width:760px"><?= htmlspecialchars(I18n::t('rev_subs_intro')) ?></p>

<form class="filters" method="get">
  <label><?= htmlspecialchars(I18n::t('rev_period')) ?>
    <select name="days" onchange="this.form.submit()">
      <option value="90"  <?= $periodDays === 90  ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_90')) ?></option>
      <option value="365" <?= $periodDays === 365 ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_365')) ?></option>
      <option value="730" <?= $periodDays === 730 ? 'selected' : '' ?>><?= htmlspecialchars(I18n::t('rev_period_730')) ?></option>
    </select>
  </label>
</form>

<div class="grid k2">
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('rev_grand_total')) ?></div><div class="val"><?= htmlspecialchars($money((int) $totalAll)) ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('rev_col_paid_count')) ?></div><div class="val"><?= array_sum(array_column($grouped, 'cnt')) ?></div></div>
</div>

<div class="card" style="margin-top:18px">
  <?php if (!$grouped): ?>
    <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_data')) ?></div>
  <?php else: ?>
    <table class="t">
      <thead><tr>
        <th><?= htmlspecialchars(I18n::t('rev_col_month')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_total')) ?></th>
        <th class="num"><?= htmlspecialchars(I18n::t('rev_col_paid_count')) ?></th>
        <th><?= htmlspecialchars(I18n::t('rev_col_method')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($grouped as $ym => $g): ?>
          <tr>
            <td><?= htmlspecialchars((new DateTime($ym . '-01'))->format('m/Y')) ?></td>
            <td class="num"><b><?= htmlspecialchars($money($g['total'])) ?></b></td>
            <td class="num"><?= $g['cnt'] ?></td>
            <td>
              <?php foreach ($g['methods'] as $m => $c): ?>
                <span class="badge"><?= htmlspecialchars($methodLabel($m)) ?>: <?= htmlspecialchars($money($c)) ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php Layout::end();
