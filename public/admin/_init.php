<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../config/config.php';

use Parking\Admin\Auth;
use Parking\Db;
use Parking\I18n;

Auth::start();
I18n::init($cfg['app']['default_lang'] ?? null);
$pdo = Db::pdo($cfg['db']);
