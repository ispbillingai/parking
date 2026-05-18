<?php
declare(strict_types=1);

namespace Parking\Gate;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttPublisher
{
    // Relay-PCB command payloads. The devices expect this exact (non-strict
    // JSON) format with single-quoted idx — do not "fix" the quoting.
    //
    //   idx 1 — barrier motor on the entrance / exit cards: timed DELAY pulse.
    //   idx 1 — traffic-light relay on the parkingSemaforo card:
    //           ON = red (parking Full), OFF = green (parking Free).
    //   idx 3 — entrance-barrier lock relay on the parkingIN card.
    private const PAYLOAD_OPEN      = '{"type":"DELAY",\'idx\':\'1\',"status":"ON","time":"2","pass":"0"}';
    private const PAYLOAD_PARK_FREE = '{"type":"ON/OFF",\'idx\':\'1\',"status":"OFF","time":"0","pass":"0"}';
    private const PAYLOAD_PARK_FULL = '{"type":"ON/OFF",\'idx\':\'1\',"status":"ON","time":"0","pass":"0"}';
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
        $topic = $this->controlTopic($which === 'exit' ? 'exit_control' : 'entrance_control');
        $this->publish($topic, self::PAYLOAD_OPEN, 'barrier');
    }

    /**
     * Drive the external Free/Full traffic light, wired to relay idx 1 of
     * the standalone parkingSemaforo card. $full=false shows green (parking
     * vacant), $full=true shows red (parking occupied).
     */
    public function setParkingFull(bool $full): void
    {
        $topic = $this->controlTopic('semaforo_control');
        $this->publish($topic, $full ? self::PAYLOAD_PARK_FULL : self::PAYLOAD_PARK_FREE, 'signal');
    }

    /**
     * Lock ($locked=true) or unlock the entrance barrier. The lock relay
     * (idx 3) lives on the entrance card, so it shares its control topic.
     */
    public function setEntranceLock(bool $locked): void
    {
        $topic = $this->controlTopic('entrance_control');
        $this->publish($topic, $locked ? self::PAYLOAD_LOCK : self::PAYLOAD_UNLOCK, 'signal');
    }

    /**
     * Resolve a configured control topic, failing loudly when it is still
     * blank. The entrance / exit / semaforo control topics are normally
     * auto-discovered by bin/mqtt-listener.php and stored as settings, so
     * an empty value means the listener has not yet seen that card.
     */
    private function controlTopic(string $key): string
    {
        $topic = $this->cfg['topics'][$key] ?? '';
        if ($topic === '') {
            throw new \RuntimeException("mqtt.topics.$key is not configured yet — is bin/mqtt-listener.php running?");
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
