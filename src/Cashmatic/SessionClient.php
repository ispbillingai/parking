<?php
declare(strict_types=1);

namespace Parking\Cashmatic;

/**
 * Thin wrapper around Client that caches the bearer token in $_SESSION,
 * so the browser-side polling loop doesn't pay for a login on every call.
 *
 * Every Cashmatic call goes through withAuthRetry(): if the response
 * looks like an auth failure — a Cashmatic auth code (7/11/12) OR an
 * HTTP 401/403 from the REST server — the cached token is dropped, a
 * fresh login is performed, and the call is retried once. This recovers
 * from BOTH expired tokens and stale tokens left over from a previous
 * Cashmatic (e.g. after base_url was pointed at a different machine).
 *
 * Expired JWTs come back as HTTP 403 with an empty body, which the old
 * code-only check never caught — hence "StartPayment: Invalid JSON
 * response" with no recovery.
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
        $r = $this->withAuthRetry(fn() => $this->client->startPayment($amountCents, $reference, 'parking'));
        if (($r['code'] ?? -1) === 0) {
            $_SESSION['cashmatic_pin']    = $reference;
            $_SESSION['cashmatic_amount'] = $amountCents;
        }
        return $r;
    }

    public function activeTransaction(): array
    {
        $this->ensureAuth();
        return $this->withAuthRetry(fn() => $this->client->activeTransaction());
    }

    public function lastTransaction(): array
    {
        $this->ensureAuth();
        return $this->withAuthRetry(fn() => $this->client->lastTransaction());
    }

    public function cancelPayment(): array
    {
        $this->ensureAuth();
        return $this->withAuthRetry(fn() => $this->client->cancelPayment());
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
        } else {
            error_log('[cashmatic] initial login FAILED: '
                . json_encode($r, JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Run a Cashmatic call; if it comes back as an auth failure, drop the
     * cached token, log in fresh and retry the call once.
     */
    private function withAuthRetry(callable $call): array
    {
        $r = $call();
        if (!$this->isAuthFailure($r)) {
            return $r;
        }

        error_log('[cashmatic] auth failure detected — dropping token and re-logging in. response='
            . json_encode($r, JSON_UNESCAPED_SLASHES));

        $this->client->setToken(null);
        unset($_SESSION['cashmatic_token']);

        $login = $this->client->login();
        if (($login['code'] ?? -1) !== 0) {
            error_log('[cashmatic] re-login FAILED: ' . json_encode($login, JSON_UNESCAPED_SLASHES));
            return $login;
        }
        $_SESSION['cashmatic_token'] = $this->client->token();
        error_log('[cashmatic] re-login OK — retrying the call');

        return $call();
    }

    /**
     * True when a response means the bearer token is bad: a Cashmatic
     * auth error code, or an HTTP 401/403 from the REST server. An
     * expired JWT shows up as HTTP 403 with an empty body — which
     * Client::request() reports as code -1 with http => 403.
     */
    private function isAuthFailure(array $r): bool
    {
        $code = (int) ($r['code'] ?? 0);
        $http = (int) ($r['http'] ?? 0);
        return in_array($code, [7, 11, 12], true)
            || $http === 401
            || $http === 403;
    }
}
