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
 *  - the same for every grade category the item is in. Hiding a category in Moodle does
 *    not hide quiz items inside it, or items added to it later, so categories are checked
 *    here;
 *  - for quizzes with a "hidden until" date, every user or group override's close
 *    time must also have passed, so students with extra time are never shown how
 *    others scored while they are still sitting the quiz. An override with no close
 *    date holds the quiz back until it is removed or given one;
 *  - the individual grade must not be hidden, excluded or empty;
 *  - a student's grade does not count while access restrictions hide the activity
 *    entirely from them, as the grade report does (see activity_access).
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
    public function collect(
        int $courseid,
        string $usersql,
        array $userparams,
        points_settings $settings,
        int $now,
        bool $keeplines
    ): result {
        global $DB, $CFG;
        require_once($CFG->libdir . '/grade/constants.php');

        $result = new result($keeplines);

        $items = $this->get_counted_items($courseid, $settings, $now, $result);
        if (!$items) {
            return $result;
        }
        $access = new activity_access($courseid, array_map('intval', array_column($items, 'cmid')), $now, $result);

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

        [$itemsql, $itemparams] = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED, 'lbitem');
        $sql = "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.hidden
                  FROM {grade_grades} gg
                 WHERE gg.itemid $itemsql
                   AND gg.finalgrade IS NOT NULL
                   AND gg.excluded = 0
                   AND gg.userid IN ($usersql)";
        $grades = $DB->get_recordset_sql($sql, $itemparams + $userparams);

        // Grades are added as they are read, so a large course never holds them all in memory.
        // Only grades of restricted activities wait, to be checked one student at a time.
        $restricted = [];
        foreach ($grades as $grade) {
            $hidden = (int) $grade->hidden;
            if ($hidden === 1) {
                continue;
            }
            if ($hidden > 1 && $hidden > $now) {
                $result->change_at($hidden);
                continue;
            }
            $item = $items[$grade->itemid];
            if ($access->is_restricted((int) $item->cmid)) {
                $restricted[] = [(int) $grade->userid, (int) $grade->itemid, (float) $grade->finalgrade];
                continue;
            }
            $this->add($result, $item, (int) $grade->userid, (float) $grade->finalgrade, $late, $settings);
        }
        $grades->close();

        // By student, so a student's course information is built at most once.
        usort($restricted, fn($a, $b) => $a[0] <=> $b[0]);
        $byactivity = [];
        foreach ($restricted as [$userid, $itemid]) {
            $byactivity[(int) $items[$itemid]->cmid][] = $userid;
        }
        $access->preload($byactivity);
        foreach ($restricted as [$userid, $itemid, $finalgrade]) {
            $item = $items[$itemid];
            if (!$access->is_hidden_from((int) $item->cmid, $userid)) {
                $this->add($result, $item, $userid, $finalgrade, $late, $settings);
            }
        }

        return $result;
    }

    /**
     * Add the points for one grade.
     *
     * @param result $result Where to add them.
     * @param \stdClass $item The grade item.
     * @param int $userid The student.
     * @param float $finalgrade The grade.
     * @param array $late assignid => [userid => true] for late work.
     * @param points_settings $settings Points per mark.
     */
    private function add(
        result $result,
        \stdClass $item,
        int $userid,
        float $finalgrade,
        array $late,
        points_settings $settings
    ): void {
        $islate = $item->itemmodule === 'assign' && !empty($late[$item->iteminstance][$userid]);
        $permark = $settings->per_mark($item->itemmodule, $islate);
        $mark = max(0.0, $finalgrade);
        if (!$result->keeps_lines()) {
            $result->add_points($userid, $mark * $permark);
            return;
        }
        $result->add_line($userid, [
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
                       gi.hidden, gi.categoryid, cm.id AS cmid
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
        if (!$records) {
            return [];
        }
        $categories = $DB->get_records('grade_categories', ['courseid' => $courseid], '', 'id, path, hidden');

        $items = [];
        $quizreveals = [];
        foreach ($records as $item) {
            $modname = $item->itemmodule;
            if (in_array($modname, $this->excludedmodules, true) || !$settings->counts($modname)) {
                continue;
            }
            if (!self::is_category_visible($categories, (int) $item->categoryid, $now, $result)) {
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
            $closes = $DB->get_records_sql(
                "SELECT quiz, MIN(timeclose) AS firstclose, MAX(timeclose) AS lastclose
                   FROM {quiz_overrides}
                  WHERE quiz $quizsql AND timeclose IS NOT NULL
               GROUP BY quiz",
                $quizparams
            );
            foreach ($closes as $quizid => $close) {
                // A close time of 0 means that student or group has no close date at all.
                $neverclosed = (int) $close->firstclose === 0;
                $lastclose = (int) $close->lastclose;
                if (!$neverclosed && $lastclose <= $now) {
                    continue;
                }
                foreach ($quizreveals[$quizid] as $itemid) {
                    if (isset($items[$itemid])) {
                        unset($items[$itemid]);
                        if (!$neverclosed) {
                            $result->change_at($lastclose);
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Whether a grade category and every category above it are visible.
     *
     * @param array $categories id => grade category record (id, path, hidden) for the course.
     * @param int $categoryid The item's category.
     * @param int $now The current time.
     * @param result $result Collects the next time a hidden category becomes visible.
     * @return bool
     */
    private static function is_category_visible(array $categories, int $categoryid, int $now, result $result): bool {
        if (!isset($categories[$categoryid])) {
            return true;
        }
        $path = (string) $categories[$categoryid]->path;
        $ids = $path !== '' ? array_filter(explode('/', $path)) : [$categoryid];
        foreach ($ids as $id) {
            if (!isset($categories[$id])) {
                continue;
            }
            $hidden = (int) $categories[$id]->hidden;
            if ($hidden === 1) {
                return false;
            }
            if ($hidden > 1 && $hidden > $now) {
                $result->change_at($hidden);
                return false;
            }
        }
        return true;
    }
}
