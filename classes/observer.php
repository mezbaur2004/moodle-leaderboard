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

namespace mod_leaderboard;

use mod_leaderboard\local\provisioner;
use mod_leaderboard\local\ranking_cache;

/**
 * Event observers for mod_leaderboard. See db/events.php.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Something that can change a course's rankings happened: mark the course as changed.
     *
     * This only writes one small cache entry, so it is cheap even during bulk grading.
     *
     * @param \core\event\base $event The event.
     */
    public static function course_data_changed(\core\event\base $event): void {
        if (!empty($event->courseid)) {
            ranking_cache::course_changed((int) $event->courseid);
        }
    }

    /**
     * A course was restored.
     *
     * @param \core\event\course_restored $event The event.
     */
    public static function course_restored(\core\event\course_restored $event): void {
        ranking_cache::course_changed((int) $event->objectid);
        provisioner::course_restored($event);
    }

    /**
     * A course module was deleted.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        ranking_cache::course_changed((int) $event->courseid);
        provisioner::course_module_deleted($event);
    }

    /**
     * A course was deleted.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        provisioner::course_deleted($event);
    }
}
