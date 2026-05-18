<?php
declare(strict_types=1);

namespace Parking\Gate;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttPublisher
{
    // Relay-PCB command payloads. The devices expect this exact (non-strict
    // JSON) format with single-quoted idx — do not "fix" the quoting.
    private const PAYLOAD_OPEN      = '{"type":"DELAY",\'idx\':\'1\',"status":"ON","time":"2","pass":"0"}';
    private const PAYLOAD_PARK_FREE = '{"type":"ON/OFF",\'idx\':\'2\',"status":"OFF","time":"0","pass":"0"}';
    private const PAYLOAD_PARK_FULL = '{"type":"ON/OFF",\'idx\':\'2\',"status":"ON","time":"0","pass":"0"}';
    private const PAYLOAD_LOCK      = '{"type":"ON/OFF",\'idx\':\'3\',"status":"ON","time":"0","pass":"0"}';
    private const PAYLOAD_UNLOCK    = '{"type":"ON/OFF",\'idx\':\'3\',"status":"OFF","time":"0","pass":"0"}';

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

    /**
     * Open one of the two physical barriers. $which is 'entrance' or 'exit';
     * each barrier's control topic is configured under mqtt.topics.
     */
    public function openBarrier(string $which): void
    {
        $key   = $which === 'exit' ? 'exit_control' : 'entrance_control';
        $topic = $this->cfg['topics'][$key] ?? '';
        if ($topic === '') {
            throw new \RuntimeException("mqtt.topics.$key is not configured");
        }
        $this->publish($topic, self::PAYLOAD_OPEN, 'barrier');
    }

    /**
     * Drive the external Free/Full traffic light. $full=false shows green
     * (parking vacant), $full=true shows red (parking occupied).
     */
    public function setParkingFull(bool $full): void
    {
        $topic = $this->signalsTopic();
        $this->publish($topic, $full ? self::PAYLOAD_PARK_FULL : self::PAYLOAD_PARK_FREE, 'signal');
    }

    /** Lock ($locked=true) or unlock the entrance barrier. */
    public function setEntranceLock(bool $locked): void
    {
        $topic = $this->signalsTopic();
        $this->publish($topic, $locked ? self::PAYLOAD_LOCK : self::PAYLOAD_UNLOCK, 'signal');
    }

    /**
     * Topic for the auxiliary signals (traffic light, entrance lock). These
     * sit on the entrance PCB, so they default to the entrance control topic
     * unless mqtt.topics.signals_control is set explicitly.
     */
    private function signalsTopic(): string
    {
        $topic = $this->cfg['topics']['signals_control']
            ?? $this->cfg['topics']['entrance_control']
            ?? '';
        if ($topic === '') {
            throw new \RuntimeException('mqtt.topics.signals_control is not configured');
        }
        return $topic;
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
