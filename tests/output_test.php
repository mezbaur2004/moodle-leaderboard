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

use mod_leaderboard\output\board;
use mod_leaderboard\output\mobile;

/**
 * Tests for what students and teachers see: web pages, the course page summary and the app.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(board::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mobile::class)]
final class output_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The leaderboard module. */
    private \stdClass $leaderboard;

    /** @var \stdClass[] Students, best first. */
    private array $students = [];

    /** @var \stdClass A teacher. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->leaderboard = $this->getDataGenerator()->create_module(
            'leaderboard',
            ['course' => $this->course->id, 'name' => 'Class ranking']
        );
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 10,
            'name' => 'Week 1 quiz']);

        $names = [['Rahim', 'Uddin', 10], ['Nusrat', 'Jahan', 8], ['Tanvir', 'Hasan', 6], ['Mitu', 'Akter', 4],
            ['Sakib', 'Khan', 2], ['Farzana', 'Rahman', 1]];
        foreach ($names as [$first, $last, $grade]) {
            $student = $this->getDataGenerator()->create_and_enrol(
                $this->course,
                'student',
                ['firstname' => $first, 'lastname' => $last]
            );
            grade_update(
                'mod/quiz',
                $this->course->id,
                'mod',
                'quiz',
                $quiz->id,
                0,
                [$student->id => (object) ['userid' => $student->id, 'rawgrade' => $grade]]
            );
            $this->students[] = $student;
        }
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * The leaderboard as seen by the current user.
     *
     * @return \cm_info
     */
    private function cm(): \cm_info {
        api::reset_caches();
        return get_fast_modinfo($this->course->id)->get_cm($this->leaderboard->cmid);
    }

    /**
     * Render a template.
     *
     * @param string $name Template name without component.
     * @param array $data Context.
     * @return string
     */
    private function render(string $name, array $data): string {
        global $PAGE;
        return $PAGE->get_renderer('mod_leaderboard')->render_from_template('mod_leaderboard/' . $name, $data);
    }

    public function test_full_page_for_a_student_outside_the_top(): void {
        global $DB;
        $DB->set_field('leaderboard', 'topn', 3, ['id' => $this->leaderboard->id]);
        $this->setUser($this->students[4]);

        $data = (new board($this->cm()))->export_full(3);
        $this->assertTrue($data['showboard']);
        $this->assertCount(3, $data['rows']);
        $this->assertTrue($data['meoutside'], 'A student outside the top still sees their own place.');
        $this->assertSame(5, $data['merow']['rank']);
        $this->assertSame('4', $data['merow']['points']);

        $html = $this->render('board', $data);
        $this->assertStringContainsString('Rahim Uddin', $html);
        $this->assertStringContainsString('Sakib Khan', $html);
        $this->assertStringNotContainsString('Mitu Akter', $html, 'Students between the top and the viewer are not listed.');
        $this->assertStringContainsString('You are #5 of 6 with 4 points', $html);
    }

    public function test_anonymous_mode_hides_other_names_from_students(): void {
        set_config('identitymode', 'anonymous', 'leaderboard');
        $this->setUser($this->students[1]);

        $html = $this->render('board', (new board($this->cm()))->export_full(10));
        $this->assertStringNotContainsString('Rahim Uddin', $html);
        $this->assertStringContainsString('Nusrat Jahan', $html, 'Students still see their own name.');
        $this->assertStringContainsString(get_string('anonymousstudent', 'leaderboard'), $html);

        $this->setUser($this->teacher);
        $html = $this->render('board', (new board($this->cm()))->export_full(10));
        $this->assertStringContainsString('Rahim Uddin', $html, 'Teachers see names.');
    }

    public function test_anonymous_mode_needs_enough_students(): void {
        set_config('identitymode', 'anonymous', 'leaderboard');
        set_config('mincohort', 10, 'leaderboard');
        $this->setUser($this->students[0]);

        $data = (new board($this->cm()))->export_full(10);
        $this->assertFalse($data['showboard']);
        $this->assertTrue($data['toofew']);
    }

    public function test_breakdown_permissions(): void {
        $this->setUser($this->students[0]);
        $board = new board($this->cm());
        $this->assertTrue($board->can_view_breakdown((int) $this->students[0]->id));
        $this->assertFalse($board->can_view_breakdown((int) $this->students[1]->id));

        $html = $this->render('breakdown', $board->export_breakdown((int) $this->students[0]->id));
        $this->assertStringContainsString('Week 1 quiz', $html);
        $this->assertStringContainsString('20', $html);

        $this->setUser($this->teacher);
        $this->assertTrue((new board($this->cm()))->can_view_breakdown((int) $this->students[1]->id));
    }

    public function test_course_page_summary_is_shown_to_students(): void {
        $this->setUser($this->students[2]);
        $cm = get_fast_modinfo($this->course->id, $this->students[2]->id)->get_cm($this->leaderboard->cmid);
        $content = $cm->get_formatted_content();
        $this->assertStringContainsString('mod-leaderboard-summary', $content);
        $this->assertStringContainsString('Rahim Uddin', $content);
        $this->assertStringContainsString('You are #3 of 6', $content);
    }

    public function test_course_page_summary_can_be_switched_off(): void {
        global $DB;
        $DB->set_field('leaderboard', 'showinline', 0, ['id' => $this->leaderboard->id]);
        rebuild_course_cache($this->course->id, true);
        $this->setUser($this->students[2]);
        $cm = get_fast_modinfo($this->course->id, $this->students[2]->id)->get_cm($this->leaderboard->cmid);
        $this->assertStringNotContainsString('mod-leaderboard-summary', $cm->get_formatted_content());
    }

    public function test_mobile_app_methods(): void {
        $this->setUser($this->students[1]);
        $args = ['cmid' => $this->leaderboard->cmid, 'courseid' => $this->course->id, 'userid' => $this->students[0]->id];

        $view = mobile::mobile_view($args);
        $html = $view['templates'][0]['html'];
        $this->assertStringContainsString('<ion-list>', $html);
        $this->assertStringContainsString('ngNonBindable', $html);
        $this->assertStringContainsString('Nusrat Jahan', $html);
        $this->assertStringContainsString('You are #2 of 6', $html, 'The page is for the logged-in user, not args.userid.');
        $this->assertStringContainsString('Week 1 quiz', $html);

        $course = mobile::mobile_course_page($args)['templates'][0]['html'];
        $this->assertStringContainsString('Class ranking', $course);
        $this->assertStringContainsString('Rahim Uddin', $course);
        $this->assertStringNotContainsString('<ion-', $course, 'The course page summary is plain HTML.');
    }

    public function test_mobile_requires_access(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\require_login_exception::class);
        mobile::mobile_view(['cmid' => $this->leaderboard->cmid, 'courseid' => $this->course->id]);
    }

    public function test_settings_survive_backup_and_restore(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->setAdminUser();
        $DB->update_record('leaderboard', (object) ['id' => $this->leaderboard->id, 'assignlatepoints' => 2,
            'quizpoints' => 3.5, 'topn' => 7, 'showinline' => 0]);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course('Copy', 'copy1', $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_record('leaderboard', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame('Class ranking', $restored->name);
        $this->assertEquals(2, $restored->assignlatepoints);
        $this->assertEquals(3.5, $restored->quizpoints);
        $this->assertEquals(7, $restored->topn);
        $this->assertEquals(0, $restored->showinline);
    }

    public function test_find_course_leaderboard(): void {
        $this->setUser($this->students[0]);
        $this->assertSame((int) $this->leaderboard->cmid, (int) api::find_course_leaderboard($this->course->id)->id);

        $empty = $this->getDataGenerator()->create_course();
        $this->assertNull(api::find_course_leaderboard($empty->id));
    }
}
