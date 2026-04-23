<?php
declare(strict_types=1);

namespace Parking\Gate;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttPublisher
{
    public function __construct(private array $cfg) {}

    public function publishRelayOpen(): void
    {
        $topic   = $this->cfg['topics']['relay_open'] ?? '';
        $payload = $this->cfg['relay_payload'] ?? '1';
        if ($topic === '') {
            return;
        }
        $this->publish($topic, $payload, 'relay');
    }

    public function publishPinAdd(string $pin): void
    {
        $topic = $this->cfg['topics']['pin_add'] ?? '';
        if ($topic === '') {
            return;
        }
        $this->publish($topic, $pin, 'pin');
    }

    private function publish(string $topic, string $payload, string $tag): void
    {
        $clientId = ($this->cfg['client_id'] ?? 'parking-php') . '-' . $tag . '-' . bin2hex(random_bytes(2));
        $client = new MqttClient($this->cfg['host'], (int) $this->cfg['port'], $clientId);

        $settings = (new ConnectionSettings())
            ->setUsername($this->cfg['username'] ?? null)
            ->setPassword($this->cfg['password'] ?? null)
            ->setUseTls(!empty($this->cfg['use_tls']))
            ->setConnectTimeout(5)
            ->setKeepAliveInterval(30);

        $client->connect($settings, true);
        $client->publish($topic, $payload, 1);
        $client->disconnect();
    }
}
