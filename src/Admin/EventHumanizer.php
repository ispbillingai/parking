<?php
declare(strict_types=1);

namespace Parking\Admin;

use Parking\I18n;

/**
 * Render a gate_events row's JSON details column as a human-readable
 * sentence in the current UI language. Used by the Events log and by
 * the Recent barrier activity card. Unknown event/action combinations
 * fall back to the raw JSON so debug payloads are never hidden.
 */
final class EventHumanizer
{
    /**
     * @param array<string,mixed> $details
     * @return string|null Human-readable sentence, or null when the
     *                    caller should render the raw JSON instead.
     */
    public static function render(string $eventType, array $details): ?string
    {
        if (!$details) return null;
        $user = (string) ($details['user'] ?? $details['by'] ?? '');

        switch ($eventType) {
            case 'admin_login':
            case 'admin_logout':
                return I18n::t(
                    $eventType === 'admin_login' ? 'evd_admin_login' : 'evd_admin_logout',
                    ['user' => $user]
                );

            case 'admin_user_added':
                $role = (string) ($details['role'] ?? 'admin');
                $roleLbl = I18n::t('usr_role_' . $role);
                if ($roleLbl === 'usr_role_' . $role) $roleLbl = $role;
                return I18n::t('evd_admin_user_added', [
                    'email' => (string) ($details['email'] ?? ''),
                    'role'  => $roleLbl,
                    'by'    => $user,
                ]);

            case 'admin_user_deleted':
                return I18n::t('evd_admin_user_deleted', [
                    'email' => (string) ($details['email'] ?? ''),
                    'by'    => $user,
                ]);

            case 'admin_action':
                return self::adminAction($details, $user);

            case 'barrier':
                return self::barrier($details, $user);

            case 'entry':
                $bits = [];
                if (!empty($details['channel']))  $bits[] = (string) $details['channel'];
                if (!empty($details['phone']))    $bits[] = '+' . ltrim((string) $details['phone'], '+');
                if (!empty($details['delivery']))$bits[] = (string) $details['delivery'];
                return $bits
                    ? I18n::t('evd_entry_with', ['info' => implode(' · ', $bits)])
                    : I18n::t('evd_entry');

            case 'denied':
                $reason = (string) ($details['reason'] ?? '');
                $rKey   = 'evd_denied_' . $reason;
                $rLbl   = I18n::t($rKey);
                if ($rLbl === $rKey) $rLbl = $reason;
                $extra = '';
                if (!empty($details['key'])) $extra = ' · ' . (string) $details['key'];
                return I18n::t('evd_denied', ['reason' => $rLbl]) . $extra;

            case 'whatsapp_sent':
            case 'whatsapp_fail':
            case 'email_sent':
            case 'email_fail':
                $contact = (string) ($details['phone'] ?? $details['email'] ?? '');
                $base = $eventType === 'whatsapp_sent' ? 'evd_whatsapp_sent'
                      : ($eventType === 'whatsapp_fail' ? 'evd_whatsapp_fail'
                      : ($eventType === 'email_sent' ? 'evd_email_sent' : 'evd_email_fail'));
                return I18n::t($base, ['contact' => $contact]);

            case 'subscription_entry':
                return I18n::t('evd_subscription_entry', [
                    'customer' => (string) ($details['customer'] ?? ''),
                    'key'      => (string) ($details['key'] ?? ''),
                ]);

            case 'daily_ticket_sold':
                $method = (string) ($details['method'] ?? '');
                $mLbl   = I18n::t('method_' . $method);
                if ($mLbl === 'method_' . $method) $mLbl = $method;
                $amount = isset($details['amount_cents'])
                    ? number_format(((int) $details['amount_cents']) / 100, 2, '.', '')
                    : '';
                return I18n::t('evd_daily_ticket_sold', [
                    'amount'  => $amount,
                    'method'  => $mLbl,
                    'expires' => (string) ($details['expires_at'] ?? ''),
                ]);

            case 'payment_start':
            case 'payment_ok':
            case 'payment_fail':
                $method = (string) ($details['method'] ?? '');
                $mLbl   = I18n::t('method_' . $method);
                if ($mLbl === 'method_' . $method) $mLbl = $method;
                $amount = isset($details['amount_cents'])
                    ? number_format(((int) $details['amount_cents']) / 100, 2, '.', '')
                    : '';
                $err = (string) ($details['error'] ?? '');
                $key = 'evd_' . $eventType;
                return I18n::t($key, ['method' => $mLbl, 'amount' => $amount, 'error' => $err]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $d
     */
    private static function barrier(array $d, string $user): ?string
    {
        $act   = (string) ($d['action'] ?? '');
        $state = (string) ($d['state']  ?? '');

        if ($act === 'open') {
            $bcode = (string) ($d['barrier'] ?? '');
            $bname = I18n::t('bar_name_' . $bcode);
            if ($bname === 'bar_name_' . $bcode) $bname = $bcode;
            $direction = (string) ($d['direction'] ?? $bcode);
            $dirLbl = I18n::t('bar_dir_' . $direction);
            if ($dirLbl === 'bar_dir_' . $direction) $dirLbl = $direction;
            return I18n::t('bar_detail_open', [
                'barrier'   => $bname,
                'direction' => $dirLbl,
                'user'      => $user,
            ]);
        }
        if ($act === 'traffic_light') {
            return I18n::t($state === 'full' ? 'bar_detail_traffic_full' : 'bar_detail_traffic_free', ['user' => $user]);
        }
        if ($act === 'entrance_lock') {
            return I18n::t($state === 'locked' ? 'bar_detail_lock_locked' : 'bar_detail_lock_unlocked', ['user' => $user]);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $d
     */
    private static function adminAction(array $d, string $user): ?string
    {
        $act = (string) ($d['action'] ?? '');

        if ($act === 'thermal_print') {
            $ok = !empty($d['ok']);
            return $ok
                ? I18n::t('evd_thermal_print_ok')
                : I18n::t('evd_thermal_print_fail', ['error' => (string) ($d['error'] ?? '')]);
        }

        // CRUD-style admin_actions: <entity>.<verb> with an id.
        if (preg_match('/^([a-z_]+)\.(create|update|delete)$/', $act, $m)) {
            $entity = $m[1];
            $verb   = $m[2];
            $entKey = 'evd_entity_' . $entity;
            $entLbl = I18n::t($entKey);
            if ($entLbl === $entKey) $entLbl = $entity;
            return I18n::t('evd_admin_crud_' . $verb, [
                'entity' => $entLbl,
                'id'     => (string) ($d['id'] ?? ''),
                'user'   => $user,
            ]);
        }

        if ($act === 'car_park_create' || $act === 'car_park_delete') {
            return I18n::t('evd_admin_crud_' . ($act === 'car_park_create' ? 'create' : 'delete'), [
                'entity' => I18n::t('evd_entity_car_park'),
                'id'     => (string) ($d['code'] ?? $d['id'] ?? ''),
                'user'   => $user,
            ]);
        }
        if ($act === 'barrier_add' || $act === 'barrier_delete') {
            return I18n::t('evd_admin_crud_' . ($act === 'barrier_add' ? 'create' : 'delete'), [
                'entity' => I18n::t('evd_entity_barrier'),
                'id'     => (string) ($d['code'] ?? ''),
                'user'   => $user,
            ]);
        }

        return null;
    }
}
