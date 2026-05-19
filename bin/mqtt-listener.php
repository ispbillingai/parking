<?php
declare(strict_types=1);

// Long-running daemon: subscribes to the gate-scan topic, validates each
// scanned PIN against the DB, and publishes the relay-open command.
// Run under systemd or supervisord. Example:
//   php /var/www/parking/bin/mqtt-listener.php

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Admin\Settings;
use Parking\Db;
use Parking\Gate\MqttPublisher;
use Parking\Subscription\AccessControl;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

$mqttCfg = $cfg['mqtt'];
$pdo     = Db::pdo($cfg['db']);

$client = new MqttClient(
    $mqttCfg['host'],
    (int) $mqttCfg['port'],
    ($mqttCfg['client_id'] ?? 'parking-php') . '-listener'
);

$settings = (new ConnectionSettings())
    ->setUsername($mqttCfg['username'] ?? null)
    ->setPassword($mqttCfg['password'] ?? null)
    ->setUseTls(!empty($mqttCfg['use_tls']))
    ->setConnectTimeout(10)
    ->setKeepAliveInterval(30);

$client->connect($settings, true);

$scanTopic = $mqttCfg['topics']['scan'] ?? '';
if ($scanTopic === '') {
    fwrite(STDERR, "mqtt.topics.scan is not configured\n");
    exit(1);
}

echo "[mqtt-listener] subscribed to {$scanTopic}\n";

$client->subscribe($scanTopic, function (string $topic, string $message) use ($pdo, $cfg, $mqttCfg) {
    $pin = preg_replace('/\D/', '', $message);
    if (strlen($pin) !== 6) {
        echo "[skip] bad pin: {$message}\n";
        return;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT * FROM parking_sessions
             WHERE pin = ? AND status = "paid"
             FOR UPDATE'
        );
        $stmt->execute([$pin]);
        $s = $stmt->fetch();

        if (!$s) {
            $pdo->rollBack();
            Db::logEvent($pdo, null, $pin, 'denied', ['reason' => 'pin not paid or unknown']);
            echo "[denied] {$pin}\n";
            return;
        }

        $paidAt = new DateTimeImmutable($s['paid_at']);
        $ttl    = (int) ($cfg['app']['pin_ttl_after_pay_minutes'] ?? 15);
        if ((new DateTimeImmutable())->getTimestamp() - $paidAt->getTimestamp() > $ttl * 60) {
            $pdo->prepare('UPDATE parking_sessions SET status = "expired" WHERE id = ?')->execute([$s['id']]);
            $pdo->commit();
            Db::logEvent($pdo, (int) $s['id'], $pin, 'denied', ['reason' => 'ttl expired']);
            echo "[expired] {$pin}\n";
            return;
        }

        $pdo->prepare('UPDATE parking_sessions SET status = "exited", exited_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $s['id']]);
        $pdo->commit();

        Db::logEvent($pdo, (int) $s['id'], $pin, 'scan_at_exit');

        (new MqttPublisher($cfg['mqtt']))->publishRelayOpen();
        Db::logEvent($pdo, (int) $s['id'], $pin, 'gate_open');
        echo "[open] {$pin}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[error] {$e->getMessage()}\n");
    }
}, 1);

/**
 * A Wiegand tag was swiped at the $gate ('entrance'|'exit') reader. Open
 * the barrier when the tag matches a valid subscription; otherwise record
 * an unknown tag for admin onboarding, or log the denial reason.
 */
function handleTagScan(PDO $pdo, array $cfg, string $gate, string $rawTag, PDOStatement $upsertTag): void
{
    $check = AccessControl::check($pdo, $cfg, $rawTag);
    $tag   = $check['key'];
    if ($tag === '') {
        echo "[tag] {$gate}: empty tag, ignored\n";
        return;
    }

    // Unknown tag — store it so an admin can assign it to a customer.
    if ($check['reason'] === 'unknown_key') {
        $upsertTag->execute([$tag, $gate]);
        Db::logEvent($pdo, null, null, 'denied', [
            'reason' => 'unregistered_tag', 'tag' => $tag, 'gate' => $gate,
        ]);
        echo "[tag] {$gate}: unknown {$tag} -> recorded for onboarding\n";
        return;
    }

    $sub = $check['sub'];

    // Known tag but not allowed in (inactive / expired / overdue).
    if (!$check['ok']) {
        Db::logEvent($pdo, null, null, 'denied', [
            'reason' => $check['reason'], 'tag' => $tag, 'gate' => $gate,
            'overdue_cents' => $check['overdue'],
        ], (int) $sub['id']);
        echo "[tag] {$gate}: denied {$tag} ({$check['reason']})\n";
        return;
    }

    // Valid subscription — open the barrier. A tag that was previously
    // unknown is now assigned, so drop it from the onboarding list.
    $pdo->prepare('DELETE FROM unregistered_tags WHERE tag_code = ?')->execute([$tag]);
    $eventType = $gate === 'exit' ? 'subscription_exit' : 'subscription_entry';
    Db::logEvent($pdo, null, null, $eventType, [
        'tag' => $tag, 'gate' => $gate, 'customer' => $sub['full_name'],
    ], (int) $sub['id']);
    try {
        (new MqttPublisher($cfg['mqtt']))->openBarrier($gate);
        Db::logEvent($pdo, null, null, 'gate_open', ['tag' => $tag, 'gate' => $gate], (int) $sub['id']);
        echo "[tag] {$gate}: OPEN for {$sub['full_name']} ({$tag})\n";
    } catch (Throwable $e) {
        Db::logEvent($pdo, null, null, 'denied', [
            'reason' => 'mqtt_error', 'tag' => $tag, 'gate' => $gate, 'err' => $e->getMessage(),
        ], (int) $sub['id']);
        fwrite(STDERR, "[error] tag open {$gate}: {$e->getMessage()}\n");
    }
}

// --- Barrier cards: topic discovery + open/closed status -----------------
// Each relay card publishes under an MFR prefix (parkingOUT / parkingIN /
// parkingSemaforo). Two jobs per card:
//   1. Discover its control topic. The card's own device-id segment is not
//      known up front, so we watch any "<base>/out/<leaf>" topic it emits
//      and derive "<base>/in/control", which we store as a setting so the
//      admin Barriers page can publish OPEN / signal commands to it.
//   2. For the entrance & exit cards, mirror input1 (HIGH = open,
//      LOW = closed) into the barriers table for the live status display.
$cardPrefixes = [
    'exit'     => $mqttCfg['topics']['exit_prefix']     ?? '',
    'entrance' => $mqttCfg['topics']['entrance_prefix'] ?? '',
    'semaforo' => $mqttCfg['topics']['semaforo_prefix'] ?? '',
];

$updateBarrier = $pdo->prepare(
    'UPDATE barriers SET status = ?, status_at = ? WHERE code = ?'
);

// Upsert for Wiegand tags seen at a reader that match no subscription.
$upsertTag = $pdo->prepare(
    'INSERT INTO unregistered_tags (tag_code, last_gate, scan_count, last_seen_at)
     VALUES (?, ?, 1, NOW())
     ON DUPLICATE KEY UPDATE
         last_gate    = VALUES(last_gate),
         scan_count   = scan_count + 1,
         last_seen_at = NOW()'
);

// Remember the last control topic stored per card so we only hit the DB
// when the discovered value actually changes.
$resolvedControl = [];

foreach ($cardPrefixes as $code => $prefix) {
    if ($prefix === '') {
        continue;
    }
    $client->subscribe(
        rtrim($prefix, '/') . '/#',
        function (string $topic, string $message, bool $retained = false) use ($pdo, $cfg, $updateBarrier, $upsertTag, &$resolvedControl, $code) {
            // 0. Wiegand tag swiped at the entrance/exit reader. tag_wg1 is
            //    published retained, so the broker replays the last swipe on
            //    every (re)connect — skip retained or the gate opens on boot.
            if (($code === 'entrance' || $code === 'exit') && str_ends_with($topic, '/out/tag_wg1')) {
                if ($retained) {
                    echo "[skip] retained tag on {$code}: {$message}\n";
                    return;
                }
                handleTagScan($pdo, $cfg, $code, $message, $upsertTag);
                return;
            }

            // 1. Discover the control topic from any "<base>/out/<leaf>" topic.
            if (preg_match('#^(.+)/out/[^/]+$#', $topic, $m)) {
                $control = $m[1] . '/in/control';
                if (($resolvedControl[$code] ?? null) !== $control) {
                    $resolvedControl[$code] = $control;
                    Settings::set($pdo, "mqtt.topics.{$code}_control", $control);
                    echo "[discover] {$code} control -> {$control}\n";
                }
            }

            // 2. Mirror input1 HIGH/LOW into the barriers table.
            if (($code === 'entrance' || $code === 'exit') && str_ends_with($topic, '/out/input1')) {
                if (!preg_match('/"status"\s*:\s*"(HIGH|LOW)"/i', $message, $s)) {
                    echo "[skip] {$code} status: {$message}\n";
                    return;
                }
                $status = strtoupper($s[1]) === 'HIGH' ? 'open' : 'closed';
                try {
                    $updateBarrier->execute([$status, date('Y-m-d H:i:s'), $code]);
                    echo "[barrier] {$code} -> {$status}\n";
                } catch (Throwable $e) {
                    fwrite(STDERR, "[error] barrier {$code}: {$e->getMessage()}\n");
                }
            }
        },
        1
    );
    echo "[mqtt-listener] subscribed to {$prefix}/# ({$code} card)\n";
}

$client->loop(true);
