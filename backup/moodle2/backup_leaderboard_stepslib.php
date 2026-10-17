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
 * Backup structure for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure for mod_leaderboard. Only settings: rankings come from the gradebook.
 */
class backup_leaderboard_activity_structure_step extends backup_activity_structure_step {
    #[\Override]
    protected function define_structure() {
        $leaderboard = new backup_nested_element('leaderboard', ['id'], [
            'name', 'intro', 'introformat',
            'assignpoints', 'assignlatepoints', 'quizpoints', 'zoompoints', 'zoomsource', 'zoomattendpoints', 'otherpoints',
            'topn', 'showinline', 'timecreated', 'timemodified',
        ]);

        $leaderboard->set_source_table('leaderboard', ['id' => backup::VAR_ACTIVITYID]);
        $leaderboard->annotate_files('mod_leaderboard', 'intro', null);

        return $this->prepare_activity_structure($leaderboard);
    }
}
