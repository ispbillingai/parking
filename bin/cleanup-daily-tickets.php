<?php
/**
 * Sweep daily-ticket subscriptions whose expires_at has passed and that
 * were never used at the gate. Safe to run from cron every few minutes:
 *
 *   * /5 * * * *  php /path/to/parking/bin/cleanup-daily-tickets.php
 *
 * The same sweep runs inline whenever a new daily ticket is sold, so
 * this cron is only needed for car parks that sit idle for hours.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Db;
use Parking\Subscription\DailyTicket;

$pdo     = Db::pdo($cfg['db']);
$removed = DailyTicket::sweepUnusedExpired($pdo);

echo "removed $removed unused expired daily ticket(s)\n";
