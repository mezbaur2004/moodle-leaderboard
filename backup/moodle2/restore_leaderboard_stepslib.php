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
 * Restore structure for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure for mod_leaderboard.
 */
class restore_leaderboard_activity_structure_step extends restore_activity_structure_step {
    #[\Override]
    protected function define_structure() {
        $paths = [new restore_path_element('leaderboard', '/activity/leaderboard')];
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the leaderboard record.
     *
     * @param array $data The backed up data.
     */
    protected function process_leaderboard($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $newitemid = $DB->insert_record('leaderboard', $data);
        $this->apply_activity_instance($newitemid);
    }

    #[\Override]
    protected function after_execute() {
        $this->add_related_files('mod_leaderboard', 'intro', null);
        // Tell the provisioner which leaderboards this restore brought, for the course_restored event.
        \mod_leaderboard\local\provisioner::note_restored((int) $this->get_courseid(), (int) $this->task->get_moduleid());
    }
}
