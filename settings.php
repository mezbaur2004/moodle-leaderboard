<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Site settings for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_leaderboard\local\points_settings;

if ($ADMIN->fulltree) {
    // Points per mark.
    $settings->add(new admin_setting_heading(
        'leaderboard/pointsheading',
        get_string('settings_points', 'leaderboard'),
        get_string('settings_points_desc', 'leaderboard')
    ));

    foreach (points_settings::FIELDS as $field => $default) {
        $settings->add(new admin_setting_configtext(
            'leaderboard/' . $field,
            get_string($field, 'leaderboard'),
            get_string($field . '_desc', 'leaderboard'),
            $default,
            PARAM_FLOAT,
            6
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'leaderboard/lockpoints',
        get_string('lockpoints', 'leaderboard'),
        get_string('lockpoints_desc', 'leaderboard'),
        0
    ));

    // Display.
    $settings->add(new admin_setting_heading(
        'leaderboard/displayheading',
        get_string('settings_display', 'leaderboard'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'leaderboard/identitymode',
        get_string('identitymode', 'leaderboard'),
        get_string('identitymode_desc', 'leaderboard'),
        'fullname',
        [
            'fullname' => get_string('identitymode_fullname', 'leaderboard'),
            'anonymous' => get_string('identitymode_anonymous', 'leaderboard'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'leaderboard/mincohort',
        get_string('mincohort', 'leaderboard'),
        get_string('mincohort_desc', 'leaderboard'),
        5,
        PARAM_INT,
        4
    ));

    $settings->add(new admin_setting_configtext(
        'leaderboard/defaulttopn',
        get_string('defaulttopn', 'leaderboard'),
        get_string('defaulttopn_desc', 'leaderboard'),
        10,
        PARAM_INT,
        4
    ));

    $settings->add(new admin_setting_configtext(
        'leaderboard/cachettl',
        get_string('cachettl', 'leaderboard'),
        get_string('cachettl_desc', 'leaderboard'),
        300,
        PARAM_INT,
        6
    ));

    // Automatic provisioning.
    $settings->add(new admin_setting_heading(
        'leaderboard/provisionheading',
        get_string('settings_provision', 'leaderboard'),
        get_string('settings_provision_desc', 'leaderboard')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'leaderboard/autoprovision',
        get_string('autoprovision', 'leaderboard'),
        get_string('autoprovision_desc', 'leaderboard'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'leaderboard/autoprovisionname',
        get_string('autoprovisionname', 'leaderboard'),
        get_string('autoprovisionname_desc', 'leaderboard'),
        get_string('defaultname', 'leaderboard'),
        PARAM_TEXT,
        40
    ));

    $formats = [];
    foreach (core_component::get_plugin_list('format') as $format => $unused) {
        $formats[$format] = get_string('pluginname', 'format_' . $format);
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'leaderboard/skipformats',
        get_string('skipformats', 'leaderboard'),
        get_string('skipformats_desc', 'leaderboard'),
        ['singleactivity' => 1, 'social' => 1],
        $formats
    ));
}
