<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\I18n;

Auth::require('login.php');

$cur = (string) ($cfg['tariff']['currency_symbol'] ?? '€');
$money = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, '.', ',');

$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

$col = static fn ($v) => $v === null ? null : (int) $v;

$active        = $col($pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE status='active'")->fetchColumn());
$paidWaiting   = $col($pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE status='paid'")->fetchColumn());

$todayEntries  = $col($pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE entered_at BETWEEN '$todayStart' AND '$todayEnd'")->fetchColumn());
$todayPaid     = $col($pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE paid_at BETWEEN '$todayStart' AND '$todayEnd'")->fetchColumn());
$todayRevenue  = $col($pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM parking_sessions WHERE paid_at BETWEEN '$todayStart' AND '$todayEnd'")->fetchColumn());
$todaySubRev   = $col($pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM subscription_payments WHERE paid_at BETWEEN '$todayStart' AND '$todayEnd'")->fetchColumn());

$totalCustomers = $col($pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn());
$activeSubs     = $col($pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND ends_on >= CURDATE()")->fetchColumn());
$overdue        = $col($pdo->query("SELECT COUNT(*) FROM subscription_payments WHERE paid_at IS NULL AND due_on <= CURDATE()")->fetchColumn());

$recentSessions = $pdo->query(
    "SELECT s.id, s.pin, s.entered_at, s.paid_at, s.status, s.amount_cents,
            s.entry_channel, c.full_name AS customer_name
     FROM parking_sessions s
     LEFT JOIN customers c ON c.id = s.customer_id
     ORDER BY s.id DESC LIMIT 10"
)->fetchAll();

$recentEvents = $pdo->query(
    "SELECT id, event_type, pin, session_id, subscription_id, details, created_at
     FROM gate_events ORDER BY id DESC LIMIT 12"
)->fetchAll();

Layout::begin(I18n::t('nav_dashboard'), 'dashboard');
?>
<div class="grid k4">
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_active_sessions')) ?></div><div class="val"><?= (int) $active ?></div><div class="sub"><?= htmlspecialchars(I18n::t('stat_currently_inside')) ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_paid_waiting_exit')) ?></div><div class="val"><?= (int) $paidWaiting ?></div><div class="sub"><?= htmlspecialchars(I18n::t('stat_within_ttl')) ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_today_entries')) ?></div><div class="val"><?= (int) $todayEntries ?></div><div class="sub"><?= htmlspecialchars(I18n::t('stat_today_paid')) ?>: <?= (int) $todayPaid ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_today_revenue')) ?></div><div class="val"><?= htmlspecialchars($money((int) $todayRevenue + (int) $todaySubRev)) ?></div><div class="sub"><?= htmlspecialchars(I18n::t('stat_revenue_breakdown', ['kiosk' => $money((int) $todayRevenue), 'subs' => $money((int) $todaySubRev)])) ?></div></div>
</div>

<div class="grid k3" style="margin-top:18px">
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_customers')) ?></div><div class="val"><?= (int) $totalCustomers ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_active_subs')) ?></div><div class="val"><?= (int) $activeSubs ?></div></div>
  <div class="stat"><div class="lbl"><?= htmlspecialchars(I18n::t('stat_overdue_payments')) ?></div><div class="val" style="color:<?= $overdue > 0 ? 'var(--warn)' : 'inherit' ?>"><?= (int) $overdue ?></div></div>
</div>

<div class="grid k2" style="margin-top:18px">
  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('dash_recent_sessions')) ?></h2>
    <?php if (!$recentSessions): ?>
      <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_data')) ?></div>
    <?php else: ?>
      <table class="t">
        <thead><tr>
          <th>#</th><th>PIN</th><th><?= htmlspecialchars(I18n::t('col_entered')) ?></th>
          <th><?= htmlspecialchars(I18n::t('col_status')) ?></th>
          <th><?= htmlspecialchars(I18n::t('col_amount')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentSessions as $r): ?>
          <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td><code class="k"><?= htmlspecialchars($r['pin']) ?></code></td>
            <td><?= htmlspecialchars((new DateTime($r['entered_at']))->format('d/m H:i')) ?></td>
            <td><span class="badge <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="num"><?= $r['amount_cents'] !== null ? htmlspecialchars($money((int) $r['amount_cents'])) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:10px"><a href="sessions.php" class="btn ghost"><?= htmlspecialchars(I18n::t('see_all')) ?> &rarr;</a></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= htmlspecialchars(I18n::t('dash_recent_events')) ?></h2>
    <?php if (!$recentEvents): ?>
      <div class="empty"><?= htmlspecialchars(I18n::t('empty_no_data')) ?></div>
    <?php else: ?>
      <table class="t">
        <thead><tr>
          <th><?= htmlspecialchars(I18n::t('col_when')) ?></th>
          <th><?= htmlspecialchars(I18n::t('col_event')) ?></th>
          <th>PIN</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentEvents as $e): ?>
          <tr>
            <td><?= htmlspecialchars((new DateTime($e['created_at']))->format('d/m H:i:s')) ?></td>
            <td><?= htmlspecialchars($e['event_type']) ?></td>
            <td><?= $e['pin'] ? '<code class="k">' . htmlspecialchars($e['pin']) . '</code>' : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:10px"><a href="events.php" class="btn ghost"><?= htmlspecialchars(I18n::t('see_all')) ?> &rarr;</a></div>
    <?php endif; ?>
  </div>
</div>

<script>
// Auto-refresh dashboard every 15s so live numbers stay fresh.
setTimeout(() => location.reload(), 15000);
</script>
<?php Layout::end();
