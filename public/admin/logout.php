<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;

Auth::logout($pdo);
header('Location: login.php');
exit;
