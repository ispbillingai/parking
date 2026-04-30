<?php
declare(strict_types=1);

namespace Parking\Admin;

use Parking\I18n;

/**
 * Renders the chrome around every admin page: head, sidebar nav, lang switcher.
 * Pages call begin($title, $active) at the top and end() at the bottom.
 */
final class Layout
{
    /** @var string[] */
    private const NAV = [
        'dashboard'     => ['index.php',         'nav_dashboard',     '&#x1F4CA;'],
        'sessions'      => ['sessions.php',      'nav_sessions',      '&#x1F39F;'],
        'events'        => ['events.php',        'nav_events',        '&#x1F4DC;'],
        'customers'     => ['customers.php',     'nav_customers',     '&#x1F465;'],
        'subscriptions' => ['subscriptions.php', 'nav_subscriptions', '&#x1F511;'],
        'plans'         => ['plans.php',         'nav_plans',         '&#x1F3F7;'],
        'payments'      => ['payments.php',      'nav_payments',      '&#x1F4B6;'],
    ];

    public static function begin(string $title, string $active = 'dashboard'): void
    {
        $user = Auth::user();
        $lang = I18n::current();
        echo '<!doctype html><html lang="' . htmlspecialchars($lang) . '"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . htmlspecialchars($title) . ' · ' . htmlspecialchars(I18n::t('admin_brand')) . '</title>'
            . self::css()
            . '</head><body>';

        echo '<div class="side-overlay" id="sideOverlay" onclick="document.body.classList.remove(\'nav-open\')"></div>';

        echo '<aside class="side" id="side">'
            . '<a href="index.php" class="logo"><span class="dot"></span> ' . htmlspecialchars(I18n::t('admin_brand')) . '</a>'
            . '<nav>';
        foreach (self::NAV as $key => [$href, $tk, $ico]) {
            $cls = $key === $active ? 'item active' : 'item';
            echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">'
               . '<span class="ico">' . $ico . '</span>'
               . '<span>' . htmlspecialchars(I18n::t($tk)) . '</span>'
               . '</a>';
        }
        echo '</nav>'
            . '<div class="side-foot">'
            . '<div class="who">' . htmlspecialchars($user['username'] ?? '?') . '</div>'
            . '<a class="link" href="logout.php">' . htmlspecialchars(I18n::t('nav_logout')) . '</a>'
            . '</div>'
            . '</aside>';

        echo '<main class="main">'
            . '<header class="topbar">'
            . '<button type="button" class="menu-btn" aria-label="Menu" onclick="document.body.classList.toggle(\'nav-open\')">&#x2630;</button>'
            . '<h1>' . htmlspecialchars($title) . '</h1>'
            . '<div class="actions">'
            . '<a class="btn ghost" href="../index.php" target="_blank" rel="noopener">' . htmlspecialchars(I18n::t('nav_kiosk')) . ' &#x2197;</a>'
            . self::langSwitch()
            . '</div>'
            . '</header>'
            . '<div class="content">';

        if (!empty($_SESSION['_flash'])) {
            foreach ($_SESSION['_flash'] as $f) {
                $cls = $f['type'] === 'err' ? 'flash err' : 'flash ok';
                echo '<div class="' . $cls . '">' . htmlspecialchars($f['msg']) . '</div>';
            }
            $_SESSION['_flash'] = [];
        }
    }

    public static function end(): void
    {
        echo '</div></main></body></html>';
    }

    public static function flash(string $msg, string $type = 'ok'): void
    {
        Auth::start();
        $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
    }

    private static function langSwitch(): string
    {
        $current = I18n::current();
        $url = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $out = '<nav class="lang">';
        foreach (I18n::labels() as $label => $code) {
            $cls = $code === $current ? 'active' : '';
            $out .= '<a class="' . $cls . '" href="' . htmlspecialchars($url . '?lang=' . $code) . '">' . htmlspecialchars($label) . '</a>';
        }
        return $out . '</nav>';
    }

    private static function css(): string
    {
        return '<style>
:root{
  --bg:#0b1020;--bg-2:#0f1530;--side:#0a0f25;--card:rgba(255,255,255,.04);
  --border:rgba(255,255,255,.08);--text:#e7ecf5;--muted:#9aa4bf;
  --accent:#5eead4;--accent-2:#38bdf8;--ok:#34d399;--err:#f87171;--warn:#fbbf24;
}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);background:linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  display:flex;
}
.side{
  width:240px;flex:0 0 240px;min-height:100vh;background:var(--side);
  border-right:1px solid var(--border);padding:18px 14px;display:flex;flex-direction:column;gap:14px;
  position:sticky;top:0;
}
.logo{
  display:flex;align-items:center;gap:8px;color:var(--text);text-decoration:none;
  font-weight:800;letter-spacing:.04em;padding:6px 4px 12px;border-bottom:1px solid var(--border);
}
.logo .dot{width:10px;height:10px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
nav{display:flex;flex-direction:column;gap:2px}
.item{
  display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;
  color:var(--muted);text-decoration:none;font-size:14px;font-weight:600;
}
.item:hover{background:rgba(255,255,255,.04);color:var(--text)}
.item.active{
  background:linear-gradient(135deg,rgba(94,234,212,.15),rgba(56,189,248,.10));
  color:var(--text);border:1px solid rgba(94,234,212,.25);
}
.item .ico{font-size:16px;width:20px;text-align:center}
.side-foot{margin-top:auto;border-top:1px solid var(--border);padding-top:12px}
.side-foot .who{color:var(--muted);font-size:12px;margin-bottom:4px}
.side-foot .link{color:var(--accent);font-size:13px;text-decoration:none}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{
  display:flex;align-items:center;justify-content:space-between;gap:14px;
  padding:18px 28px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.02);
}
.topbar h1{margin:0;font-size:24px;font-weight:700;letter-spacing:-.01em}
.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lang{display:flex;gap:2px;padding:3px;border:1px solid var(--border);border-radius:999px}
.lang a{display:inline-block;padding:5px 10px;border-radius:999px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none}
.lang a.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020}
.content{padding:24px 28px;flex:1}
.btn{
  display:inline-block;padding:9px 16px;border-radius:10px;font-size:14px;font-weight:600;
  border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);
  cursor:pointer;text-decoration:none;transition:transform .12s ease,background .15s ease;
}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.1)}
.btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;border:none;box-shadow:0 8px 22px rgba(94,234,212,.22)}
.btn.danger{background:linear-gradient(135deg,#f87171,#ef4444);color:#fff;border:none}
.btn.ghost{background:transparent}
.flash{padding:10px 14px;border-radius:10px;margin:0 0 14px;font-size:14px;border:1px solid var(--border)}
.flash.ok{background:rgba(52,211,153,.10);border-color:rgba(52,211,153,.35);color:#a7f3d0}
.flash.err{background:rgba(248,113,113,.10);border-color:rgba(248,113,113,.35);color:#fecaca}
.card{
  background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.015));
  border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:18px;
}
.card h2{margin:0 0 12px;font-size:18px}
.grid{display:grid;gap:14px}
.grid.k4{grid-template-columns:repeat(4,minmax(0,1fr))}
.grid.k3{grid-template-columns:repeat(3,minmax(0,1fr))}
.grid.k2{grid-template-columns:repeat(2,minmax(0,1fr))}
@media (max-width:900px){.grid.k4,.grid.k3,.grid.k2{grid-template-columns:1fr 1fr}}
@media (max-width:600px){.grid.k4,.grid.k3,.grid.k2{grid-template-columns:1fr}}
.stat{padding:16px;border-radius:14px;border:1px solid var(--border);background:rgba(255,255,255,.03)}
.stat .lbl{color:var(--muted);font-size:12px;letter-spacing:.14em;text-transform:uppercase}
.stat .val{font-size:30px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums}
.stat .sub{color:var(--muted);font-size:12px;margin-top:4px}
table.t{width:100%;border-collapse:collapse;font-size:14px}
table.t th,table.t td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);vertical-align:middle}
table.t th{color:var(--muted);font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:700}
table.t tbody tr:hover{background:rgba(255,255,255,.03)}
table.t td.num{text-align:right;font-variant-numeric:tabular-nums}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em}
.badge.active{background:rgba(94,234,212,.15);color:#a7f3d0;border:1px solid rgba(94,234,212,.35)}
.badge.paid{background:rgba(56,189,248,.15);color:#bae6fd;border:1px solid rgba(56,189,248,.35)}
.badge.exited{background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border)}
.badge.expired,.badge.cancelled,.badge.suspended{background:rgba(248,113,113,.10);color:#fecaca;border:1px solid rgba(248,113,113,.35)}
.badge.due{background:rgba(251,191,36,.10);color:#fde68a;border:1px solid rgba(251,191,36,.35)}
form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:16px}
form.filters label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted)}
form.filters input,form.filters select{
  background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);
  border-radius:10px;padding:9px 12px;font-size:14px;min-width:160px;
}
form.crud{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
form.crud .full{grid-column:1/-1}
form.crud label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted)}
form.crud input,form.crud select,form.crud textarea{
  background:rgba(0,0,0,.30);color:var(--text);border:1px solid var(--border);
  border-radius:10px;padding:10px 12px;font-size:14px;
}
form.crud textarea{min-height:90px;font-family:inherit}
.pager{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:var(--muted);font-size:13px}
.pager a{color:var(--accent);text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:8px}
.pager a.disabled{opacity:.4;pointer-events:none}
.empty{padding:40px;text-align:center;color:var(--muted)}
.row-actions{display:flex;gap:6px;flex-wrap:wrap}
.row-actions form{display:inline}
.row-actions .btn{padding:5px 10px;font-size:12px}
code.k{background:rgba(0,0,0,.3);padding:2px 8px;border-radius:6px;font-family:Menlo,Consolas,monospace;font-size:13px;letter-spacing:.06em}
.muted{color:var(--muted)}

/* --- Mobile drawer + hamburger --- */
.menu-btn{
  display:none;align-items:center;justify-content:center;
  width:42px;height:42px;border-radius:10px;
  border:1px solid var(--border);background:rgba(255,255,255,.05);
  color:var(--text);cursor:pointer;font-size:20px;line-height:1;flex:0 0 auto;
}
.menu-btn:hover{background:rgba(255,255,255,.1)}
.side-overlay{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
  z-index:40;backdrop-filter:blur(2px);
}
body.nav-open .side-overlay{display:block}

@media (max-width:900px){
  body{display:block}
  .side{
    position:fixed;top:0;left:0;height:100vh;width:280px;flex:none;
    transform:translateX(-100%);transition:transform .25s ease;z-index:50;
    box-shadow:6px 0 30px rgba(0,0,0,.55);
  }
  body.nav-open .side{transform:translateX(0)}
  .main{width:100%}
  .menu-btn{display:inline-flex}
  .topbar{
    padding:12px 14px;gap:10px;flex-wrap:wrap;
  }
  .topbar h1{font-size:18px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .topbar .actions{order:3;width:100%;justify-content:flex-end}
  .content{padding:14px}
  .card{padding:14px;border-radius:14px;overflow-x:auto;-webkit-overflow-scrolling:touch}
  table.t{min-width:560px}
  table.t th,table.t td{padding:8px 10px;font-size:13px}
  form.crud{grid-template-columns:1fr;gap:12px}
  form.filters{gap:8px}
  form.filters input,form.filters select{min-width:140px;width:100%}
  form.filters label{flex:1 1 140px}
  form.filters .btn{flex:1 1 auto}
  .stat{padding:12px}
  .stat .val{font-size:22px}
  .row-actions{flex-direction:column;align-items:stretch;gap:6px}
  .row-actions form{display:block}
  .row-actions .btn{width:100%;text-align:center}
  .pager{flex-direction:column;gap:10px;align-items:stretch;text-align:center}
  .flash{font-size:13px}
  .btn{padding:10px 14px}
  /* Login is independent (not via Layout) but we still inherit some nav clicks */
}

@media (max-width:480px){
  .topbar h1{font-size:16px}
  .stat .val{font-size:20px}
  .stat .lbl{font-size:11px}
  .lang{padding:2px}
  .lang a{padding:4px 8px;font-size:11px}
  table.t{min-width:520px;font-size:12px}
  table.t th,table.t td{padding:7px 8px}
  .content{padding:12px 10px}
}
</style>';
    }
}
