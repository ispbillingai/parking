<?php
declare(strict_types=1);

namespace Parking;

final class I18n
{
    private const COOKIE = 'parking_lang';
    private const FALLBACK_LANG = 'en';

    /** @var array<string,string> */
    private static array $strings = [];
    private static string $lang = self::FALLBACK_LANG;

    /**
     * Pick the UI language. Priority:
     *   1. ?lang= query (user click on switcher) — also persisted in cookie
     *   2. parking_lang cookie (user's remembered choice)
     *   3. $adminDefault — admin-configured default from config.php
     *   4. FALLBACK_LANG hard-coded in this class
     */
    public static function init(?string $adminDefault = null): string
    {
        $available = self::available();
        $default = \in_array($adminDefault, $available, true) ? $adminDefault : self::FALLBACK_LANG;

        $requested = $_GET['lang'] ?? $_COOKIE[self::COOKIE] ?? $default;
        $lang = \in_array($requested, $available, true) ? $requested : $default;

        if (isset($_GET['lang']) && $_GET['lang'] === $lang && !headers_sent()) {
            setcookie(self::COOKIE, $lang, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
        }

        self::$lang = $lang;
        self::$strings = require __DIR__ . '/../lang/' . $lang . '.php';
        return $lang;
    }

    /** @return string[] */
    public static function available(): array
    {
        return ['en', 'it'];
    }

    public static function current(): string
    {
        return self::$lang;
    }

    /** @param array<string,string|int> $vars */
    public static function t(string $key, array $vars = []): string
    {
        $s = self::$strings[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $s;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$strings;
    }

    /** @return array<string,string> label => code */
    public static function labels(): array
    {
        return ['EN' => 'en', 'IT' => 'it'];
    }
}
