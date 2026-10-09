<?php
declare(strict_types=1);

namespace Parking\Pos;

use Parking\I18n;

/**
 * Card-gateway rules, same as the Focacciami POS (F:\pub, includes/devices.php):
 *
 *  - The admin "Payment Gateways" page picks which CARD gateway the kiosks
 *    offer: 'pos' (Ingenico), 'dojo', 'both' or 'none' (setting
 *    payment.card_gateway). Unset = 'both', so a fresh install shows whatever
 *    is configured.
 *  - Ingenico has two connection modes, decided by the address:
 *      tcp://ip:port  -> 'p17'  Protocol 17 straight to the terminal (P17Client)
 *      http(s)://…    -> 'rts'  RTS WebDoReMi service that wraps it (Client)
 *  - A button only shows when its gateway is active AND configured:
 *      Ingenico = base_url set; Dojo = secret_key + terminal_id set.
 *
 * Cash (Cashmatic) is a separate payment type and is not affected.
 */
final class Gateway
{
    public const CHOICES = ['pos', 'dojo', 'both', 'none'];

    /** 'pos' | 'dojo' | 'both' | 'none' — defaults to 'both' when unset. */
    public static function active(array $cfg): string
    {
        $a = $cfg['payment']['card_gateway'] ?? null;
        return in_array($a, self::CHOICES, true) ? $a : 'both';
    }

    /** Ingenico button: gateway pos/both and a terminal address configured. */
    public static function posOn(array $cfg): bool
    {
        return in_array(self::active($cfg), ['pos', 'both'], true)
            && !empty($cfg['pos']['base_url']);
    }

    /** Dojo button: gateway dojo/both and key + terminal configured. */
    public static function dojoOn(array $cfg): bool
    {
        return in_array(self::active($cfg), ['dojo', 'both'], true)
            && !empty($cfg['dojo']['secret_key'])
            && !empty($cfg['dojo']['terminal_id']);
    }

    /** Ingenico client for the 'pos' config, by its connection mode. */
    public static function posClient(array $posCfg): Client|P17Client
    {
        return self::posMode($posCfg) === 'p17' ? new P17Client($posCfg) : new Client($posCfg);
    }

    /** 'p17' (direct TCP) or 'rts' (HTTP service) for a 'pos' config. */
    public static function posMode(array $posCfg): string
    {
        // The address decides; 'mode' only breaks the tie for a bare host:port.
        $url = (string) ($posCfg['base_url'] ?? '');
        if (stripos($url, 'tcp://') === 0) {
            return 'p17';
        }
        if (preg_match('#^https?://#i', $url)) {
            return 'rts';
        }
        return ($posCfg['mode'] ?? '') === 'p17' ? 'p17' : 'rts';
    }

    /**
     * Normalise the terminal address typed in admin for its mode:
     * p17 -> tcp://host:port, rts -> http(s)://…
     */
    public static function posNormUrl(string $v, string $mode): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if ($mode === 'p17') {
            return 'tcp://' . preg_replace('#^[a-z]+://#i', '', rtrim($v, '/'));
        }
        return preg_match('#^https?://#i', $v) ? $v : 'http://' . preg_replace('#^tcp://#i', '', $v);
    }

    /** https:// in front of a bare host, for the Dojo API URL. */
    public static function normUrl(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        return preg_match('#^https?://#i', $v) ? $v : 'https://' . $v;
    }

    /**
     * What a kiosk page needs in its browser config: which card buttons to
     * show and the strings for public/js/dojo-pay.js.
     */
    public static function kioskCfg(array $cfg): array
    {
        return [
            'card'         => self::posOn($cfg),
            'dojo'         => self::dojoOn($cfg),
            'dojo_poll_ms' => max(500, (int) ($cfg['dojo']['poll_interval_ms'] ?? 1500)),
            'dojo_i18n'    => [
                'pay_by_dojo'     => I18n::t('pay_by_dojo'),
                'cancel'          => I18n::t('pay_cancel'),
                'starting'        => I18n::t('dojo_starting'),
                'follow_terminal' => I18n::t('dojo_follow_terminal'),
                'working'         => I18n::t('dojo_working'),
                'failed'          => I18n::t('err_payment_failed'),
                'cancelling'      => I18n::t('dojo_cancelling'),
                'cancel_refused'  => I18n::t('dojo_cancel_refused'),
                'sig_title'       => I18n::t('dojo_sig_title'),
                'sig_question'    => I18n::t('dojo_sig_question'),
                'sig_accept'      => I18n::t('dojo_sig_accept'),
                'sig_reject'      => I18n::t('dojo_sig_reject'),
                'sig_countdown'   => I18n::t('dojo_sig_countdown'),
                'sig_auto'        => I18n::t('dojo_sig_auto'),
                'sig_rejected'    => I18n::t('dojo_sig_rejected'),
                // Terminal prompts from Dojo notificationEvents; unknown ones are shown as-is.
                'prompts' => [
                    'PresentCard'                   => I18n::t('dojo_p_present_card'),
                    'EnterPin'                      => I18n::t('dojo_p_enter_pin'),
                    'RemoveCard'                    => I18n::t('dojo_p_remove_card'),
                    'PleaseWait'                    => I18n::t('dojo_p_please_wait'),
                    'Authorizing'                   => I18n::t('dojo_p_please_wait'),
                    'SignatureVerificationRequired' => I18n::t('dojo_sig_question'),
                ],
            ],
        ];
    }

    /** ISO 4217 numeric for Dojo, from the tariff currency symbol (default EUR). */
    public static function currencyNum(array $cfg): int
    {
        return match ((string) ($cfg['tariff']['currency_symbol'] ?? '€')) {
            '£'     => 826,
            '$'     => 840,
            default => 978,
        };
    }
}
