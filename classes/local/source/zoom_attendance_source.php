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

use local_zoomattendance\local\attendance;
use mod_leaderboard\local\points_settings;

/**
 * Points from Zoom attendance, as measured by local_zoomattendance.
 *
 * Each finished class of a Zoom activity counts once for each student on its class list: the
 * percentage of the class the student attended (as the attendance plugin works it out) times
 * the points per percent.
 * Only classes the attendance plugin has evaluated count, so upcoming, cancelled, excluded
 * and reset classes, and classes without a participant report, earn nothing, and nor do
 * Zoom activities with attendance switched off or being deleted.
 *
 * Unlike grades, attendance is not secret: students know whether they joined a class. So a
 * Zoom activity still counts when a teacher hides it or restricts it afterwards (often done to
 * tidy the course page once its classes are over). Who counts for a class is the attendance
 * plugin's class list, set when the class ended: students it was hidden from, or restricted
 * away from, at the time are not on it.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zoom_attendance_source implements source {
    #[\Override]
    public function collect(
        int $courseid,
        string $usersql,
        array $userparams,
        points_settings $settings,
        int $now,
        bool $keeplines
    ): result {
        global $DB;

        $result = new result($keeplines);
        $perpercent = $settings->get('zoomattendpoints');
        if (!points_settings::attendance_available() || $perpercent <= 0) {
            return $result;
        }

        $modinfo = get_fast_modinfo($courseid);
        $cms = [];
        foreach ($modinfo->get_instances_of('zoom') as $cm) {
            if (!$cm->deletioninprogress) {
                $cms[(int) $cm->id] = $cm;
            }
        }
        if (!$cms) {
            return $result;
        }
        $userset = array_flip(array_map('intval', $DB->get_fieldset_sql($usersql, $userparams)));
        if (!$userset) {
            return $result;
        }

        $points = [];
        foreach ($cms as $cmid => $cm) {
            try {
                $activity = $this->read_activity($cm, $userset, $keeplines);
            } catch (\Throwable $e) {
                // One broken Zoom activity (for example a missing Zoom record) must not break the leaderboard.
                debugging('mod_leaderboard: could not read Zoom attendance of course module ' . $cmid . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            if ($activity) {
                $points[$cmid] = $activity;
            }
        }
        return $this->add_points($result, $points, $perpercent, $keeplines);
    }

    /**
     * Read one Zoom activity's evaluated classes, with the percentage attended of everyone on
     * each class's list.
     *
     * Zoom attendance decides who counts for a class (its class list): someone who joined a class
     * they were not on the list for (not enrolled then, or the activity was hidden from them or
     * restricted to other groups) earns nothing for it, as in the attendance reports; someone on
     * the list who missed it gets 0%. The percentage is Zoom attendance's own.
     *
     * @param \cm_info $cm The Zoom activity.
     * @param array $userset userid => index, the users to read.
     * @param bool $keeplines Whether this is a breakdown.
     * @return array|null [cm, classes (occurrence id => occurrence), percents (occurrence id =>
     *     userid => percentage)], or null when nothing counts.
     */
    private function read_activity(\cm_info $cm, array $userset, bool $keeplines): ?array {
        $attendance = new attendance($cm);
        if (!$attendance->instance || empty($attendance->settings->enabled)) {
            return null;
        }
        $classes = [];
        foreach ($attendance->get_occurrences() as $occurrence) {
            if ($attendance->state($occurrence) === attendance::STATE_EVALUATED) {
                $classes[(int) $occurrence->id] = $occurrence;
            }
        }
        if (!$classes) {
            return null;
        }
        $candidates = $attendance->get_candidates(0, array_keys($userset));
        if (!$candidates) {
            return null;
        }
        // A breakdown is for one student: read only their rows. Only the candidates' rows matter.
        $single = count($userset) === 1 ? (int) array_key_first($userset) : null;
        $keys = array_flip(array_map(function ($userid) {
            return 'u:' . $userid;
        }, array_keys($candidates)));
        $results = $attendance->get_results(array_keys($classes), $single);
        $percents = [];
        foreach ($classes as $occurrenceid => $occurrence) {
            $rows = array_intersect_key($results[$occurrenceid] ?? [], $keys);
            foreach ($attendance->evaluate($occurrence, $candidates, $rows)->expected as $userid => $row) {
                $percents[$occurrenceid][(int) $userid] = (float) ($row->percentage ?? 0);
            }
        }
        return [$cm, $classes, $percents];
    }

    /**
     * Add the points of the classes read.
     *
     * @param result $result Where to add them.
     * @param array $points cmid => what read_activity() returned.
     * @param float $perpercent Points per percent attended.
     * @param bool $keeplines Whether this is a breakdown.
     * @return result
     */
    private function add_points(
        result $result,
        array $points,
        float $perpercent,
        bool $keeplines
    ): result {
        foreach ($points as $cmid => [$cm, $classes, $percents]) {
            foreach ($classes as $occurrenceid => $occurrence) {
                foreach ($percents[$occurrenceid] ?? [] as $userid => $percentage) {
                    $percent = round($percentage, 2);
                    $classpoints = $percent * $perpercent;
                    if (!$keeplines) {
                        $result->add_points($userid, $classpoints);
                        continue;
                    }
                    $date = userdate((int) $occurrence->timestart, get_string('strftimedatetimeshort', 'core_langconfig'));
                    $result->add_line($userid, [
                        'source' => 'zoomattendance',
                        'itemid' => (int) $occurrenceid,
                        'cmid' => $cmid,
                        'modname' => 'zoom',
                        'name' => get_string('zoomclass', 'leaderboard', (object) [
                            'name' => $cm->name,
                            'date' => $date,
                        ]),
                        'mark' => $percent,
                        'grademax' => 100.0,
                        'late' => false,
                        'permark' => $perpercent,
                        'points' => $classpoints,
                    ]);
                }
            }
        }
        return $result;
    }

    /**
     * The version of a course's attendance data, so cached rankings follow it.
     *
     * @param int $courseid The course.
     * @return string
     */
    public static function data_version(int $courseid): string {
        if (!class_exists(\local_zoomattendance\local\data_version::class)) {
            return '';
        }
        return \local_zoomattendance\local\data_version::for_course($courseid);
    }
}
