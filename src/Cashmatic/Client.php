<?php
declare(strict_types=1);

namespace Parking\Cashmatic;

/**
 * Server-side Cashmatic client (for tests/health-checks/server-hosted kiosks).
 *
 * In the standard cloud-PHP deployment the kiosk browser talks to the local
 * Cashmatic REST at 127.0.0.1:50301 directly via JavaScript — see pay.php.
 */
class Client
{
    private ?string $token = null;

    public function __construct(private array $cfg) {}

    public function login(): array
    {
        $res = $this->request('POST', '/api/user/Login', [
            'username' => $this->cfg['username'],
            'password' => $this->cfg['password'],
        ]);
        if (($res['code'] ?? -1) === 0) {
            $this->token = $res['data']['token'] ?? null;
        }
        return $res;
    }

    public function renewToken(): array
    {
        $res = $this->request('POST', '/api/user/RenewToken', null, true);
        if (($res['code'] ?? -1) === 0) {
            $this->token = $res['data']['token'] ?? $this->token;
        }
        return $res;
    }

    public function renewOrLogin(): array
    {
        if ($this->token) {
            $r = $this->renewToken();
            if (($r['code'] ?? -1) === 0) {
                return $r;
            }
        }
        return $this->login();
    }

    public function startPayment(int $amountCents, string $reference = '', string $reason = 'parking'): array
    {
        return $this->request('POST', '/api/transaction/StartPayment', [
            'amount'       => $amountCents,
            'reason'       => $reason,
            'reference'    => $reference,
            'queueAllowed' => false,
        ], true);
    }

    public function activeTransaction(): array
    {
        return $this->request('POST', '/api/device/ActiveTransaction', null, true);
    }

    public function lastTransaction(): array
    {
        return $this->request('POST', '/api/device/LastTransaction', null, true);
    }

    public function cancelPayment(): array
    {
        return $this->request('POST', '/api/transaction/CancelPayment', null, true);
    }

    public function commitPayment(): array
    {
        return $this->request('POST', '/api/transaction/CommitPayment', null, true);
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    private function request(string $method, string $path, ?array $body, bool $auth = false): array
    {
        $ch = curl_init($this->cfg['base_url'] . $path);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['verify_ssl']) ? 2 : 0,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['code' => -1, 'message' => 'cURL: ' . $err];
        }
        curl_close($ch);
        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? $decoded
            : ['code' => -1, 'message' => 'Invalid JSON response: ' . $raw];
    }
}
