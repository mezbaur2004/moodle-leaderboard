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

/**
 * Works out which students handed in an assignment after their own due date.
 *
 * It follows the same rule as the assignment grading table (mod/assign/gradingtable.php,
 * col_status), but for many students and assignments at once, in a fixed number of
 * queries:
 *  - the due date is the student's extension if they have one, otherwise their user
 *    override, otherwise the group override with the lowest sort order among their
 *    groups, otherwise the assignment's due date (moved by the student's start offset
 *    in a course that uses relative dates);
 *  - the submission is the attempt whose grade the gradebook holds: the latest graded
 *    attempt, or the latest submitted (or draft) attempt when none is graded yet. So a
 *    reopened attempt that is still being worked on does not change the lateness of the
 *    grade already earned. Team assignments use the group's submission;
 *  - work is late when there is a due date and the submission time is after it.
 * Students with an extension or extra time are therefore only late if they miss their
 * own date. An assignment with no due date, or no online submission, is never late.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_lateness {
    /** @var int Up to this many users, the queries are limited to them (breakdowns); above it, the whole course is read. */
    private const FEW_USERS = 100;

    /**
     * Which users were late for which assignments.
     *
     * @param int $courseid The course.
     * @param int[] $assignids Assignment instance ids.
     * @param string $usersql SQL returning one column of user ids.
     * @param array $userparams Named parameters for $usersql.
     * @return array assignid => [userid => true] for late work only.
     */
    public static function get_late_map(int $courseid, array $assignids, string $usersql, array $userparams): array {
        global $CFG, $DB;

        $assigns = $DB->get_records_list(
            'assign',
            'id',
            $assignids,
            '',
            'id, duedate, teamsubmission, teamsubmissiongroupingid, preventsubmissionnotingroup'
        );
        if (!$assigns) {
            return [];
        }
        [$assignsql, $assignparams] = $DB->get_in_or_equal(array_keys($assigns), SQL_PARAMS_NAMED, 'lbassign');

        // The users we care about. For a few users (a breakdown), only their rows are read.
        $userids = array_map('intval', $DB->get_fieldset_sql($usersql, $userparams));
        if (!$userids) {
            return [];
        }
        $userset = array_flip($userids);
        $fewusers = count($userids) <= self::FEW_USERS;
        $userin = '';
        $userfilterparams = [];
        if ($fewusers) {
            [$userin, $userfilterparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lbuser');
        }
        $userfilter = $fewusers ? "AND userid $userin" : '';

        // User overrides: assignid => userid => duedate (null means "no due date override").
        $useroverrides = [];
        $rs = $DB->get_recordset_sql(
            "SELECT id, assignid, userid, duedate
               FROM {assign_overrides}
              WHERE assignid $assignsql AND userid IS NOT NULL",
            $assignparams
        );
        foreach ($rs as $row) {
            $useroverrides[$row->assignid][$row->userid] = $row->duedate === null ? null : (int) $row->duedate;
        }
        $rs->close();

        // Group overrides, lowest sort order first: assignid => list of [groupid, duedate].
        $groupoverrides = [];
        $rs = $DB->get_recordset_sql(
            "SELECT id, assignid, groupid, duedate, sortorder
               FROM {assign_overrides}
              WHERE assignid $assignsql AND groupid IS NOT NULL
           ORDER BY sortorder ASC, id ASC",
            $assignparams
        );
        foreach ($rs as $row) {
            $groupoverrides[$row->assignid][] = [(int) $row->groupid, $row->duedate === null ? null : (int) $row->duedate];
        }
        $rs->close();

        // Group memberships in this course: userid => [groupid => true].
        $usergroups = [];
        $rs = $DB->get_recordset_sql(
            "SELECT gm.id, gm.userid, gm.groupid
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = :courseid " . ($fewusers ? "AND gm.userid $userin" : ''),
            ['courseid' => $courseid] + $userfilterparams
        );
        $groupset = [];
        foreach ($rs as $row) {
            if (isset($userset[$row->userid])) {
                $usergroups[$row->userid][(int) $row->groupid] = true;
                $groupset[(int) $row->groupid] = true;
            }
        }
        $rs->close();

        // Groups in each grouping, needed for team submissions limited to a grouping.
        $groupinggroups = [];
        $needsgroupings = false;
        foreach ($assigns as $assign) {
            if ($assign->teamsubmission && $assign->teamsubmissiongroupingid) {
                $needsgroupings = true;
            }
        }
        if ($needsgroupings) {
            $rs = $DB->get_recordset_sql(
                "SELECT gg.id, gg.groupingid, gg.groupid
                   FROM {groupings_groups} gg
                   JOIN {groupings} gr ON gr.id = gg.groupingid
                  WHERE gr.courseid = :courseid",
                ['courseid' => $courseid]
            );
            foreach ($rs as $row) {
                $groupinggroups[$row->groupingid][(int) $row->groupid] = true;
            }
            $rs->close();
        }

        // Extensions: assignid => userid => extension due date.
        $extensions = [];
        $rs = $DB->get_recordset_sql(
            "SELECT id, assignment, userid, extensionduedate
               FROM {assign_user_flags}
              WHERE assignment $assignsql AND extensionduedate > 0",
            $assignparams
        );
        foreach ($rs as $row) {
            $extensions[$row->assignment][$row->userid] = (int) $row->extensionduedate;
        }
        $rs->close();

        // The latest graded attempt of each student: assignid => userid => attempt number.
        $graded = [];
        $rs = $DB->get_recordset_sql(
            "SELECT assignment, userid, MAX(attemptnumber) AS attemptnumber
               FROM {assign_grades}
              WHERE assignment $assignsql AND grade >= 0 $userfilter
           GROUP BY assignment, userid",
            $assignparams + $userfilterparams
        );
        foreach ($rs as $row) {
            if (isset($userset[$row->userid])) {
                $graded[$row->assignment][(int) $row->userid] = (int) $row->attemptnumber;
            }
        }
        $rs->close();

        // Submission times of submitted or draft attempts, by attempt number.
        // Individual: assignid => userid => [attempt => time]. Team: assignid => groupid => [attempt => time].
        $individual = [];
        $team = [];
        $teamfilter = '';
        $teamparams = [];
        if ($fewusers) {
            $teamfilter = 'AND 1 = 0';
            if ($groupset) {
                [$groupsql, $teamparams] = $DB->get_in_or_equal(array_keys($groupset), SQL_PARAMS_NAMED, 'lbgroup');
                $teamfilter = "AND groupid $groupsql";
            }
            $teamfilter = "AND ((userid = 0 $teamfilter) OR (userid <> 0 $userfilter))";
        }
        $rs = $DB->get_recordset_sql(
            "SELECT id, assignment, userid, groupid, attemptnumber, timemodified
               FROM {assign_submission}
              WHERE assignment $assignsql AND status IN ('submitted', 'draft') $teamfilter",
            $assignparams + $teamparams + $userfilterparams
        );
        foreach ($rs as $row) {
            if ((int) $row->userid === 0) {
                $team[$row->assignment][(int) $row->groupid][(int) $row->attemptnumber] = (int) $row->timemodified;
            } else if (isset($userset[$row->userid])) {
                $individual[$row->assignment][(int) $row->userid][(int) $row->attemptnumber] = (int) $row->timemodified;
            }
        }
        $rs->close();

        // Relative dates: each student's default due date moves with their start date.
        $startoffsets = [];
        $course = get_course($courseid);
        if (!empty($course->relativedatesmode)) {
            require_once($CFG->dirroot . '/course/lib.php');
            foreach (course_get_course_dates_for_user_ids($course, $userids) as $userid => $dates) {
                if (!empty($dates['start'])) {
                    $startoffsets[$userid] = (int) $dates['startoffset'];
                }
            }
        }

        $late = [];
        foreach ($assigns as $assign) {
            $assignid = (int) $assign->id;
            foreach ($userids as $userid) {
                $userid = (int) $userid;
                $groups = $usergroups[$userid] ?? [];

                $due = self::effective_due_date(
                    $assign,
                    $userid,
                    $groups,
                    $useroverrides[$assignid] ?? [],
                    $groupoverrides[$assignid] ?? [],
                    $extensions[$assignid] ?? [],
                    $startoffsets[$userid] ?? 0
                );
                if ($due <= 0) {
                    continue;
                }

                $submitted = self::submission_time(
                    $assign,
                    $userid,
                    $groups,
                    $groupinggroups,
                    $individual[$assignid] ?? [],
                    $team[$assignid] ?? [],
                    $graded[$assignid][$userid] ?? null
                );
                if ($submitted !== null && $submitted > $due) {
                    $late[$assignid][$userid] = true;
                }
            }
        }
        return $late;
    }

    /**
     * The due date that applies to one student, following mod_assign's rules.
     *
     * @param \stdClass $assign The assignment record.
     * @param int $userid The student.
     * @param array $groups groupid => true for the student's groups in the course.
     * @param array $useroverrides userid => duedate|null for this assignment.
     * @param array $groupoverrides List of [groupid, duedate|null], lowest sort order first.
     * @param array $extensions userid => extension due date for this assignment.
     * @param int $startoffset The student's start offset in a relative dates course, else 0.
     * @return int The due date, or 0 when there is none.
     */
    private static function effective_due_date(
        \stdClass $assign,
        int $userid,
        array $groups,
        array $useroverrides,
        array $groupoverrides,
        array $extensions,
        int $startoffset
    ): int {

        if (!empty($extensions[$userid])) {
            return $extensions[$userid];
        }

        // Like mod_assign, the offset moves only the assignment's own due date, not overrides.
        $due = (int) $assign->duedate;
        if ($due > 0) {
            $due += $startoffset;
        }
        if (array_key_exists($userid, $useroverrides)) {
            // A user override wins over group overrides, even when it leaves the due date alone.
            if ($useroverrides[$userid] !== null) {
                $due = $useroverrides[$userid];
            }
            return $due;
        }
        foreach ($groupoverrides as [$groupid, $duedate]) {
            if (isset($groups[$groupid])) {
                if ($duedate !== null) {
                    $due = $duedate;
                }
                break;
            }
        }
        return $due;
    }

    /**
     * When a student's work was handed in, following mod_assign's rules for team submissions.
     *
     * @param \stdClass $assign The assignment record.
     * @param int $userid The student.
     * @param array $groups groupid => true for the student's groups in the course.
     * @param array $groupinggroups groupingid => [groupid => true].
     * @param array $individual userid => [attempt => submission time] for this assignment.
     * @param array $team groupid => [attempt => submission time] for this assignment.
     * @param int|null $gradedattempt The student's latest graded attempt, or null.
     * @return int|null The submission time, or null when nothing was handed in.
     */
    private static function submission_time(
        \stdClass $assign,
        int $userid,
        array $groups,
        array $groupinggroups,
        array $individual,
        array $team,
        ?int $gradedattempt
    ): ?int {

        if ($assign->teamsubmission) {
            // Like mod_assign: the student's only group in the team grouping; none or several means "no group".
            $candidates = $groups;
            if ($assign->teamsubmissiongroupingid) {
                $candidates = array_intersect_key($groups, $groupinggroups[$assign->teamsubmissiongroupingid] ?? []);
            }
            $groupid = count($candidates) === 1 ? (int) array_key_first($candidates) : 0;

            if ($groupid || !$assign->preventsubmissionnotingroup) {
                if (isset($team[$groupid])) {
                    return self::pick_attempt($team[$groupid], $gradedattempt);
                }
            }
        }

        return isset($individual[$userid]) ? self::pick_attempt($individual[$userid], $gradedattempt) : null;
    }

    /**
     * The submission time of the graded attempt, or of the latest attempt when none is graded.
     *
     * @param array $attempts attempt number => submission time.
     * @param int|null $gradedattempt The latest graded attempt, or null.
     * @return int|null
     */
    private static function pick_attempt(array $attempts, ?int $gradedattempt): ?int {
        if ($gradedattempt !== null) {
            // A graded attempt that was never handed in (graded from the gradebook) is not late.
            return $attempts[$gradedattempt] ?? null;
        }
        return $attempts[max(array_keys($attempts))];
    }
}
