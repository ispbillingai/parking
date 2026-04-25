<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\I18n;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

/**
 * Single source of truth for every public/admin entry-point.
 * Labels and descriptions are i18n keys — see lang/en.php and lang/it.php.
 * Add a new entry here whenever a page is added; add the matching keys to
 * both language files.
 */
$sections = [
    [
        'title_key' => 'links_section_kiosk_title',
        'desc_key'  => 'links_section_kiosk_desc',
        'tone'      => 'accent',
        'links'     => [
            ['href' => 'index.php',             'label_key' => 'links_kiosk_home_label',       'why_key' => 'links_kiosk_home_why'],
            ['href' => 'entrance.php',          'label_key' => 'links_kiosk_entrance_label',   'why_key' => 'links_kiosk_entrance_why'],
            ['href' => 'entrance.php?format=json','label_key'=>'links_kiosk_entrance_json_label','why_key'=>'links_kiosk_entrance_json_why'],
            ['href' => 'totem.php',             'label_key' => 'links_kiosk_totem_label',      'why_key' => 'links_kiosk_totem_why'],
            ['href' => 'subscriber-entry.php',  'label_key' => 'links_kiosk_subentry_label',   'why_key' => 'links_kiosk_subentry_why'],
            ['href' => 'pay.php',               'label_key' => 'links_kiosk_pay_label',        'why_key' => 'links_kiosk_pay_why'],
            ['href' => 'cashier-pay.php',       'label_key' => 'links_kiosk_cashier_label',    'why_key' => 'links_kiosk_cashier_why'],
            ['href' => 'gate-scan.php',         'label_key' => 'links_kiosk_gatescan_label',   'why_key' => 'links_kiosk_gatescan_why'],
        ],
    ],
    [
        'title_key' => 'links_section_admin_title',
        'desc_key'  => 'links_section_admin_desc',
        'tone'      => 'accent2',
        'links'     => [
            ['href' => 'admin/login.php',         'label_key' => 'links_admin_login_label',     'why_key' => 'links_admin_login_why'],
            ['href' => 'admin/index.php',         'label_key' => 'links_admin_dashboard_label', 'why_key' => 'links_admin_dashboard_why'],
            ['href' => 'admin/sessions.php',      'label_key' => 'links_admin_sessions_label',  'why_key' => 'links_admin_sessions_why'],
            ['href' => 'admin/events.php',        'label_key' => 'links_admin_events_label',    'why_key' => 'links_admin_events_why'],
            ['href' => 'admin/customers.php',     'label_key' => 'links_admin_customers_label', 'why_key' => 'links_admin_customers_why'],
            ['href' => 'admin/plans.php',         'label_key' => 'links_admin_plans_label',     'why_key' => 'links_admin_plans_why'],
            ['href' => 'admin/subscriptions.php', 'label_key' => 'links_admin_subs_label',      'why_key' => 'links_admin_subs_why'],
            ['href' => 'admin/payments.php',      'label_key' => 'links_admin_payments_label',  'why_key' => 'links_admin_payments_why'],
            ['href' => 'admin/logout.php',        'label_key' => 'links_admin_logout_label',    'why_key' => 'links_admin_logout_why'],
        ],
    ],
];
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('links_title')) ?></title>
<style>
:root{
  --bg:#0b1020;--bg-2:#0f1530;--card:rgba(255,255,255,.04);--border:rgba(255,255,255,.10);
  --text:#e7ecf5;--muted:#9aa4bf;--accent:#5eead4;--accent-2:#38bdf8;--ok:#34d399;
}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);
  background:radial-gradient(1100px 700px at 10% -10%,#1b2555 0%,transparent 55%),radial-gradient(900px 600px at 110% 110%,#0b3b53 0%,transparent 50%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  padding:32px 20px 60px;
}
.wrap{max-width:1000px;margin:0 auto}
header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.brand{display:inline-flex;align-items:center;gap:10px;padding:8px 14px;border:1px solid var(--border);border-radius:999px;background:var(--card);color:var(--muted);font-size:12px;letter-spacing:.18em;text-transform:uppercase}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:38px;margin:8px 0 4px;letter-spacing:-.02em;background:linear-gradient(90deg,#fff,#a5f3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
p.intro{color:var(--muted);margin:0;font-size:15px;max-width:720px;line-height:1.55}
.lang-switch{display:flex;gap:4px;padding:4px;border:1px solid var(--border);border-radius:999px;background:var(--card)}
.lang-switch a{display:inline-block;padding:6px 12px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang-switch a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}

.section{
  margin-top:24px;border:1px solid var(--border);border-radius:18px;
  background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.015));
  padding:22px 24px;
}
.section h2{margin:0 0 6px;font-size:20px;display:flex;align-items:center;gap:10px}
.tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
.tag.accent{background:rgba(94,234,212,.15);color:#a7f3d0;border:1px solid rgba(94,234,212,.35)}
.tag.accent2{background:rgba(56,189,248,.15);color:#bae6fd;border:1px solid rgba(56,189,248,.35)}
.section .desc{color:var(--muted);font-size:13px;margin:0 0 14px;line-height:1.55}

.row{
  display:grid;grid-template-columns:minmax(220px,280px) 1fr;gap:18px;
  padding:14px 0;border-top:1px solid rgba(255,255,255,.05);align-items:start;
}
.row:first-of-type{border-top:none}
.row .lhs{display:flex;flex-direction:column;gap:4px}
.row a.link{
  display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid var(--border);
  background:rgba(255,255,255,.03);color:var(--text);text-decoration:none;
  font-family:Menlo,Consolas,"Courier New",monospace;font-size:13px;font-weight:600;
  transition:transform .12s ease,border-color .15s ease,background .15s ease;word-break:break-all;
}
.row a.link:hover{transform:translateY(-1px);border-color:rgba(94,234,212,.5);background:rgba(94,234,212,.08)}
.row .label{font-weight:700;font-size:14px}
.row .why{color:var(--muted);font-size:14px;line-height:1.55;margin:0}
@media (max-width:760px){.row{grid-template-columns:1fr;gap:8px}}

footer{color:var(--muted);font-size:12px;text-align:center;margin-top:30px}
.back{
  display:inline-block;margin-top:10px;padding:8px 14px;border-radius:10px;
  border:1px solid var(--border);background:rgba(255,255,255,.04);
  color:var(--text);text-decoration:none;font-size:13px;font-weight:600;
}
.back:hover{background:rgba(255,255,255,.08)}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('links_brand')) ?></span>
      <h1><?= htmlspecialchars(I18n::t('links_title')) ?></h1>
      <p class="intro"><?= htmlspecialchars(I18n::t('links_intro')) ?></p>
      <a class="back" href="index.php?lang=<?= htmlspecialchars($lang) ?>">&larr; <?= htmlspecialchars(I18n::t('links_back')) ?></a>
    </div>
    <nav class="lang-switch" aria-label="Language">
      <?php foreach (I18n::labels() as $label => $code): ?>
        <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <?php foreach ($sections as $sec): ?>
    <section class="section">
      <h2><?= htmlspecialchars(I18n::t($sec['title_key'])) ?> <span class="tag <?= htmlspecialchars($sec['tone']) ?>"><?= count($sec['links']) ?></span></h2>
      <p class="desc"><?= htmlspecialchars(I18n::t($sec['desc_key'])) ?></p>
      <?php foreach ($sec['links'] as $row): ?>
        <div class="row">
          <div class="lhs">
            <span class="label"><?= htmlspecialchars(I18n::t($row['label_key'])) ?></span>
            <a class="link" href="<?= htmlspecialchars($row['href']) ?>"><?= htmlspecialchars($row['href']) ?></a>
          </div>
          <p class="why"><?= htmlspecialchars(I18n::t($row['why_key'])) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <footer>&copy; <?= date('Y') ?> <?= htmlspecialchars(I18n::t('links_footer')) ?></footer>
</div>
</body>
</html>
