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

namespace mod_leaderboard\local\source;

use mod_leaderboard\local\points_settings;

/**
 * A place points come from.
 *
 * Version 1 has one source, the gradebook. A later version can add another one, for
 * example Zoom attendance from local_zoom_attendance, and exclude 'zoom' from the
 * gradebook source so nothing is counted twice.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface source {
    /**
     * Collect the point lines for a set of users in one course.
     *
     * @param int $courseid The course.
     * @param string $usersql SQL returning one column of user ids (the users to collect for).
     * @param array $userparams Named parameters for $usersql.
     * @param points_settings $settings Points per mark.
     * @param int $now The time to evaluate visibility at.
     * @return result
     */
    public function collect(int $courseid, string $usersql, array $userparams, points_settings $settings, int $now): result;
}
