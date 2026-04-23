<?php
declare(strict_types=1);

// Long-running daemon: subscribes to the gate-scan topic, validates each
// scanned PIN against the DB, and publishes the relay-open command.
// Run under systemd or supervisord. Example:
//   php /var/www/parking/bin/mqtt-listener.php

require __DIR__ . '/../vendor/autoload.php';
$cfg = require __DIR__ . '/../config/config.php';

use Parking\Db;
use Parking\Gate\MqttPublisher;
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

$client->loop(true);
