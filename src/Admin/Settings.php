<?php
declare(strict_types=1);

namespace Parking\Admin;

use PDO;

/**
 * DB-backed config overlay. Admins edit values in the Settings page;
 * those rows live in the `settings` table keyed by dot-notation paths
 * (e.g. "textmebot.api_key", "mailer.smtp_host", "tariff.hourly_cents").
 *
 * At request start the sender paths call Settings::overlay($cfg, $pdo)
 * which mutates $cfg in-place so the rest of the code keeps reading
 * `$cfg['textmebot']['api_key']` without knowing where the value came
 * from.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Apply DB overrides on top of the file-based $cfg defaults.
     * Rows with empty values are ignored so a blank field deletes
     * the override and the config.php default takes effect again.
     */
    public static function overlay(array $cfg, PDO $pdo): array
    {
        if (self::$cache === null) {
            $rows = $pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = $rows ?: [];
        }
        foreach (self::$cache as $name => $value) {
            if ($value === '' || $value === null) continue;
            $cfg = self::assign($cfg, $name, self::cast($name, $value));
        }
        return $cfg;
    }

    /**
     * Read all stored overrides. Used by the admin settings UI to
     * prefill form fields with what the admin has chosen so far.
     *
     * @return array<string,string>
     */
    public static function all(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    }

    public static function set(PDO $pdo, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        } else {
            $pdo->prepare(
                'INSERT INTO settings (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            )->execute([$name, $value]);
        }
        self::$cache = null;
    }

    /**
     * Save many at once. Keys whose value is exactly the sentinel
     * "__keep__" are skipped — used for password-style fields whose
     * stored value should be preserved when the form input is empty.
     */
    public static function setMany(PDO $pdo, array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value === '__keep__') continue;
            self::set($pdo, $name, $value === null ? null : (string) $value);
        }
        self::$cache = null;
    }

    /**
     * Walks a dot-path and assigns the value into the right nested
     * spot, creating intermediate arrays as needed.
     */
    private static function assign(array $cfg, string $path, mixed $value): array
    {
        $parts = explode('.', $path);
        $ref = &$cfg;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (!isset($ref[$parts[$i]]) || !is_array($ref[$parts[$i]])) {
                $ref[$parts[$i]] = [];
            }
            $ref = &$ref[$parts[$i]];
        }
        $ref[$parts[count($parts) - 1]] = $value;
        unset($ref);
        return $cfg;
    }

    /**
     * Map known numeric / boolean settings to their proper type so
     * downstream code doesn't have to coerce strings.
     */
    private static function cast(string $name, string $value): mixed
    {
        $intKeys = [
            'tariff.hourly_cents', 'tariff.minimum_cents', 'tariff.daily_cap_cents',
            'tariff.grace_minutes', 'mailer.smtp_port', 'mailer.smtp_timeout',
            'app.pin_ttl_after_pay_minutes', 'app.cashier_auto_reset_seconds',
            'app.subscription_block_overdue',
        ];
        return in_array($name, $intKeys, true) ? (int) $value : $value;
    }
}
