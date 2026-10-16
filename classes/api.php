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

use mod_leaderboard\local\ranking_builder;

/**
 * Public API of mod_leaderboard, for this plugin's own pages and for other plugins
 * (for example a course dashboard block).
 *
 * Everything here works for the current user ($USER), because group selection depends
 * on the session.
 *
 * Apart from find_course_leaderboard(), these methods do not check capabilities and do not
 * apply the site's name rules (anonymous mode and the minimum number of students). Callers
 * must check mod/leaderboard:view themselves, and should display through
 * \mod_leaderboard\output\board, which applies those rules and checks who may see whose
 * breakdown (board::can_view_breakdown()).
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var array Leaderboard records already loaded in this request, by instance id. */
    private static array $records = [];

    /**
     * The leaderboard a course uses: the first one, in course order, that the current user can see.
     *
     * @param int $courseid The course.
     * @return \cm_info|null
     */
    public static function find_course_leaderboard(int $courseid): ?\cm_info {
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_instances_of('leaderboard') as $cm) {
            if ($cm->uservisible && has_capability('mod/leaderboard:view', $cm->context)) {
                return $cm;
            }
        }
        return null;
    }

    /**
     * The leaderboard record for a course module.
     *
     * @param \cm_info $cm The course module.
     * @return \stdClass
     */
    public static function get_record(\cm_info $cm): \stdClass {
        global $DB;
        if (!isset(self::$records[$cm->instance])) {
            self::$records[$cm->instance] = $DB->get_record('leaderboard', ['id' => $cm->instance], '*', MUST_EXIST);
        }
        return self::$records[$cm->instance];
    }

    /**
     * Forget loaded records, for example after the settings were changed.
     */
    public static function reset_caches(): void {
        self::$records = [];
    }

    /**
     * A ranking builder for a leaderboard.
     *
     * @param \cm_info $cm The course module.
     * @return ranking_builder
     */
    public static function get_builder(\cm_info $cm): ranking_builder {
        return new ranking_builder($cm, self::get_record($cm));
    }

    /**
     * Which group the current user's ranking is for.
     *
     * @param \cm_info $cm The course module.
     * @param bool $update Whether to apply a group chosen in the page URL (as groups_print_activity_menu does).
     * @return int|null The group id, 0 for all participants, or null when the user may only see
     *                  their own group but has none (separate groups mode).
     */
    public static function get_viewer_groupid(\cm_info $cm, bool $update = false): ?int {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == NOGROUPS) {
            return 0;
        }
        $groupid = (int) groups_get_activity_group($cm, $update);
        if (
            $groupid === 0 && $groupmode == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $cm->context)
        ) {
            return null;
        }
        return $groupid;
    }

    /**
     * The ranking the current user should see.
     *
     * @param \cm_info $cm The course module.
     * @param bool $update Whether to apply a group chosen in the page URL.
     * @return array ['groupid' => int|null, 'ranking' => ranking|null]. ranking is null when the user has no group.
     */
    public static function get_viewer_ranking(\cm_info $cm, bool $update = false): array {
        $groupid = self::get_viewer_groupid($cm, $update);
        if ($groupid === null) {
            return ['groupid' => null, 'ranking' => null];
        }
        return ['groupid' => $groupid, 'ranking' => self::get_builder($cm)->get_ranking($groupid)];
    }

    /**
     * A short summary for the current user: the top students and the user's own place.
     *
     * Meant for course-page summaries, the mobile app and dashboard blocks. Names are not
     * included, and the name rules are not applied; use \mod_leaderboard\output\board to
     * display it.
     *
     * @param \cm_info $cm The course module.
     * @param int $limit How many top students.
     * @return array ['groupid' => int|null, 'top' => array, 'me' => array|null, 'total' => int, 'generated' => int]
     */
    public static function get_summary(\cm_info $cm, int $limit = 3): array {
        global $USER;
        ['groupid' => $groupid, 'ranking' => $ranking] = self::get_viewer_ranking($cm);
        if (!$ranking) {
            return ['groupid' => null, 'top' => [], 'me' => null, 'total' => 0, 'generated' => time()];
        }
        return [
            'groupid' => $groupid,
            'top' => $ranking->get_top($limit),
            'me' => $ranking->get_entry((int) $USER->id),
            'total' => $ranking->count(),
            'generated' => $ranking->get_generated(),
        ];
    }

    /**
     * How a user's points were calculated. No access check: see board::can_view_breakdown().
     *
     * @param \cm_info $cm The course module.
     * @param int $userid The user.
     * @return array List of lines with source, itemid, cmid, modname, name, mark, grademax, late, permark, points.
     */
    public static function get_breakdown(\cm_info $cm, int $userid): array {
        return self::get_builder($cm)->get_breakdown($userid);
    }

    /**
     * Record that something affecting a course's rankings changed.
     *
     * @param int $courseid The course.
     */
    public static function course_changed(int $courseid): void {
        local\ranking_cache::course_changed($courseid);
    }
}
