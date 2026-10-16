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
 * Moodle app support for mod_leaderboard.
 *
 * - method: the full leaderboard page in the app (pull down to refresh).
 * - coursepagemethod: the top 3 and the student's own place, shown on the app's course page.
 *
 * After changing this file, purge Moodle's caches; app users may need to log out and in again.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_leaderboard' => [
        'handlers' => [
            'leaderboard' => [
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_view',
                'coursepagemethod' => 'mobile_course_page',
                'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/leaderboard/pix/monologo.svg',
                    'class' => '',
                ],
                'ptrenabled' => true,
                'displaydescription' => false,
                'displayprefetch' => false,
                'displaysize' => false,
                'displayrefresh' => true,
            ],
        ],
        'lang' => [
            ['pluginname', 'leaderboard'],
            ['nogroup', 'leaderboard'],
            ['nostudents', 'leaderboard'],
            ['toofew', 'leaderboard'],
            ['late', 'leaderboard'],
            ['total', 'leaderboard'],
            ['nopointsyet', 'leaderboard'],
            ['mobile_pulltorefresh', 'leaderboard'],
        ],
    ],
];
