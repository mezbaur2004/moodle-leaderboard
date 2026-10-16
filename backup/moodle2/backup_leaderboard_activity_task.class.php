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
 * Backup task for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/leaderboard/backup/moodle2/backup_leaderboard_stepslib.php');

/**
 * Backup task for mod_leaderboard.
 */
class backup_leaderboard_activity_task extends backup_activity_task {
    #[\Override]
    protected function define_my_settings() {
    }

    #[\Override]
    protected function define_my_steps() {
        $this->add_step(new backup_leaderboard_activity_structure_step('leaderboard_structure', 'leaderboard.xml'));
    }

    /**
     * Encode links to this activity so they can be restored.
     *
     * @param string $content Some HTML.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot . '/mod/leaderboard', '#');
        $content = preg_replace('#(' . $base . '/index\.php\?id=)([0-9]+)#', '$@LEADERBOARDINDEX*$2@$', $content);
        $content = preg_replace('#(' . $base . '/view\.php\?id=)([0-9]+)#', '$@LEADERBOARDVIEWBYID*$2@$', $content);
        return $content;
    }
}
