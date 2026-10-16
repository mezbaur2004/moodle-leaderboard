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
 *    groups, otherwise the assignment's due date;
 *  - the submission time is the time of the latest submitted (or draft) attempt, using
 *    the group's submission for team assignments;
 *  - work is late when there is a due date and the submission time is after it.
 * Students with an extension or extra time are therefore only late if they miss their
 * own date. An assignment with no due date, or no online submission, is never late.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_lateness {
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
        global $DB;

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

        // The users we care about.
        $userids = $DB->get_fieldset_sql($usersql, $userparams);
        if (!$userids) {
            return [];
        }
        $userset = array_flip($userids);

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
              WHERE g.courseid = :courseid",
            ['courseid' => $courseid]
        );
        foreach ($rs as $row) {
            if (isset($userset[$row->userid])) {
                $usergroups[$row->userid][(int) $row->groupid] = true;
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

        // Submission times of the latest submitted or draft attempt.
        // Individual: assignid => userid => time. Team: assignid => groupid => time.
        $individual = [];
        $team = [];
        $attempts = [];
        $rs = $DB->get_recordset_sql(
            "SELECT id, assignment, userid, groupid, attemptnumber, timemodified
               FROM {assign_submission}
              WHERE assignment $assignsql AND status IN ('submitted', 'draft')",
            $assignparams
        );
        foreach ($rs as $row) {
            if ((int) $row->userid === 0) {
                $key = 't' . $row->assignment . '_' . $row->groupid;
                if (!isset($attempts[$key]) || $row->attemptnumber > $attempts[$key]) {
                    $attempts[$key] = (int) $row->attemptnumber;
                    $team[$row->assignment][(int) $row->groupid] = (int) $row->timemodified;
                }
            } else if (isset($userset[$row->userid])) {
                $key = 'u' . $row->assignment . '_' . $row->userid;
                if (!isset($attempts[$key]) || $row->attemptnumber > $attempts[$key]) {
                    $attempts[$key] = (int) $row->attemptnumber;
                    $individual[$row->assignment][(int) $row->userid] = (int) $row->timemodified;
                }
            }
        }
        $rs->close();

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
                    $extensions[$assignid] ?? []
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
                    $team[$assignid] ?? []
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
     * @return int The due date, or 0 when there is none.
     */
    private static function effective_due_date(
        \stdClass $assign,
        int $userid,
        array $groups,
        array $useroverrides,
        array $groupoverrides,
        array $extensions
    ): int {

        if (!empty($extensions[$userid])) {
            return $extensions[$userid];
        }

        $due = (int) $assign->duedate;
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
     * @param array $individual userid => submission time for this assignment.
     * @param array $team groupid => submission time for this assignment.
     * @return int|null The submission time, or null when nothing was handed in.
     */
    private static function submission_time(
        \stdClass $assign,
        int $userid,
        array $groups,
        array $groupinggroups,
        array $individual,
        array $team
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
                    return $team[$groupid];
                }
            }
        }

        return $individual[$userid] ?? null;
    }
}
