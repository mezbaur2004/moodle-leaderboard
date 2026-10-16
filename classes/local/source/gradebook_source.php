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

use mod_leaderboard\local\points_settings;

/**
 * Points from the gradebook: the final grade of each activity, times points per mark.
 *
 * Only grades a student is allowed to see count, so the leaderboard never reveals a
 * grade early:
 *  - only activity grade items (itemtype 'mod') with numeric grades;
 *  - the activity must exist, be visible and not be in the middle of being deleted;
 *  - the grade item must not be hidden, and a "hidden until" date must have passed;
 *  - for quizzes with a "hidden until" date, every user or group override's close
 *    time must also have passed, so students with extra time are never shown how
 *    others scored while they are still sitting the quiz;
 *  - the individual grade must not be hidden, excluded or empty.
 *
 * Hiding a grade category hides the items inside it in Moodle, so categories need no
 * separate check.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebook_source implements source {
    /** @var string[] Activity types another source handles, so this source skips them. */
    private array $excludedmodules;

    /**
     * Constructor.
     *
     * @param string[] $excludedmodules Activity types not to read from the gradebook.
     */
    public function __construct(array $excludedmodules = []) {
        $this->excludedmodules = $excludedmodules;
    }

    #[\Override]
    public function collect(int $courseid, string $usersql, array $userparams, points_settings $settings, int $now): result {
        global $DB, $CFG;
        require_once($CFG->libdir . '/grade/constants.php');

        $result = new result();

        $items = $this->get_counted_items($courseid, $settings, $now, $result);
        if (!$items) {
            return $result;
        }

        [$itemsql, $itemparams] = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED, 'lbitem');
        $sql = "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.hidden
                  FROM {grade_grades} gg
                 WHERE gg.itemid $itemsql
                   AND gg.finalgrade IS NOT NULL
                   AND gg.excluded = 0
                   AND gg.userid IN ($usersql)";
        $grades = $DB->get_recordset_sql($sql, $itemparams + $userparams);

        $visible = [];
        foreach ($grades as $grade) {
            $hidden = (int) $grade->hidden;
            if ($hidden === 1) {
                continue;
            }
            if ($hidden > 1 && $hidden > $now) {
                $result->change_at($hidden);
                continue;
            }
            $visible[] = $grade;
        }
        $grades->close();

        // Work out lateness only when it changes the points.
        $late = [];
        if ($settings->late_rate_differs()) {
            $assignids = [];
            foreach ($items as $item) {
                if ($item->itemmodule === 'assign') {
                    $assignids[] = (int) $item->iteminstance;
                }
            }
            if ($assignids) {
                $late = assign_lateness::get_late_map($courseid, $assignids, $usersql, $userparams);
            }
        }

        foreach ($visible as $grade) {
            $item = $items[$grade->itemid];
            $islate = $item->itemmodule === 'assign' && !empty($late[$item->iteminstance][$grade->userid]);
            $permark = $settings->per_mark($item->itemmodule, $islate);
            $mark = max(0.0, (float) $grade->finalgrade);
            $result->add_line((int) $grade->userid, [
                'source' => 'gradebook',
                'itemid' => (int) $item->id,
                'cmid' => (int) $item->cmid,
                'modname' => $item->itemmodule,
                'name' => (string) $item->itemname,
                'mark' => $mark,
                'grademax' => (float) $item->grademax,
                'late' => $islate,
                'permark' => $permark,
                'points' => $mark * $permark,
            ]);
        }

        return $result;
    }

    /**
     * The grade items that can earn points right now.
     *
     * @param int $courseid The course.
     * @param points_settings $settings Points per mark.
     * @param int $now The time to evaluate visibility at.
     * @param result $result Collects the next time a hidden item becomes visible.
     * @return array itemid => item record.
     */
    private function get_counted_items(int $courseid, points_settings $settings, int $now, result $result): array {
        global $DB;

        $sql = "SELECT gi.id, gi.itemmodule, gi.iteminstance, gi.itemnumber, gi.itemname, gi.grademax,
                       gi.hidden, cm.id AS cmid
                  FROM {grade_items} gi
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.course = gi.courseid
                                          AND cm.module = m.id
                                          AND cm.instance = gi.iteminstance
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = 'mod'
                   AND gi.gradetype = :gradetype
                   AND gi.hidden <> 1
                   AND cm.visible = 1
                   AND cm.deletioninprogress = 0
              ORDER BY gi.sortorder, gi.id";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'gradetype' => GRADE_TYPE_VALUE]);

        $items = [];
        $quizreveals = [];
        foreach ($records as $item) {
            $modname = $item->itemmodule;
            if (in_array($modname, $this->excludedmodules, true) || !$settings->counts($modname)) {
                continue;
            }
            $hidden = (int) $item->hidden;
            if ($hidden > 1) {
                $reveal = $hidden;
                if ($modname === 'quiz') {
                    $quizreveals[(int) $item->iteminstance][] = (int) $item->id;
                }
                if ($reveal > $now) {
                    $result->change_at($reveal);
                    continue;
                }
            }
            $items[$item->id] = $item;
        }

        // Quizzes whose marks are hidden until the close date: wait for every override too.
        if ($quizreveals) {
            [$quizsql, $quizparams] = $DB->get_in_or_equal(array_keys($quizreveals), SQL_PARAMS_NAMED, 'lbquiz');
            $closes = $DB->get_records_sql_menu(
                "SELECT quiz, MAX(timeclose)
                   FROM {quiz_overrides}
                  WHERE quiz $quizsql AND timeclose IS NOT NULL
               GROUP BY quiz",
                $quizparams
            );
            foreach ($closes as $quizid => $lastclose) {
                $lastclose = (int) $lastclose;
                if ($lastclose <= $now) {
                    continue;
                }
                foreach ($quizreveals[$quizid] as $itemid) {
                    if (isset($items[$itemid])) {
                        unset($items[$itemid]);
                        $result->change_at($lastclose);
                    }
                }
            }
        }

        return $items;
    }
}
