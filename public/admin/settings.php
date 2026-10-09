<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\SettingsForm;
use Parking\I18n;

Auth::require('login.php');

// --- Catalog of editable settings, grouped into form sections.
// Each entry: dot-key, type (text|password|number|email|select|checkbox),
// optional fallback (from $cfg) used as the placeholder in the form,
// and (for select) options. Password fields are not echoed back; an
// empty submission means "keep existing".
$groups = [
    [
        'title_key' => 'settings_group_whatsapp',
        'desc_key'  => 'settings_group_whatsapp_desc',
        'fields' => [
            ['key' => 'textmebot.api_key',  'type' => 'password', 'label_key' => 'settings_wa_api_key'],
            ['key' => 'textmebot.endpoint', 'type' => 'text',     'label_key' => 'settings_wa_endpoint',
             'placeholder' => 'https://api.textmebot.com/send.php'],
        ],
    ],
    [
        'title_key' => 'settings_group_email',
        'desc_key'  => 'settings_group_email_desc',
        'fields' => [
            ['key' => 'mailer.transport',  'type' => 'select', 'label_key' => 'settings_mail_transport',
             'options' => ['mail' => 'PHP mail()', 'smtp' => 'SMTP']],
            ['key' => 'mailer.from_email', 'type' => 'email',  'label_key' => 'settings_mail_from_email'],
            ['key' => 'mailer.from_name',  'type' => 'text',   'label_key' => 'settings_mail_from_name'],
            ['key' => 'mailer.smtp_host',  'type' => 'text',   'label_key' => 'settings_mail_smtp_host',
             'placeholder' => 'smtp.example.com', 'help_key' => 'settings_mail_smtp_help'],
            ['key' => 'mailer.smtp_port',  'type' => 'number', 'label_key' => 'settings_mail_smtp_port'],
            ['key' => 'mailer.smtp_secure','type' => 'select', 'label_key' => 'settings_mail_smtp_secure',
             'options' => ['' => I18n::t('opt_none'), 'tls' => 'STARTTLS', 'ssl' => 'SSL/TLS']],
            ['key' => 'mailer.smtp_user',  'type' => 'text',     'label_key' => 'settings_mail_smtp_user'],
            ['key' => 'mailer.smtp_pass',  'type' => 'password', 'label_key' => 'settings_mail_smtp_pass'],
        ],
    ],
    [
        'title_key' => 'settings_group_tariff',
        'desc_key'  => 'settings_group_tariff_desc',
        'fields' => [
            ['key' => 'tariff.currency_symbol', 'type' => 'text',   'label_key' => 'settings_tariff_currency'],
            ['key' => 'tariff.hourly_cents',    'type' => 'number', 'label_key' => 'settings_tariff_hourly',
             'help_key' => 'settings_tariff_cents_help'],
            ['key' => 'tariff.minimum_cents',   'type' => 'number', 'label_key' => 'settings_tariff_minimum'],
            ['key' => 'tariff.daily_cap_cents', 'type' => 'number', 'label_key' => 'settings_tariff_daily_cap',
             'help_key' => 'settings_tariff_zero_help'],
            ['key' => 'tariff.grace_minutes',   'type' => 'number', 'label_key' => 'settings_tariff_grace'],
        ],
    ],
    [
        'title_key' => 'settings_group_app',
        'desc_key'  => 'settings_group_app_desc',
        'fields' => [
            ['key' => 'app.pin_ttl_after_pay_minutes',   'type' => 'number',   'label_key' => 'settings_app_ttl'],
            ['key' => 'app.cashier_auto_reset_seconds',  'type' => 'number',   'label_key' => 'settings_app_reset'],
            ['key' => 'app.default_lang',                'type' => 'select',   'label_key' => 'settings_app_lang',
             'options' => ['en' => 'English', 'it' => 'Italiano']],
            ['key' => 'app.subscription_block_overdue',  'type' => 'checkbox', 'label_key' => 'settings_app_block_overdue',
             'help_key' => 'settings_app_block_overdue_help'],
        ],
    ],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    SettingsForm::save($pdo, $groups, 'settings.save', 'settings.php');
}

Layout::begin(I18n::t('nav_settings'), 'settings');
SettingsForm::render($pdo, $cfg, $groups);
Layout::end();
