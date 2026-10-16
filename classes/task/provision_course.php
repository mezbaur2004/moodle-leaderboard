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

namespace mod_leaderboard\task;

use mod_leaderboard\local\provisioner;

/**
 * Adds a leaderboard to a course in the background.
 *
 * Used when the course was created inside another transaction (for example by the
 * create courses web service), and as a retry when adding it straight away failed.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_course extends \core\task\adhoc_task {
    #[\Override]
    public function execute() {
        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        if ($courseid <= 0 || !provisioner::is_enabled()) {
            return;
        }
        $status = provisioner::ensure($courseid, 'task');
        mtrace('mod_leaderboard: course ' . $courseid . ': ' . $status);
        if ($status === 'locked') {
            throw new \moodle_exception('provisionlocked', 'leaderboard');
        }
    }
}
