<?php
declare(strict_types=1);

namespace Parking\Admin;

use PDO;
use Parking\Db;

/**
 * Tiny session-cookie auth for the admin dashboard. No external libraries.
 * Bootstraps a default admin from config (admin.bootstrap_user / bootstrap_pass)
 * the first time the admin_users table is empty, so a fresh deploy can log in.
 */
final class Auth
{
    private const SESSION_KEY = 'parking_admin_user';

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('PARKINGADM');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function require(string $loginUrl = 'login.php'): array
    {
        $u = self::user();
        if (!$u) {
            $next = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: ' . $loginUrl . '?next=' . urlencode($next));
            exit;
        }
        return $u;
    }

    public static function attempt(PDO $pdo, string $username, string $password, array $bootstrap = []): ?array
    {
        self::bootstrapIfEmpty($pdo, $bootstrap);

        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        $pdo->prepare('UPDATE admin_users SET last_login_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $row['id']]);

        $user = [
            'id'        => (int) $row['id'],
            'username'  => (string) $row['username'],
            'full_name' => (string) ($row['full_name'] ?? ''),
            'role'      => (string) $row['role'],
        ];
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $user;

        Db::logEvent($pdo, null, null, 'admin_login', ['user' => $user['username']]);
        return $user;
    }

    public static function logout(PDO $pdo): void
    {
        self::start();
        $u = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
        if ($u) {
            Db::logEvent($pdo, null, null, 'admin_logout', ['user' => $u['username']]);
        }
    }

    private static function bootstrapIfEmpty(PDO $pdo, array $bootstrap): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($count > 0) return;

        $username = (string) ($bootstrap['username'] ?? 'admin');
        $password = (string) ($bootstrap['password'] ?? 'admin');
        $full     = (string) ($bootstrap['full_name'] ?? 'Administrator');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, full_name, role)
             VALUES (?, ?, ?, "admin")'
        )->execute([$username, $hash, $full]);
    }

    /** CSRF helpers — simple per-session token, embedded in admin forms. */
    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfCheck(?string $token): bool
    {
        self::start();
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method not allowed';
            exit;
        }
        if (!self::csrfCheck($_POST['_csrf'] ?? null)) {
            http_response_code(403);
            echo 'Bad CSRF token';
            exit;
        }
    }
}
