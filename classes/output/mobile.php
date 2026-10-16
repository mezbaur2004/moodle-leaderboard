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

use mod_leaderboard\api;

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
        $leaderboard = api::get_record($cm);

        // Log the view, as view.php does.
        $event = \mod_leaderboard\event\course_module_viewed::create([
            'objectid' => $cm->instance,
            'context' => $cm->context,
        ]);
        $event->add_record_snapshot('course', $cm->get_course());
        $event->add_record_snapshot('leaderboard', $leaderboard);
        $event->trigger();

        $board = new board($cm);
        $data = $board->export_full((int) $leaderboard->topn, false);

        $data['breakdown'] = null;
        if (api::get_builder($cm)->is_ranked_user((int) $USER->id)) {
            $breakdown = $board->export_breakdown((int) $USER->id);
            foreach ($breakdown['rows'] as $index => $row) {
                // The name from format_string() is HTML; the app template gets plain text, escaped below.
                $breakdown['rows'][$index]['name'] = html_entity_decode(strip_tags($row['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $data['breakdown'] = $breakdown;
        }
        $data['updatedtext'] = $data['updated'] !== '' ? get_string('mobile_lastupdated', 'leaderboard', $data['updated']) : '';

        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_leaderboard/mobile_view', self::escape_for_app($data)),
            ]],
            // Refresh the course page summary whenever the student opens the full page. The second
            // argument (alreadyFetched) is left out on purpose: true would make the app reuse its cache.
            'javascript' => 'if (this.updateModuleCourseContent) { this.updateModuleCourseContent(' . (int) $cm->id . '); }',
        ];
    }

    /**
     * What the app's course page shows for the leaderboard: its name, the description when it
     * is set to show on the course page, and, when the summary is on, the top 3 and the
     * student's own place. Plain HTML only: the app shows it without Angular, so it cannot use
     * app components.
     *
     * The app keeps this for up to about 20 minutes, even when the course page is pulled to
     * refresh, so it says when the ranking was calculated.
     *
     * @param array $args Arguments from the app; cmid and courseid are used.
     * @return array
     */
    public static function mobile_course_page(array $args): array {
        global $OUTPUT;

        $cm = self::require_access($args);
        $customdata = $cm->customdata;
        $showsummary = !empty($customdata['showinline']);

        $data = [];
        if ($showsummary) {
            $data = (new board($cm))->export_summary(3);
            $data['updatedtext'] = $data['updated'] !== ''
                ? get_string('mobile_lastupdated', 'leaderboard', $data['updated']) : '';
        }
        $data['name'] = format_string($cm->name, true, ['context' => $cm->context]);
        $data['showsummary'] = $showsummary;
        // The app replaces the description with this content, so it has to include it.
        $data['intro'] = $cm->showdescription
            ? format_module_intro('leaderboard', api::get_record($cm), $cm->id) : '';

        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_leaderboard/mobile_course_page', $data),
            ]],
        ];
    }

    /**
     * Make every text value safe to put into an app template unescaped.
     *
     * The app compiles the template with Angular, which reads "{", "}" and "@" in plain text as
     * template syntax even inside ngNonBindable, so a student called "Rahim {RU}" would stop the
     * page from compiling. Each string is HTML-escaped and those characters become entities.
     *
     * @param array $data Template data.
     * @return array
     */
    public static function escape_for_app(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::escape_for_app($value);
            } else if (is_string($value)) {
                $data[$key] = str_replace(['{', '}', '@'], ['&#123;', '&#125;', '&#64;'], s($value));
            }
        }
        return $data;
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
