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
 * Upgrade steps for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade mod_leaderboard.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_leaderboard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026101700) {
        // Zoom points from local_zoomattendance: where they come from, and points per percent attended.
        $table = new xmldb_table('leaderboard');
        $field = new xmldb_field('zoomsource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'gradebook', 'zoompoints');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('zoomattendpoints', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '2', 'zoomsource');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026101700, 'leaderboard');
    }

    return true;
}
