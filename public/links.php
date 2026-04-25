<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\I18n;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

/**
 * Single source of truth for every entry-point of the parking system.
 * Update this list whenever a new page or endpoint is added.
 *
 * Each section is rendered as a card; each row links to the URL and
 * explains what it does + when to use it.
 */
$sections = [
    [
        'title'   => 'Kiosk · public stations (no login)',
        'tone'    => 'accent',
        'desc'    => 'These are the touch-screen pages on the kiosk hardware. They are public on purpose — there is no login. Lock them down at the network/Apache level if exposed beyond the LAN.',
        'links'   => [
            [
                'href'  => 'index.php',
                'label' => 'Kiosk home',
                'why'   => 'Landing screen with one tile per station. Open this on each kiosk monitor as the default URL.',
            ],
            [
                'href'  => 'entrance.php',
                'label' => 'Entrance · auto-print (Scenario 1)',
                'why'   => 'GET request the entrance gate fires when a car arrives. Generates a unique PIN, inserts a parking_sessions row, prints the ticket. Accepts ?phone=+39... to also send the PIN over WhatsApp.',
            ],
            [
                'href'  => 'entrance.php?format=json',
                'label' => 'Entrance · JSON API',
                'why'   => 'Same as above but returns JSON instead of the printable ticket. Useful for headless integrations / external displays.',
            ],
            [
                'href'  => 'totem.php',
                'label' => 'Totem · email / WhatsApp delivery (Scenario 2)',
                'why'   => 'Self-service entrance station. Customer picks Print / WhatsApp / Email, optionally enters name+contact. Auto-creates a customer record so repeat visitors are recognised.',
            ],
            [
                'href'  => 'subscriber-entry.php',
                'label' => 'Subscriber entrance · electronic key (Scenario 3)',
                'why'   => 'Regular customers type or scan their 8-char key. Validates active subscription, end date, and overdue payments — then publishes MQTT relay_open. Configure subscription_block_overdue in config/config.php to control overdue gating.',
            ],
            [
                'href'  => 'pay.php',
                'label' => 'Verify ticket',
                'why'   => 'Lightweight verification screen — type a PIN to see if it has been paid and how much is due. No payment hardware involved.',
            ],
            [
                'href'  => 'cashier-pay.php',
                'label' => 'Cashmatic cashier',
                'why'   => 'Full Cashmatic payment flow: scan PIN → calculate amount → start payment on the cash machine → poll → confirm → log + WhatsApp notification + MQTT pin_add.',
            ],
            [
                'href'  => 'gate-scan.php',
                'label' => 'Exit gate scan endpoint',
                'why'   => 'Called by the exit gate reader: gate-scan.php?pin=123456. Checks status=paid, TTL, marks the session exited, opens the relay. Returns JSON.',
            ],
        ],
    ],
    [
        'title'   => 'Admin dashboard · login required',
        'tone'    => 'accent2',
        'desc'    => 'Sign in once with the bootstrap credentials defined in config.php → admin.bootstrap (default admin / admin — change after first login). Every page survives reload because all filters are stored in the URL querystring.',
        'links'   => [
            [
                'href'  => 'admin/login.php',
                'label' => 'Sign in',
                'why'   => 'Login screen. The first time admin_users is empty, the bootstrap user from config.php is auto-provisioned.',
            ],
            [
                'href'  => 'admin/index.php',
                'label' => 'Dashboard',
                'why'   => 'Live tiles: cars inside, paid awaiting exit, today\'s entries and revenue (split kiosk vs. subscriptions), active subscribers, overdue payments. Auto-refreshes every 15 s.',
            ],
            [
                'href'  => 'admin/sessions.php',
                'label' => 'Sessions',
                'why'   => 'Every ticket ever issued. Filter by status (active / paid / exited / expired / cancelled), entry channel (gate / totem / api), date range, or free-text search across PIN / phone / email / customer name. Paginated 25/page.',
            ],
            [
                'href'  => 'admin/events.php',
                'label' => 'Events log',
                'why'   => 'Full audit trail: entries, scans, payments, denials, WhatsApp/email deliveries, subscription entries, admin actions. Filter by event type, PIN, or date. Paginated 50/page.',
            ],
            [
                'href'  => 'admin/customers.php',
                'label' => 'Customers',
                'why'   => 'CRUD for the customer database. Used by both totem visitors (auto-created) and subscription holders. Search by name / email / phone / plate.',
            ],
            [
                'href'  => 'admin/plans.php',
                'label' => 'Subscription plans',
                'why'   => 'CRUD for plan templates: code, name, period (weekly / monthly / annual), price. Inactive plans cannot be assigned to new subscriptions but keep existing ones intact.',
            ],
            [
                'href'  => 'admin/subscriptions.php',
                'label' => 'Subscriptions',
                'why'   => 'Assign a customer to a plan; the system auto-generates a unique 8-char electronic key and the full payment schedule. Re-build schedule button regenerates unpaid installments after a date change.',
            ],
            [
                'href'  => 'admin/payments.php',
                'label' => 'Payment schedule',
                'why'   => 'All subscription installments. Mark paid (cash / card / bank / other) or unmark. Filter to "only unpaid" to chase outstanding balances.',
            ],
            [
                'href'  => 'admin/logout.php',
                'label' => 'Sign out',
                'why'   => 'Destroys the admin session and logs the admin_logout event.',
            ],
        ],
    ],
    [
        'title'   => 'Browser → server APIs (called by kiosk JS)',
        'tone'    => 'muted',
        'desc'    => 'These return JSON and are consumed by the inline <script> blocks in cashier-pay.php and pay.php. You do not normally open them by hand, but they are useful for curl debugging.',
        'links'   => [
            [
                'href'  => 'api/scan-pin.php?pin=000000',
                'label' => 'GET api/scan-pin.php?pin=NNNNNN',
                'why'   => 'Looks up an active session by PIN, returns entered_at + duration + amount due. Used by both the cashier UI and the verify-only screen.',
            ],
            [
                'href'  => 'api/cashmatic-start.php',
                'label' => 'POST api/cashmatic-start.php',
                'why'   => 'Body: { pin, amount_cents }. Tells the Cashmatic kiosk to start collecting cash. Server proxies the call so the browser does not need to reach the kiosk LAN.',
            ],
            [
                'href'  => 'api/cashmatic-poll.php',
                'label' => 'POST api/cashmatic-poll.php',
                'why'   => 'Polled by the cashier UI every 300 ms while a payment is in progress. Returns inserted / dispensed / notDispensed / operation state.',
            ],
            [
                'href'  => 'api/cashmatic-finish.php',
                'label' => 'POST api/cashmatic-finish.php',
                'why'   => 'Body: { pin, amount_cents }. Closes the transaction, marks the session paid, fires WhatsApp notification + MQTT pin_add. Idempotent — guarded against the cashier UI calling it twice.',
            ],
            [
                'href'  => 'api/cashmatic-cancel.php',
                'label' => 'POST api/cashmatic-cancel.php',
                'why'   => 'Aborts an in-progress cash collection (returns money already inserted). Triggered by the Cancel button on the cashier UI.',
            ],
        ],
    ],
    [
        'title'   => 'Maintenance · CLI only',
        'tone'    => 'muted',
        'desc'    => 'Not URLs. Run from the project root with the XAMPP PHP CLI: F:\\xampp\\php\\php.exe bin\\... — these are listed here so they are not forgotten.',
        'links'   => [
            [
                'href'  => null,
                'label' => 'php bin/migrate.php',
                'why'   => 'Idempotent schema migrator. Creates the new tables (customers / plans / subscriptions / subscription_payments / admin_users) and patches the existing ones (parking_sessions.customer_id, gate_events.subscription_id, widened ENUMs). Safe to re-run any time.',
            ],
            [
                'href'  => null,
                'label' => 'db/schema.sql / db/schema_upgrade.sql',
                'why'   => 'Reference SQL — schema.sql is the canonical CREATE script for a fresh database; schema_upgrade.sql is the same logic as migrate.php for DBAs who prefer raw SQL.',
            ],
            [
                'href'  => null,
                'label' => 'config/config.php',
                'why'   => 'Runtime config (gitignored). Sections: db / cashmatic / mqtt / tariff / textmebot / mailer / admin / app. config/config.sample.php is the documented template.',
            ],
        ],
    ],
];
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Parking · system map</title>
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
header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
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
.tag.muted{background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border)}
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
.row .cli{
  display:inline-block;padding:8px 12px;border-radius:10px;border:1px dashed var(--border);
  font-family:Menlo,Consolas,"Courier New",monospace;font-size:13px;color:var(--muted);
  background:rgba(0,0,0,.2);
}
.row .why{color:var(--muted);font-size:14px;line-height:1.55;margin:0}
@media (max-width:760px){.row{grid-template-columns:1fr;gap:8px}}

footer{color:var(--muted);font-size:12px;text-align:center;margin-top:30px}
.back{
  display:inline-block;margin-top:6px;padding:8px 14px;border-radius:10px;
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
      <span class="brand"><span class="dot"></span>Parking · system map</span>
      <h1>Every link, every endpoint</h1>
      <p class="intro">Single reference page so we never lose track of what runs where. Sections below cover the public kiosk, the admin dashboard, the JSON APIs the kiosk JS calls, and the CLI maintenance scripts. Click any link to open it; the description tells you what it does and when to use it.</p>
      <a class="back" href="index.php">&larr; Back to kiosk home</a>
    </div>
    <nav class="lang-switch" aria-label="Language">
      <?php foreach (I18n::labels() as $label => $code): ?>
        <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <?php foreach ($sections as $sec): ?>
    <section class="section">
      <h2><?= htmlspecialchars($sec['title']) ?> <span class="tag <?= htmlspecialchars($sec['tone']) ?>"><?= count($sec['links']) ?></span></h2>
      <p class="desc"><?= htmlspecialchars($sec['desc']) ?></p>
      <?php foreach ($sec['links'] as $row): ?>
        <div class="row">
          <div class="lhs">
            <span class="label"><?= htmlspecialchars($row['label']) ?></span>
            <?php if ($row['href'] !== null): ?>
              <a class="link" href="<?= htmlspecialchars($row['href']) ?>"><?= htmlspecialchars($row['href']) ?></a>
            <?php else: ?>
              <span class="cli">(CLI / file — not a URL)</span>
            <?php endif; ?>
          </div>
          <p class="why"><?= htmlspecialchars($row['why']) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <footer>&copy; <?= date('Y') ?> Parking Gate · keep this page bookmarked.</footer>
</div>
</body>
</html>
