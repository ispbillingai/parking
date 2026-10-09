<?php
declare(strict_types=1);

/**
 * Admin: Fiscal printers — the Epson RT (Registratore Telematico) reached on
 * its fpmate.cgi web service: it prints the fiscal receipt after every cash
 * or card payment. Values are the $cfg['fiscal_printer'] block, overlaid
 * from the settings table (a blank field falls back to config.php).
 */

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\SettingsForm;
use Parking\I18n;

Auth::require('login.php');

$groups = [
    [
        'title_key' => 'fp_group_rt',
        'desc_key'  => 'fp_group_rt_desc',
        'test'      => 'fiscal',
        'fields' => [
            ['key' => 'fiscal_printer.base_url',   'type' => 'text',     'label_key' => 'fp_base_url',
             'placeholder' => 'http://192.168.1.52', 'help_key' => 'fp_base_url_help'],
            ['key' => 'fiscal_printer.operator',   'type' => 'text',     'label_key' => 'fp_operator'],
            ['key' => 'fiscal_printer.timeout_ms', 'type' => 'number',   'label_key' => 'fp_timeout', 'help_key' => 'fp_timeout_help'],
            ['key' => 'fiscal_printer.verify_ssl', 'type' => 'checkbox', 'label_key' => 'gw_verify_ssl'],
        ],
    ],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    SettingsForm::save($pdo, $groups, 'fiscal_printers.save', 'fiscal-printers.php');
}

Layout::begin(I18n::t('nav_fiscal_printers'), 'fiscal_printers');
SettingsForm::render($pdo, $cfg, $groups);
Layout::end();
