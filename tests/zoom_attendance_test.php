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
}
