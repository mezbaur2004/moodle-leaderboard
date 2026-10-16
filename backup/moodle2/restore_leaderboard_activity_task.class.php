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
 * Restore task for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/leaderboard/backup/moodle2/restore_leaderboard_stepslib.php');

/**
 * Restore task for mod_leaderboard.
 */
class restore_leaderboard_activity_task extends restore_activity_task {
    #[\Override]
    protected function define_my_settings() {
    }

    #[\Override]
    protected function define_my_steps() {
        $this->add_step(new restore_leaderboard_activity_structure_step('leaderboard_structure', 'leaderboard.xml'));
    }

    /**
     * Content that may contain encoded links.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('leaderboard', ['intro'], 'leaderboard')];
    }

    /**
     * Rules to decode links.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('LEADERBOARDINDEX', '/mod/leaderboard/index.php?id=$1', 'course'),
            new restore_decode_rule('LEADERBOARDVIEWBYID', '/mod/leaderboard/view.php?id=$1', 'course_module'),
        ];
    }

    /**
     * Log rules for this activity.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('leaderboard', 'add', 'view.php?id={course_module}', '{leaderboard}'),
            new restore_log_rule('leaderboard', 'update', 'view.php?id={course_module}', '{leaderboard}'),
            new restore_log_rule('leaderboard', 'view', 'view.php?id={course_module}', '{leaderboard}'),
        ];
    }

    /**
     * Log rules for the course.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [new restore_log_rule('leaderboard', 'view all', 'index.php?id={course}', null)];
    }
}
