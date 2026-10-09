<?php
declare(strict_types=1);

/**
 * Admin: Printers — the network thermal printer (ESC/POS over raw TCP) that
 * prints the entrance ticket (entrance.php, print-ticket.php). Values are
 * the $cfg['printer'] block, overlaid from the settings table.
 */

require __DIR__ . '/_init.php';

use Parking\Admin\Auth;
use Parking\Admin\Layout;
use Parking\Admin\SettingsForm;
use Parking\I18n;

Auth::require('login.php');

$groups = [
    [
        'title_key' => 'prn_group_ticket',
        'desc_key'  => 'prn_group_ticket_desc',
        'test'      => 'printer',
        'fields' => [
            ['key' => 'printer.host',     'type' => 'text',   'label_key' => 'prn_host', 'help_key' => 'prn_host_help'],
            ['key' => 'printer.port',     'type' => 'number', 'label_key' => 'prn_port'],
            ['key' => 'printer.timeout',  'type' => 'number', 'label_key' => 'prn_timeout'],
            ['key' => 'printer.width',    'type' => 'select', 'label_key' => 'prn_width',
             'options' => ['32' => I18n::t('prn_width_58'), '48' => I18n::t('prn_width_80')]],
            ['key' => 'printer.codepage', 'type' => 'select', 'label_key' => 'prn_codepage',
             'options' => ['2' => 'CP850 (è à ò)', '0' => 'CP437']],
        ],
    ],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    SettingsForm::save($pdo, $groups, 'printers.save', 'printers.php');
}

Layout::begin(I18n::t('nav_printers'), 'printers');
SettingsForm::render($pdo, $cfg, $groups);
Layout::end();
