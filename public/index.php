<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\I18n;

$lang = I18n::init($cfg['app']['default_lang'] ?? null);
$currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('index_title')) ?></title>
<style>
  :root{
    --bg:#0b1020;
    --bg-2:#0f1530;
    --card:rgba(255,255,255,.04);
    --border:rgba(255,255,255,.08);
    --text:#e7ecf5;
    --muted:#9aa4bf;
    --accent:#5eead4;
    --accent-2:#38bdf8;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    min-height:100vh;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
    color:var(--text);
    background:
      radial-gradient(1200px 800px at 10% -10%, #1b2555 0%, transparent 55%),
      radial-gradient(900px 600px at 110% 110%, #0b3b53 0%, transparent 50%),
      linear-gradient(180deg,var(--bg) 0%, var(--bg-2) 100%);
    display:flex;align-items:center;justify-content:center;padding:24px;
  }
  .wrap{width:100%;max-width:560px;text-align:center;position:relative}
  .brand{
    display:inline-flex;align-items:center;gap:10px;
    padding:8px 14px;border:1px solid var(--border);border-radius:999px;
    background:var(--card);backdrop-filter:blur(8px);
    color:var(--muted);font-size:13px;letter-spacing:.14em;text-transform:uppercase;
  }
  .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
  h1{
    font-size:56px;margin:18px 0 8px;letter-spacing:-.02em;
    background:linear-gradient(90deg,#fff,#a5f3fc);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  p.sub{color:var(--muted);margin:0 0 28px;font-size:16px}
  .grid{display:grid;gap:14px}
  a.tile{
    display:flex;align-items:center;gap:16px;
    padding:22px 20px;border-radius:16px;
    border:1px solid var(--border);
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.02));
    color:var(--text);text-decoration:none;font-size:20px;font-weight:600;
    transition:transform .15s ease, border-color .15s ease, background .15s ease;
  }
  a.tile:hover{transform:translateY(-2px);border-color:rgba(94,234,212,.5);background:rgba(94,234,212,.08)}
  a.tile .ico{
    width:48px;height:48px;border-radius:12px;display:grid;place-items:center;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;font-size:22px;flex:0 0 48px;
  }
  a.tile .meta{display:flex;flex-direction:column;align-items:flex-start;gap:2px}
  a.tile .meta small{color:var(--muted);font-weight:400;font-size:13px}
  footer{margin-top:28px;color:var(--muted);font-size:12px}
  .lang-switch{
    position:fixed;top:18px;right:18px;display:flex;gap:4px;
    padding:4px;border:1px solid var(--border);border-radius:999px;
    background:var(--card);backdrop-filter:blur(8px);
  }
  .lang-switch a{
    display:inline-block;padding:6px 12px;border-radius:999px;
    color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.08em;
    text-decoration:none;transition:color .15s ease, background .15s ease;
  }
  .lang-switch a:hover{color:var(--text)}
  .lang-switch a.active{
    background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;
  }
  @media (max-width:760px){
    body{padding:18px 14px;align-items:flex-start;padding-top:64px}
    h1{font-size:38px;margin-top:14px}
    p.sub{font-size:14px;margin-bottom:20px}
    a.tile{padding:18px 16px;font-size:18px;gap:12px}
    a.tile .ico{width:42px;height:42px;flex:0 0 42px;font-size:20px}
    .lang-switch{top:12px;right:12px;padding:3px}
    .lang-switch a{padding:5px 10px;font-size:11px}
  }
  @media (max-width:420px){
    h1{font-size:32px}
    a.tile{padding:14px 14px;font-size:16px}
    a.tile .meta small{font-size:12px}
  }
</style>
</head>
<body>
  <nav class="lang-switch" aria-label="Language">
    <?php foreach (I18n::labels() as $label => $code): ?>
      <a href="<?= htmlspecialchars($currentUrl . '?lang=' . $code) ?>" class="<?= $code === $lang ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="wrap">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('brand_control')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('index_title')) ?></h1>
    <p class="sub"><?= htmlspecialchars(I18n::t('index_subtitle')) ?></p>
    <div class="grid">
      <a class="tile" href="entrance.php">
        <span class="ico">&#x1F39F;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_entrance_title')) ?><small><?= htmlspecialchars(I18n::t('tile_entrance_sub')) ?></small></span>
      </a>
      <a class="tile" href="totem.php">
        <span class="ico">&#x1F5A8;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_totem_title')) ?><small><?= htmlspecialchars(I18n::t('tile_totem_sub')) ?></small></span>
      </a>
      <a class="tile" href="subscriber-entry.php">
        <span class="ico">&#x1F511;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_subentry_title')) ?><small><?= htmlspecialchars(I18n::t('tile_subentry_sub')) ?></small></span>
      </a>
      <a class="tile" href="cashier-pay.php">
        <span class="ico">&#x1F4B6;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_cashier_title')) ?><small><?= htmlspecialchars(I18n::t('tile_cashier_sub')) ?></small></span>
      </a>
      <a class="tile" href="pay.php">
        <span class="ico">&#x1F4B3;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_pay_title')) ?><small><?= htmlspecialchars(I18n::t('tile_pay_sub')) ?></small></span>
      </a>
      <a class="tile" href="admin/index.php">
        <span class="ico">&#x1F4CA;</span>
        <span class="meta"><?= htmlspecialchars(I18n::t('tile_admin_title')) ?><small><?= htmlspecialchars(I18n::t('tile_admin_sub')) ?></small></span>
      </a>
    </div>
    <footer>&copy; <?= date('Y') ?> <?= htmlspecialchars(I18n::t('footer')) ?></footer>
  </div>
</body>
</html>
