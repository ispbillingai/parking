<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\I18n;

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $bootstrap = $cfg['admin']['bootstrap'] ?? [];
    $u = Auth::attempt($pdo, $username, $password, $bootstrap);
    if ($u) {
        $next = (string) ($_GET['next'] ?? 'index.php');
        if (!preg_match('#^/[^/]#', $next)) $next = 'index.php';
        header('Location: ' . $next);
        exit;
    }
    $error = I18n::t('login_invalid');
}

if (Auth::user()) { header('Location: index.php'); exit; }

$lang = I18n::current();
?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(I18n::t('login_title')) ?></title>
<style>
:root{--bg:#0b1020;--bg-2:#0f1530;--card:rgba(255,255,255,.04);--border:rgba(255,255,255,.1);--text:#e7ecf5;--muted:#9aa4bf;--accent:#5eead4;--accent-2:#38bdf8;--err:#f87171}
*{box-sizing:border-box}html,body{margin:0;padding:0}
body{
  min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
  color:var(--text);
  background:radial-gradient(900px 600px at 10% -10%,#1b2555 0%,transparent 55%),radial-gradient(700px 500px at 110% 110%,#0b3b53 0%,transparent 50%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.box{
  width:100%;max-width:380px;padding:30px 28px;border-radius:20px;border:1px solid var(--border);
  background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));
  box-shadow:0 24px 60px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.06);
}
.brand{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font-size:12px;letter-spacing:.18em;text-transform:uppercase;margin-bottom:6px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
h1{font-size:24px;margin:6px 0 18px;letter-spacing:-.01em}
label{display:block;color:var(--muted);font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin:12px 0 6px}
input{
  width:100%;font-size:15px;padding:12px 14px;border-radius:10px;
  background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--border);outline:none;
}
input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(94,234,212,.15)}
button{
  margin-top:18px;width:100%;font-size:15px;padding:12px;border-radius:10px;border:none;font-weight:700;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#0b1020;
  box-shadow:0 10px 26px rgba(94,234,212,.22);
}
.err{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.35);color:#fecaca;padding:10px 12px;border-radius:10px;font-size:13px;margin-top:12px}
.tip{color:var(--muted);font-size:12px;margin-top:12px;text-align:center}
@media (max-width:480px){
  body{padding:14px}
  .box{padding:22px 18px;border-radius:16px}
  h1{font-size:20px}
  input{font-size:14px;padding:11px 12px}
  button{font-size:14px;padding:11px}
}
</style>
</head>
<body>
  <form class="box" method="post" autocomplete="off">
    <span class="brand"><span class="dot"></span><?= htmlspecialchars(I18n::t('admin_brand')) ?></span>
    <h1><?= htmlspecialchars(I18n::t('login_title')) ?></h1>
    <label><?= htmlspecialchars(I18n::t('login_username')) ?></label>
    <input name="username" autofocus required>
    <label><?= htmlspecialchars(I18n::t('login_password')) ?></label>
    <input type="password" name="password" required>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <button type="submit"><?= htmlspecialchars(I18n::t('login_submit')) ?></button>
    <div class="tip"><?= htmlspecialchars(I18n::t('login_default_hint')) ?></div>
  </form>
</body>
</html>
