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
use local_zoomattendance\local\calculator;
use mod_leaderboard\local\points_settings;

/**
 * Points from Zoom attendance, as measured by local_zoomattendance.
 *
 * Each finished class of a Zoom activity counts once: the percentage of the class the
 * student attended (as the attendance plugin works it out) times the points per percent.
 * Only classes the attendance plugin has evaluated count, so upcoming, cancelled, excluded
 * and reset classes, and classes without a participant report, earn nothing. Zoom
 * activities that are hidden, being deleted, have attendance switched off, or are hidden
 * from the student by access restrictions do not count either.
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
            if ($cm->visible && !$cm->deletioninprogress) {
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
        $access = new activity_access($courseid, array_keys($cms), $now, $result);

        // Users with a result, by activity, so access restrictions can be checked in one go.
        $points = [];
        foreach ($cms as $cmid => $cm) {
            $attendance = new attendance($cm);
            if (!$attendance->instance || empty($attendance->settings->enabled)) {
                continue;
            }
            $classes = [];
            foreach ($attendance->get_occurrences() as $occurrence) {
                if ($attendance->state($occurrence) !== attendance::STATE_EVALUATED) {
                    continue;
                }
                $denominator = $attendance->settings->denominator_for($occurrence);
                if ($denominator > 0) {
                    $classes[(int) $occurrence->id] = [$occurrence, $denominator];
                }
            }
            if (!$classes) {
                continue;
            }
            $attended = [];
            foreach ($attendance->get_results(array_keys($classes)) as $occurrenceid => $rows) {
                foreach ($rows as $row) {
                    $userid = (int) $row->userid;
                    if ($userid && isset($userset[$userid])) {
                        $attended[$occurrenceid][$userid] = max($attended[$occurrenceid][$userid] ?? 0, (int) $row->attendedsecs);
                    }
                }
            }
            $points[$cmid] = [$cm, $classes, $attended];
        }

        $byactivity = [];
        foreach ($points as $cmid => [, , $attended]) {
            foreach ($attended as $users) {
                foreach (array_keys($users) as $userid) {
                    $byactivity[$cmid][] = $userid;
                }
            }
        }
        $access->preload($byactivity);

        foreach ($points as $cmid => [$cm, $classes, $attended]) {
            foreach ($classes as $occurrenceid => [$occurrence, $denominator]) {
                // A breakdown also lists the classes the student missed, at 0 points.
                $users = $attended[$occurrenceid] ?? [];
                if ($keeplines) {
                    $users += array_fill_keys(array_keys($userset), 0);
                }
                foreach ($users as $userid => $secs) {
                    if ($access->is_hidden_from($cmid, $userid)) {
                        continue;
                    }
                    $percent = round((float) calculator::percentage($secs, $denominator), 2);
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
