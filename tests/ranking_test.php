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

use mod_leaderboard\local\points_settings;
use mod_leaderboard\local\ranking;
use mod_leaderboard\local\ranking_builder;
use mod_leaderboard\local\source\assign_lateness;
use mod_leaderboard\local\source\gradebook_source;

/**
 * Tests for the points engine and rankings.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ranking_builder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(gradebook_source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assign_lateness::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ranking::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(points_settings::class)]
final class ranking_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The leaderboard module record. */
    private \stdClass $leaderboard;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->leaderboard = $this->getDataGenerator()->create_module('leaderboard', ['course' => $this->course->id]);
    }

    /**
     * Enrol a new user.
     *
     * @param string $role Role shortname.
     * @param array $userfields Extra user fields.
     * @param string $status Enrolment status: 'active' or 'suspended'.
     * @return \stdClass
     */
    private function enrol(string $role = 'student', array $userfields = [], string $status = 'active'): \stdClass {
        $user = $this->getDataGenerator()->create_user($userfields);
        $this->getDataGenerator()->enrol_user(
            $user->id,
            $this->course->id,
            $role,
            'manual',
            0,
            0,
            $status === 'active' ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED
        );
        return $user;
    }

    /**
     * Write a grade to the gradebook for an activity.
     *
     * @param string $modname Activity type.
     * @param int $instance Activity instance id.
     * @param int $userid The user.
     * @param float $grade The grade.
     */
    private function grade(string $modname, int $instance, int $userid, float $grade): void {
        grade_update(
            'mod/' . $modname,
            $this->course->id,
            'mod',
            $modname,
            $instance,
            0,
            [$userid => (object) ['userid' => $userid, 'rawgrade' => $grade]]
        );
    }

    /**
     * Record an assignment submission.
     *
     * @param int $assignid The assignment.
     * @param int $userid The user (0 for a team submission).
     * @param int $time The submission time.
     * @param int $groupid The group for team submissions.
     */
    private function submit(int $assignid, int $userid, int $time, int $groupid = 0): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assignid,
            'userid' => $userid,
            'groupid' => $groupid,
            'timecreated' => $time,
            'timemodified' => $time,
            'status' => 'submitted',
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
    }

    /**
     * The builder for the test leaderboard.
     *
     * @return ranking_builder
     */
    private function builder(): ranking_builder {
        api::reset_caches();
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->leaderboard->cmid);
        return api::get_builder($cm);
    }

    /**
     * Points of each user in a fresh calculation.
     *
     * @param int $groupid The group.
     * @return array userid => points
     */
    private function points(int $groupid = 0): array {
        $ranking = $this->builder()->calculate($groupid, time());
        return array_column($ranking->get_entries(), 'points', 'userid');
    }

    public function test_default_points_for_assignment_and_quiz(): void {
        $student = $this->enrol();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 50]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);

        $this->grade('assign', $assign->id, $student->id, 40);
        $this->grade('quiz', $quiz->id, $student->id, 8);

        // 40 x 2 + 8 x 2.
        $this->assertSame([$student->id => 96], $this->points());
    }

    public function test_late_assignment_uses_late_rate(): void {
        $now = time();
        $ontime = $this->enrol();
        $late = $this->enrol();
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $this->course->id, 'grade' => 50, 'duedate' => $now - DAYSECS]
        );

        $this->submit($assign->id, $ontime->id, $now - 2 * DAYSECS);
        $this->submit($assign->id, $late->id, $now - HOURSECS);
        $this->grade('assign', $assign->id, $ontime->id, 40);
        $this->grade('assign', $assign->id, $late->id, 40);

        $points = $this->points();
        $this->assertSame(80, $points[$ontime->id]);
        $this->assertSame(40, $points[$late->id]);
    }

    public function test_late_rate_can_be_set_to_two(): void {
        $now = time();
        $student = $this->enrol();
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $this->course->id, 'grade' => 50, 'duedate' => $now - DAYSECS]
        );
        $this->submit($assign->id, $student->id, $now);
        $this->grade('assign', $assign->id, $student->id, 40);

        global $DB;
        $DB->set_field('leaderboard', 'assignlatepoints', 2, ['id' => $this->leaderboard->id]);
        $this->assertSame(80, $this->points()[$student->id]);
    }

    public function test_extension_and_overrides_decide_lateness(): void {
        global $DB;
        $now = time();
        $withextension = $this->enrol();
        $withoverride = $this->enrol();
        $ingroup = $this->enrol();
        $plain = $this->enrol();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $ingroup->id]);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $this->course->id, 'grade' => 10, 'duedate' => $now - DAYSECS]
        );

        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assign->id, 'userid' => $withextension->id,
            'extensionduedate' => $now + DAYSECS, 'locked' => 0, 'mailed' => 0]);
        $DB->insert_record('assign_overrides', (object) ['assignid' => $assign->id, 'userid' => $withoverride->id,
            'duedate' => $now + DAYSECS]);
        $DB->insert_record('assign_overrides', (object) ['assignid' => $assign->id, 'groupid' => $group->id,
            'sortorder' => 1, 'duedate' => $now + DAYSECS]);

        foreach ([$withextension, $withoverride, $ingroup, $plain] as $student) {
            $this->submit($assign->id, $student->id, $now - HOURSECS);
            $this->grade('assign', $assign->id, $student->id, 10);
        }

        $points = $this->points();
        $this->assertSame(20, $points[$withextension->id], 'An extension moves the due date.');
        $this->assertSame(20, $points[$withoverride->id], 'A user override moves the due date.');
        $this->assertSame(20, $points[$ingroup->id], 'A group override moves the due date.');
        $this->assertSame(10, $points[$plain->id], 'Without an extension or override the work is late.');
    }

    public function test_no_due_date_or_no_submission_is_never_late(): void {
        $now = time();
        $nodue = $this->enrol();
        $offline = $this->enrol();
        $assignnodue = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => 0]);
        $assignoffline = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => $now - DAYSECS]);

        $this->submit($assignnodue->id, $nodue->id, $now);
        $this->grade('assign', $assignnodue->id, $nodue->id, 10);
        // Graded without any online submission.
        $this->grade('assign', $assignoffline->id, $offline->id, 10);

        $points = $this->points();
        $this->assertSame(20, $points[$nodue->id]);
        $this->assertSame(20, $points[$offline->id]);
    }

    public function test_team_submission_lateness_uses_group_submission(): void {
        $now = time();
        $member = $this->enrol();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => $now - DAYSECS, 'teamsubmission' => 1]);

        // The team handed in late; the member's own (empty) row does not count.
        $this->submit($assign->id, 0, $now - HOURSECS, $group->id);
        $this->grade('assign', $assign->id, $member->id, 10);

        $this->assertSame(10, $this->points()[$member->id]);
    }

    public function test_hidden_grades_do_not_count(): void {
        $now = time();
        $student = $this->enrol();
        $visible = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $hidden = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $hiddenuntil = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $revealed = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $gradehidden = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        foreach ([$visible, $hidden, $hiddenuntil, $revealed, $gradehidden] as $assign) {
            $this->grade('assign', $assign->id, $student->id, 10);
        }

        $item = fn($assign) => \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'courseid' => $this->course->id]);
        $item($hidden)->set_hidden(1);
        $item($hiddenuntil)->set_hidden($now + DAYSECS);
        $item($revealed)->set_hidden($now - DAYSECS);
        $grade = \grade_grade::fetch(['itemid' => $item($gradehidden)->id, 'userid' => $student->id]);
        $grade->set_hidden(1);

        // Visible and revealed only.
        $ranking = $this->builder()->calculate(0, $now);
        $this->assertSame(40, $ranking->get_entry((int) $student->id)['points']);
        // The ranking expires when the hidden-until item becomes visible.
        $this->assertLessThanOrEqual($now + DAYSECS, $ranking->get_valid_until());
    }

    public function test_quiz_marks_wait_for_overrides(): void {
        global $DB;
        $now = time();
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 10);

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $this->course->id]);
        // Marks shown after the quiz closed an hour ago...
        $item->set_hidden($now - HOURSECS);
        // ...but a student with extra time can still attempt it for a day.
        $other = $this->enrol();
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $other->id,
            'timeclose' => $now + DAYSECS]);

        $ranking = $this->builder()->calculate(0, $now);
        $this->assertSame(0, $ranking->get_entry((int) $student->id)['points']);
        $this->assertLessThanOrEqual($now + DAYSECS, $ranking->get_valid_until());

        $later = $this->builder()->calculate(0, $now + DAYSECS + 1);
        $this->assertSame(20, $later->get_entry((int) $student->id)['points']);
    }

    public function test_excluded_and_hidden_activity_grades_do_not_count(): void {
        global $DB;
        $student = $this->enrol();
        $excluded = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $hiddencm = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('assign', $excluded->id, $student->id, 10);
        $this->grade('assign', $hiddencm->id, $student->id, 10);

        $itemid = $DB->get_field('grade_items', 'id', ['itemmodule' => 'assign', 'iteminstance' => $excluded->id]);
        $DB->set_field('grade_grades', 'excluded', time(), ['itemid' => $itemid, 'userid' => $student->id]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $hiddencm->cmid]);

        $this->assertSame(0, $this->points()[$student->id]);
    }

    public function test_only_active_students_are_ranked(): void {
        $student = $this->enrol();
        $teacher = $this->enrol('editingteacher');
        $suspended = $this->enrol('student', [], 'suspended');
        $suspendeduser = $this->enrol('student', ['suspended' => 1]);

        $points = $this->points();
        $this->assertArrayHasKey($student->id, $points);
        $this->assertArrayNotHasKey($teacher->id, $points);
        $this->assertArrayNotHasKey($suspended->id, $points);
        $this->assertArrayNotHasKey($suspendeduser->id, $points);
    }

    public function test_other_activity_types_count_only_when_enabled(): void {
        global $DB;
        $this->setAdminUser();
        $student = $this->enrol();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('lesson', $lesson->id, $student->id, 10);

        $this->assertSame(0, $this->points()[$student->id]);

        $DB->set_field('leaderboard', 'otherpoints', 3, ['id' => $this->leaderboard->id]);
        $this->assertSame(30, $this->points()[$student->id]);
    }

    public function test_site_lock_overrides_instance_values(): void {
        global $DB;
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 10);
        $DB->set_field('leaderboard', 'quizpoints', 5, ['id' => $this->leaderboard->id]);

        $this->assertSame(50, $this->points()[$student->id]);

        set_config('lockpoints', 1, 'leaderboard');
        set_config('quizpoints', 3, 'leaderboard');
        $this->assertSame(30, $this->points()[$student->id]);
    }

    public function test_ties_use_competition_ranking_and_totals_are_rounded(): void {
        $a = $this->enrol('student', ['firstname' => 'Amina', 'lastname' => 'Akter']);
        $b = $this->enrol('student', ['firstname' => 'Bilal', 'lastname' => 'Bhuiyan']);
        $c = $this->enrol('student', ['firstname' => 'Chandni', 'lastname' => 'Chowdhury']);
        $d = $this->enrol('student', ['firstname' => 'Dipu', 'lastname' => 'Das']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $a->id, 9);
        $this->grade('quiz', $quiz->id, $b->id, 7.25);
        $this->grade('quiz', $quiz->id, $c->id, 7.25);
        $this->grade('quiz', $quiz->id, $d->id, 2);

        $entries = $this->builder()->calculate(0, time())->get_entries();
        $this->assertSame(array_map('intval', [$a->id, $b->id, $c->id, $d->id]), array_column($entries, 'userid'));
        $this->assertSame([1, 2, 2, 4], array_column($entries, 'rank'));
        // 7.25 x 2 = 14.5, rounded to 15.
        $this->assertSame([18, 15, 15, 4], array_column($entries, 'points'));
    }

    public function test_separate_groups_rank_within_the_group(): void {
        global $DB;
        $alpha = $this->enrol();
        $beta = $this->enrol();
        $nogroup = $this->enrol();
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $alpha->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $beta->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->leaderboard->cmid]);
        rebuild_course_cache($this->course->id, true);

        $this->assertSame([(int) $alpha->id], array_keys($this->points((int) $groupa->id)));

        $cm = get_fast_modinfo($this->course->id)->get_cm($this->leaderboard->cmid);
        $this->setUser($alpha);
        $this->assertSame((int) $groupa->id, api::get_viewer_groupid($cm));
        $this->setUser($nogroup);
        $this->assertNull(api::get_viewer_groupid($cm), 'No group in separate groups mode: nothing to show.');
    }

    public function test_cached_ranking_is_used_until_a_grade_changes(): void {
        // Observers run after commit, so run this test without the test transaction.
        $this->preventResetByRollback();
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 5);
        $builder = $this->builder();
        $this->waitForSecond();

        $first = $builder->get_ranking(0);
        $this->assertSame(10, $first->get_entry((int) $student->id)['points']);
        $this->assertNotNull(\mod_leaderboard\local\ranking_cache::get(
            $this->leaderboard->cmid,
            0,
            $this->course->id,
            $builder->get_settings_key(),
            time()
        ), 'The ranking is cached.');
        $this->assertSame($first->get_generated(), $builder->get_ranking(0)->get_generated(), 'The cache is used.');

        // The user_graded event marks the course as changed, so the next request recalculates.
        $this->waitForSecond();
        $this->grade('quiz', $quiz->id, $student->id, 6);
        $this->assertSame(12, $builder->get_ranking(0)->get_entry((int) $student->id)['points']);
    }

    public function test_breakdown_explains_the_points(): void {
        $now = time();
        $student = $this->enrol();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 50,
            'name' => 'Essay', 'duedate' => $now - DAYSECS]);
        $this->submit($assign->id, $student->id, $now);
        $this->grade('assign', $assign->id, $student->id, 30);

        $lines = $this->builder()->get_breakdown((int) $student->id);
        $this->assertCount(1, $lines);
        $this->assertSame('Essay', $lines[0]['name']);
        $this->assertTrue($lines[0]['late']);
        $this->assertEquals(1, $lines[0]['permark']);
        $this->assertEquals(30, $lines[0]['points']);
    }

    public function test_hidden_grade_category_hides_quiz_items(): void {
        $now = time();
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $later = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 10);
        $this->grade('quiz', $later->id, $student->id, 10);

        $exams = $this->getDataGenerator()->create_grade_category(['courseid' => $this->course->id, 'fullname' => 'Exams']);
        $results = $this->getDataGenerator()->create_grade_category(['courseid' => $this->course->id, 'fullname' => 'Results']);
        $item = fn($instance) => \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $instance, 'courseid' => $this->course->id]);
        $item($quiz->id)->set_parent($exams->id);
        $item($later->id)->set_parent($results->id);

        // Hiding a category does not hide the quiz items in it, so the plugin checks categories itself.
        \grade_category::fetch(['id' => $exams->id])->set_hidden(1, true);
        \grade_category::fetch(['id' => $results->id])->set_hidden($now + DAYSECS, true);
        $this->assertSame(0, (int) $item($quiz->id)->hidden, 'Core leaves quiz items visible.');

        $ranking = $this->builder()->calculate(0, $now);
        $this->assertSame(0, $ranking->get_entry((int) $student->id)['points']);
        $this->assertLessThanOrEqual($now + DAYSECS, $ranking->get_valid_until());
        $this->assertSame(20, $this->builder()->calculate(0, $now + DAYSECS + 1)->get_entry((int) $student->id)['points']);
    }

    public function test_quiz_override_without_close_date_holds_the_quiz(): void {
        global $DB;
        $now = time();
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 10);
        \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $this->course->id])->set_hidden($now - HOURSECS);

        // One student may attempt the quiz with no close date at all.
        $other = $this->enrol();
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $other->id, 'timeclose' => 0]);
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $student->id,
            'timeclose' => $now - MINSECS]);

        $this->assertSame(0, $this->points()[$student->id]);
    }

    public function test_restrictions_that_hide_an_activity_hide_its_grade(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        set_config('enableavailability', 1);
        $now = time();
        $ingroup = $this->enrol();
        $outside = $this->enrol();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $ingroup->id]);

        $date = fn(bool $show) => json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => $now + DAYSECS]],
            'showc' => [$show]]);
        $make = fn(array $extra = []) => $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $this->course->id, 'grade' => 10] + $extra
        );
        $hiddenbydate = $make();
        $greyedbydate = $make();
        $groupsonly = $make();
        $groupingonly = $make();
        $groupingordate = $make();
        $insection = $make(['section' => 1]);
        $grouping = $this->getDataGenerator()->create_grouping(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $DB->set_field('course_modules', 'availability', json_encode(['op' => '&',
            'c' => [['type' => 'grouping', 'id' => (int) $grouping->id]], 'showc' => [false]]), ['id' => $groupingonly->cmid]);
        // Mixed with a date that has not come yet, so only the grouping can let a student in.
        $mixed = json_encode(['op' => '|', 'show' => false, 'c' => [
            ['type' => 'grouping', 'id' => (int) $grouping->id],
            ['type' => 'date', 'd' => '>=', 't' => $now + DAYSECS],
        ]]);
        $DB->set_field('course_modules', 'availability', $mixed, ['id' => $groupingordate->cmid]);
        $DB->set_field('course_modules', 'availability', $date(false), ['id' => $hiddenbydate->cmid]);
        $DB->set_field('course_modules', 'availability', $date(true), ['id' => $greyedbydate->cmid]);
        $DB->set_field('course_modules', 'availability', json_encode(['op' => '&',
            'c' => [['type' => 'group', 'id' => (int) $group->id]], 'showc' => [false]]), ['id' => $groupsonly->cmid]);
        // A greyed-out restriction on the section still hides the activity's grade, as in the grade report.
        $DB->set_field('course_sections', 'availability', $date(true), ['course' => $this->course->id, 'section' => 1]);
        rebuild_course_cache($this->course->id, true);

        foreach ([$hiddenbydate, $greyedbydate, $groupsonly, $groupingonly, $groupingordate, $insection] as $assign) {
            $this->grade('assign', $assign->id, $ingroup->id, 10);
            $this->grade('assign', $assign->id, $outside->id, 10);
        }

        $ranking = $this->builder()->calculate(0, $now);
        // Greyed out: 20 for both. Group, grouping, grouping or date: 60 more for the group member.
        $this->assertSame(80, $ranking->get_entry((int) $ingroup->id)['points']);
        $this->assertSame(20, $ranking->get_entry((int) $outside->id)['points']);
        $this->assertLessThanOrEqual($now + DAYSECS, $ranking->get_valid_until(), 'Expires when the dates pass.');

        // The breakdown follows the same rule.
        $cmids = array_column($this->builder()->get_breakdown((int) $outside->id), 'cmid');
        $this->assertSame([(int) $greyedbydate->cmid], $cmids);

        // Once the date has passed, everything the student may access counts.
        $past = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => $now - DAYSECS]], 'showc' => [false]]);
        $DB->set_field('course_modules', 'availability', $past, ['id' => $hiddenbydate->cmid]);
        $DB->set_field('course_sections', 'availability', $past, ['course' => $this->course->id, 'section' => 1]);
        rebuild_course_cache($this->course->id, true);
        $this->assertSame(60, $this->points()[$outside->id]);
    }

    public function test_reopened_attempt_does_not_change_lateness(): void {
        global $DB;
        $now = time();
        $student = $this->enrol();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => $now - DAYSECS]);
        // Attempt 0 handed in on time and graded 8; the teacher reopened it and the student is
        // now working on attempt 1, after the due date.
        $this->submit($assign->id, $student->id, $now - 2 * DAYSECS);
        $DB->set_field('assign_submission', 'latest', 0, ['assignment' => $assign->id, 'userid' => $student->id]);
        $DB->insert_record('assign_submission', (object) ['assignment' => $assign->id, 'userid' => $student->id,
            'groupid' => 0, 'timecreated' => $now, 'timemodified' => $now, 'status' => 'draft', 'attemptnumber' => 1,
            'latest' => 1]);
        $DB->insert_record('assign_grades', (object) ['assignment' => $assign->id, 'userid' => $student->id,
            'grade' => 8, 'grader' => 2, 'attemptnumber' => 0, 'timecreated' => $now - DAYSECS,
            'timemodified' => $now - DAYSECS]);
        $this->grade('assign', $assign->id, $student->id, 8);

        $this->assertSame(16, $this->points()[$student->id], 'The graded attempt was on time.');
        $this->assertFalse($this->builder()->get_breakdown((int) $student->id)[0]['late']);

        // Once attempt 1 is handed in late and graded, it decides.
        $DB->set_field('assign_submission', 'status', 'submitted', ['assignment' => $assign->id, 'attemptnumber' => 1]);
        $DB->insert_record('assign_grades', (object) ['assignment' => $assign->id, 'userid' => $student->id,
            'grade' => 9, 'grader' => 2, 'attemptnumber' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $this->grade('assign', $assign->id, $student->id, 9);
        $this->assertSame(9, $this->points()[$student->id]);
    }

    public function test_relative_dates_move_the_due_date(): void {
        set_config('enablecourserelativedates', 1);
        $now = time();
        $this->course = $this->getDataGenerator()->create_course(['startdate' => $now - 30 * DAYSECS,
            'relativedatesmode' => 1]);
        $this->leaderboard = $this->getDataGenerator()->create_module('leaderboard', ['course' => $this->course->id]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => $now - 20 * DAYSECS]);

        $early = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($early->id, $this->course->id, 'student', 'manual', $now - 30 * DAYSECS);
        // Enrolled 25 days after the course start, so this student's due date is 5 days from now.
        $late = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($late->id, $this->course->id, 'student', 'manual', $now - 5 * DAYSECS);

        foreach ([$early, $late] as $student) {
            $this->submit($assign->id, $student->id, $now);
            $this->grade('assign', $assign->id, $student->id, 10);
        }

        $points = $this->points();
        $this->assertSame(10, $points[$early->id], 'Late against the course dates.');
        $this->assertSame(20, $points[$late->id], 'On time against the student\'s own dates.');
    }

    public function test_breakdown_matches_the_ranking_for_team_lateness(): void {
        $now = time();
        $student = $this->enrol();
        $teammate = $this->enrol();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $teammate->id]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 10,
            'duedate' => $now - DAYSECS, 'teamsubmission' => 1]);
        $this->submit($assign->id, 0, $now, (int) $group->id);
        $this->grade('assign', $assign->id, $student->id, 10);

        // The breakdown reads only this student's rows, but must reach the same answer.
        $this->assertSame(10, $this->points()[$student->id]);
        $this->assertTrue($this->builder()->get_breakdown((int) $student->id)[0]['late']);
    }

    public function test_hiding_a_single_grade_refreshes_the_cached_ranking(): void {
        // Observers run after commit, so run this test without the test transaction.
        $this->preventResetByRollback();
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 5);
        $builder = $this->builder();
        $this->waitForSecond();
        $this->assertSame(10, $builder->get_ranking(0)->get_entry((int) $student->id)['points']);

        // Hiding one grade fires no event; the grade history shows it.
        $this->waitForSecond();
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $this->course->id]);
        \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id])->set_hidden(1);
        $this->assertSame(0, $builder->get_ranking(0)->get_entry((int) $student->id)['points']);
    }

    public function test_previous_ranking_is_served_while_another_request_recalculates(): void {
        global $CFG;
        $this->preventResetByRollback();
        // Postgres advisory locks can be taken twice by one connection, so use database records here.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $this->grade('quiz', $quiz->id, $student->id, 5);
        $builder = $this->builder();
        $this->waitForSecond();
        $first = $builder->get_ranking(0);

        $this->waitForSecond();
        $this->grade('quiz', $quiz->id, $student->id, 6);
        $lock = \core\lock\lock_config::get_lock_factory('mod_leaderboard_ranking')
            ->get_lock('cm' . $this->leaderboard->cmid . '_g0', 0);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame($first->get_generated(), $builder->get_ranking(0)->get_generated());
        } finally {
            $lock->release();
        }
        $this->assertSame(12, $builder->get_ranking(0)->get_entry((int) $student->id)['points']);
    }

    public function test_totals_only_calculation_matches_the_breakdown(): void {
        $student = $this->enrol();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 20]);
        $this->grade('quiz', $quiz->id, $student->id, 7.5);
        $this->grade('assign', $assign->id, $student->id, 12);

        $lines = $this->builder()->get_breakdown((int) $student->id);
        $this->assertEquals(array_sum(array_column($lines, 'points')), $this->points()[$student->id]);
    }
}
