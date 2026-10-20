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
use mod_leaderboard\local\source\zoom_attendance_source;

/**
 * Tests for Zoom points from local_zoomattendance. Skipped when that plugin is not installed.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(zoom_attendance_source::class)]
final class zoom_attendance_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        if (!points_settings::attendance_available()) {
            $this->markTestSkipped('local_zoomattendance is not installed.');
        }
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('defaultenabled', 1, 'local_zoomattendance');
    }

    public function test_points_follow_the_percentage_attended(): void {
        global $DB;
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $leaderboard = $dg->create_module('leaderboard', ['course' => $course->id]);
        $amy = $dg->create_and_enrol($course, 'student');
        $ben = $dg->create_and_enrol($course, 'student');

        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $start = time() - 2 * DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $amy->id]);
        $generator->create_participant($session, $start, $start + 30 * MINSECS, ['userid' => $ben->id]);
        \local_zoomattendance\local\sync::sync_all();
        // A Zoom grade in the gradebook, which must not count as well.
        grade_update(
            'mod/zoom',
            $course->id,
            'mod',
            'zoom',
            $cm->instance,
            0,
            [$amy->id => (object) ['userid' => $amy->id, 'rawgrade' => 1]]
        );

        $points = function () use ($course, $leaderboard): array {
            api::reset_caches();
            $board = get_fast_modinfo($course->id)->get_cm($leaderboard->cmid);
            return array_column(api::get_builder($board)->calculate(0, time())->get_entries(), 'points', 'userid');
        };

        // From the gradebook (the default), only the grade counts.
        $this->assertSame(2, $points()[$amy->id]);
        $this->assertSame(0, $points()[$ben->id]);

        // From the attendance plugin: 100% and 50% of the class, 2 points per percent.
        $DB->set_field('leaderboard', 'zoomsource', points_settings::ZOOM_ATTENDANCE, ['id' => $leaderboard->id]);
        $this->assertSame(200, $points()[$amy->id]);
        $this->assertSame(100, $points()[$ben->id]);

        // The breakdown names the class and the percentage.
        api::reset_caches();
        $board = get_fast_modinfo($course->id)->get_cm($leaderboard->cmid);
        $lines = api::get_builder($board)->get_breakdown((int) $ben->id);
        $this->assertCount(1, $lines);
        $this->assertEquals(50, $lines[0]['mark']);
        $this->assertEquals(100, $lines[0]['points']);
        $this->assertSame((int) $cm->id, $lines[0]['cmid']);

        // Points per percent can be changed, and a class the teacher excludes stops counting.
        $DB->set_field('leaderboard', 'zoomattendpoints', 0.5, ['id' => $leaderboard->id]);
        $this->assertSame(50, $points()[$amy->id]);
        $DB->set_field(
            'local_zoomattendance_occ',
            'status',
            \local_zoomattendance\local\sync::STATUS_EXCLUDED,
            ['zoomid' => $cm->instance]
        );
        $this->assertSame(0, $points()[$amy->id]);
    }

    public function test_attendance_changes_refresh_the_cached_ranking(): void {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $leaderboard = $dg->create_module('leaderboard', ['course' => $course->id,
            'zoomsource' => points_settings::ZOOM_ATTENDANCE]);
        api::reset_caches();
        $board = get_fast_modinfo($course->id)->get_cm($leaderboard->cmid);
        $before = api::get_builder($board)->get_settings_key();
        \local_zoomattendance\local\data_version::bump_course((int) $course->id);
        $this->assertNotSame($before, api::get_builder($board)->get_settings_key());
    }

    /**
     * A course with a leaderboard in attendance mode.
     *
     * @param array $leaderboard Extra leaderboard fields.
     * @param array $course Extra course fields.
     * @return array [course, leaderboard]
     */
    private function setup_course(array $leaderboard = [], array $course = []): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course($course);
        $board = $dg->create_module('leaderboard', ['course' => $course->id,
            'zoomsource' => points_settings::ZOOM_ATTENDANCE] + $leaderboard);
        return [$course, $board];
    }

    /**
     * A finished Zoom class with participants.
     *
     * @param \stdClass $course The course.
     * @param array $attendance userid => minutes attended, from the start.
     * @param int $daysago When the class was.
     * @param array $zoom Extra Zoom fields.
     * @return \cm_info The Zoom activity.
     */
    private function zoom_class(\stdClass $course, array $attendance, int $daysago = 2, array $zoom = []): \cm_info {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_zoomattendance');
        $start = time() - $daysago * DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS] + $zoom);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        foreach ($attendance as $userid => $minutes) {
            $generator->create_participant($session, $start, $start + $minutes * MINSECS, ['userid' => $userid]);
        }
        return $cm;
    }

    /**
     * Points of each ranked user, freshly calculated.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $board The leaderboard.
     * @param int $groupid The group.
     * @return array userid => points
     */
    private function points(\stdClass $course, \stdClass $board, int $groupid = 0): array {
        api::reset_caches();
        $cm = get_fast_modinfo($course->id)->get_cm($board->cmid);
        return array_column(api::get_builder($cm)->calculate($groupid, time())->get_entries(), 'points', 'userid');
    }

    /**
     * Breakdown lines of one user.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $board The leaderboard.
     * @param int $userid The user.
     * @return array
     */
    private function lines(\stdClass $course, \stdClass $board, int $userid): array {
        api::reset_caches();
        $cm = get_fast_modinfo($course->id)->get_cm($board->cmid);
        return api::get_builder($cm)->get_breakdown($userid);
    }

    public function test_several_classes_add_up_and_upcoming_ones_wait(): void {
        [$course, $board] = $this->setup_course();
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->zoom_class($course, [$amy->id => 60], 3);
        $this->zoom_class($course, [$amy->id => 15], 2);
        // A class next week, and one held with no session report at all.
        $generator = $this->getDataGenerator()->get_plugin_generator('local_zoomattendance');
        $generator->create_zoom(['course' => $course->id, 'start_time' => time() + WEEKSECS, 'duration' => HOURSECS]);
        $generator->create_zoom(['course' => $course->id, 'start_time' => time() - DAYSECS, 'duration' => HOURSECS]);
        \local_zoomattendance\local\sync::sync_all();

        // 100% + 25% at 2 points per percent.
        $this->assertSame(250, $this->points($course, $board)[$amy->id]);
        $this->assertCount(2, $this->lines($course, $board, (int) $amy->id));
    }

    public function test_attendance_is_capped_and_rounded(): void {
        [$course, $board] = $this->setup_course(['zoomattendpoints' => 1.5]);
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $ben = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Amy stays after the end; Ben attends 40 of 60 minutes (66.67%).
        $cm = $this->zoom_class($course, [$amy->id => 90, $ben->id => 40]);
        \local_zoomattendance\local\sync::sync_all();

        $points = $this->points($course, $board);
        $this->assertSame(150, $points[$amy->id], 'Never more than 100%.');
        $this->assertSame(100, $points[$ben->id], '66.67% x 1.5 = 100.005, rounded.');
        $this->assertSame((int) $cm->id, $this->lines($course, $board, (int) $ben->id)[0]['cmid']);
    }

    public function test_switched_off_does_not_count_but_hidden_does(): void {
        global $DB;
        [$course, $board] = $this->setup_course();
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $off = $this->zoom_class($course, [$amy->id => 60], 3);
        $hidden = $this->zoom_class($course, [$amy->id => 60], 2);
        $insection = $this->zoom_class($course, [$amy->id => 30], 1);
        \local_zoomattendance\local\sync::sync_all();
        moveto_module(get_coursemodule_from_id('zoom', $insection->id), $DB->get_record(
            'course_sections',
            ['course' => $course->id, 'section' => 1]
        ));
        rebuild_course_cache($course->id, true);
        $this->assertSame(500, $this->points($course, $board)[$amy->id]);

        $DB->insert_record('local_zoomattendance_setting', (object) ['cmid' => $off->id, 'enabled' => 0,
            'timemodified' => time()]);
        // Attendance is not secret: hiding an activity, or the section it is in, keeps its points.
        set_coursemodule_visible($hidden->id, 0);
        set_section_visible($course->id, 1, 0);
        $this->assertSame(0, (int) get_fast_modinfo($course->id)->get_cm($insection->id)->visible);
        $this->assertSame(300, $this->points($course, $board)[$amy->id]);

        // An activity being deleted stops counting at once.
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $hidden->id]);
        rebuild_course_cache($course->id, true);
        $this->assertSame(100, $this->points($course, $board)[$amy->id]);
    }

    public function test_restrictions_do_not_hide_attendance_but_links_follow_access(): void {
        global $DB;
        set_config('enableavailability', 1);
        [$course, $board] = $this->setup_course();
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $ben = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $amy->id]);
        $cm = $this->zoom_class($course, [$amy->id => 60, $ben->id => 60]);
        \local_zoomattendance\local\sync::sync_all();
        $DB->set_field('course_modules', 'availability', json_encode(['op' => '&',
            'c' => [['type' => 'group', 'id' => (int) $group->id]], 'showc' => [false]]), ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);

        // Ben attended a class meant for another group: it still counts.
        $points = $this->points($course, $board);
        $this->assertSame(200, $points[$amy->id]);
        $this->assertSame(200, $points[$ben->id]);

        // His breakdown lists it, without a link he could not open; Amy and the teacher get the link.
        $url = function (\stdClass $viewer) use ($course, $board, $ben): ?string {
            $this->setUser($viewer);
            api::reset_caches();
            $data = (new output\board(get_fast_modinfo($course->id)->get_cm($board->cmid)))->export_breakdown((int) $ben->id);
            return $data['rows'][0]['url'];
        };
        $this->assertNull($url($ben));
        $this->assertNotNull($url($teacher));
        $this->setUser($amy);
        api::reset_caches();
        $amydata = (new output\board(get_fast_modinfo($course->id)->get_cm($board->cmid)))->export_breakdown((int) $amy->id);
        $this->assertNotNull($amydata['rows'][0]['url']);
    }

    public function test_groups_teachers_and_unmatched_participants(): void {
        [$course, $board] = $this->setup_course([], ['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $dg = $this->getDataGenerator();
        $amy = $dg->create_and_enrol($course, 'student');
        $ben = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'editingteacher');
        $group = $dg->create_group(['courseid' => $course->id]);
        $dg->create_group_member(['groupid' => $group->id, 'userid' => $amy->id]);
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $amy->id]);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $teacher->id]);
        $generator->create_participant($session, $start, $start + HOURSECS, ['name' => 'Phone',
            'user_email' => 'ben-phone@example.org']);
        \local_zoomattendance\local\sync::sync_all();

        $this->assertSame([(int) $amy->id => 200], $this->points($course, $board, (int) $group->id));
        $all = $this->points($course, $board);
        $this->assertArrayNotHasKey($teacher->id, $all, 'Teachers are not ranked.');
        $this->assertSame(0, $all[$ben->id], 'An unmatched participant counts for nobody.');

        // Once a teacher links the phone to Ben, it counts for him.
        \local_zoomattendance\local\manual::link_identity((int) $course->id, \local_zoomattendance\local\sync::unmatched_key(
            (object) ['user_email' => 'ben-phone@example.org', 'uuid' => '', 'zoomuserid' => '', 'name' => 'Phone']
        ), (int) $ben->id, 'Phone');
        \local_zoomattendance\local\sync::sync_all(null, true);
        $this->assertSame(200, $this->points($course, $board)[$ben->id]);
    }

    public function test_breakdown_lists_missed_classes_only_after_enrolment(): void {
        [$course, $board] = $this->setup_course();
        $dg = $this->getDataGenerator();
        $amy = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'editingteacher');
        $this->zoom_class($course, [$amy->id => 60, $teacher->id => 60], 5);
        // Only the host joined this one (with no participant at all, the report counts as missing).
        $this->zoom_class($course, [$teacher->id => 60], 1);
        // Ben enrolled three days ago: only the second class was his.
        $ben = $dg->create_user();
        $dg->enrol_user($ben->id, $course->id, 'student', 'manual', time() - 3 * DAYSECS);
        \local_zoomattendance\local\sync::sync_all();

        $this->assertCount(2, $this->lines($course, $board, (int) $amy->id), 'One attended, one missed.');
        $benlines = $this->lines($course, $board, (int) $ben->id);
        $this->assertCount(1, $benlines);
        $this->assertEquals(0, $benlines[0]['points']);
    }

    public function test_locked_site_values_decide_the_source(): void {
        global $DB;
        [$course, $board] = $this->setup_course();
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = $this->zoom_class($course, [$amy->id => 60]);
        \local_zoomattendance\local\sync::sync_all();
        grade_update(
            'mod/zoom',
            $course->id,
            'mod',
            'zoom',
            $cm->instance,
            0,
            [$amy->id => (object) ['userid' => $amy->id, 'rawgrade' => 5]]
        );
        $this->assertSame(200, $this->points($course, $board)[$amy->id]);

        // The site locks its values, and its source is the gradebook.
        set_config('lockpoints', 1, 'leaderboard');
        set_config('zoomsource', points_settings::ZOOM_GRADEBOOK, 'leaderboard');
        $this->assertSame(10, $this->points($course, $board)[$amy->id]);
        set_config('zoomsource', points_settings::ZOOM_ATTENDANCE, 'leaderboard');
        set_config('zoomattendpoints', 0.5, 'leaderboard');
        $this->assertSame(50, $this->points($course, $board)[$amy->id]);
        $this->assertSame(points_settings::ZOOM_ATTENDANCE, $DB->get_field('leaderboard', 'zoomsource', ['id' => $board->id]));
    }

    public function test_settings_survive_backup_and_restore(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        [$course, $board] = $this->setup_course(['zoomattendpoints' => 0.25]);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();
        $newid = \restore_dbops::create_new_course('Copy', 'copy' . random_string(4), $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $copy = $DB->get_record('leaderboard', ['course' => $newid], '*', MUST_EXIST);
        $this->assertSame(points_settings::ZOOM_ATTENDANCE, $copy->zoomsource);
        $this->assertEquals(0.25, $copy->zoomattendpoints);
    }

    public function test_zero_points_per_percent_and_rules(): void {
        global $PAGE;
        [$course, $board] = $this->setup_course(['zoomattendpoints' => 0]);
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = $this->zoom_class($course, [$amy->id => 60]);
        \local_zoomattendance\local\sync::sync_all();
        grade_update(
            'mod/zoom',
            $course->id,
            'mod',
            'zoom',
            $cm->instance,
            0,
            [$amy->id => (object) ['userid' => $amy->id, 'rawgrade' => 5]]
        );
        $this->assertSame(0, $this->points($course, $board)[$amy->id], 'Neither attendance nor the grade counts.');

        $this->setUser($amy);
        api::reset_caches();
        $data = (new output\board(get_fast_modinfo($course->id)->get_cm($board->cmid)))->export_full(10);
        $labels = array_column($data['rules'], 'label');
        $this->assertContains(get_string('zoomattendpoints_rule', 'leaderboard'), $labels);
        $this->assertNotContains(get_string('zoompoints_rule', 'leaderboard'), $labels);
        $this->assertNotEmpty($PAGE);
    }

    public function test_a_broken_zoom_activity_does_not_break_the_leaderboard(): void {
        global $DB;
        [$course, $board] = $this->setup_course();
        $amy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $broken = $this->zoom_class($course, [$amy->id => 60], 2);
        $this->zoom_class($course, [$amy->id => 30], 1);
        \local_zoomattendance\local\sync::sync_all();
        $DB->delete_records('zoom', ['id' => $broken->instance]);

        $this->assertSame(100, $this->points($course, $board)[$amy->id]);
        $this->assertDebuggingCalled();
    }
}
