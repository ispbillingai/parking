<?php
declare(strict_types=1);

namespace Parking\Cashmatic;

/**
 * Thin wrapper around Client that caches the bearer token in $_SESSION,
 * so the browser-side polling loop doesn't pay for a login on every call.
 *
 * Sessions here are PHP sessions tied to the kiosk browser, not parking
 * sessions. One token per visitor.
 */
class SessionClient
{
    private Client $client;

    public function __construct(array $cashmaticCfg)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->client = new Client($cashmaticCfg);
        if (!empty($_SESSION['cashmatic_token'])) {
            $this->client->setToken($_SESSION['cashmatic_token']);
        }
    }

    public function startPayment(int $amountCents, string $reference): array
    {
        $this->ensureAuth();
        $r = $this->client->startPayment($amountCents, $reference, 'parking');
        if (($r['code'] ?? -1) !== 0) {
            return $r;
        }
        $_SESSION['cashmatic_pin']    = $reference;
        $_SESSION['cashmatic_amount'] = $amountCents;
        return $r;
    }

    public function activeTransaction(): array
    {
        $this->ensureAuth();
        return $this->retryOnAuth(fn() => $this->client->activeTransaction());
    }

    public function lastTransaction(): array
    {
        $this->ensureAuth();
        return $this->retryOnAuth(fn() => $this->client->lastTransaction());
    }

    public function cancelPayment(): array
    {
        $this->ensureAuth();
        return $this->retryOnAuth(fn() => $this->client->cancelPayment());
    }

    public function pin(): ?string
    {
        return $_SESSION['cashmatic_pin'] ?? null;
    }

    public function amount(): ?int
    {
        return isset($_SESSION['cashmatic_amount']) ? (int) $_SESSION['cashmatic_amount'] : null;
    }

    public function clearTransaction(): void
    {
        unset($_SESSION['cashmatic_pin'], $_SESSION['cashmatic_amount']);
    }

    private function ensureAuth(): void
    {
        if ($this->client->token()) return;
        $r = $this->client->login();
        if (($r['code'] ?? -1) === 0) {
            $_SESSION['cashmatic_token'] = $this->client->token();
        }
    }

    /**
     * Cashmatic returns non-zero codes for expired tokens. One-shot retry
     * with a fresh login if the first call looks auth-related.
     */
    private function retryOnAuth(callable $call): array
    {
        $r = $call();
        $code = $r['code'] ?? -1;
        if ($code === 7 || $code === 11 || $code === 12) {
            $login = $this->client->login();
            if (($login['code'] ?? -1) === 0) {
                $_SESSION['cashmatic_token'] = $this->client->token();
                return $call();
            }
        }
        return $r;
    }
}
