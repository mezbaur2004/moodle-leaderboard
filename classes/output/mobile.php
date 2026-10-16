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

namespace mod_leaderboard\output;

/**
 * Content for the Moodle app (see db/mobile.php).
 *
 * The app calls these methods through tool_mobile_get_content as the logged-in user, which
 * does no access checks itself, so every method checks access first. The user is always
 * the current $USER, never a user id sent by the app.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * The full leaderboard page in the app.
     *
     * @param array $args Arguments from the app; cmid and courseid are used.
     * @return array
     */
    public static function mobile_view(array $args): array {
        global $OUTPUT, $USER;

        $cm = self::require_access($args);
        $board = new board($cm);
        $data = $board->export_full((int) \mod_leaderboard\api::get_record($cm)->topn);

        $data['breakdown'] = null;
        if (\mod_leaderboard\api::get_builder($cm)->is_ranked_user((int) $USER->id)) {
            $data['breakdown'] = $board->export_breakdown((int) $USER->id);
        }
        $data['updatedtext'] = get_string('mobile_lastupdated', 'leaderboard', $data['updated']);

        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_leaderboard/mobile_view', $data),
            ]],
            // Refresh the course page summary whenever the student opens the full page.
            'javascript' => 'if (this.updateModuleCourseContent) { this.updateModuleCourseContent(' . (int) $cm->id . ', true); }',
        ];
    }

    /**
     * The short summary shown on the app's course page. Plain HTML only: the app shows it
     * without Angular, so it cannot use app components.
     *
     * @param array $args Arguments from the app; cmid and courseid are used.
     * @return array
     */
    public static function mobile_course_page(array $args): array {
        global $OUTPUT;

        $cm = self::require_access($args);
        $customdata = $cm->customdata;
        $data = ['showsummary' => !empty($customdata['showinline'])];
        $data += (new board($cm))->export_summary(3);

        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_leaderboard/mobile_course_page', $data),
            ]],
        ];
    }

    /**
     * Check access the same way view.php does.
     *
     * @param array $args Arguments from the app.
     * @return \cm_info
     */
    private static function require_access(array $args): \cm_info {
        $cmid = (int) ($args['cmid'] ?? 0);
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'leaderboard');
        require_login($course, false, $cm, true, true);
        require_capability('mod/leaderboard:view', $cm->context);
        return $cm;
    }
}
