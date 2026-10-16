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

use mod_leaderboard\local\provisioner;

/**
 * Tests for adding leaderboards to courses automatically.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provisioner::class)]
final class provisioner_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        // On Postgres each test normally runs inside a transaction. Production course creation and restores
        // do not, and the provisioner behaves differently inside a transaction, so switch that off.
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('autoprovisionintests', 1, 'leaderboard');
    }

    /**
     * Leaderboard course module ids in a course, oldest first.
     *
     * @param int $courseid The course.
     * @return int[]
     */
    private function leaderboards(int $courseid): array {
        global $DB;
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid AND m.name = 'leaderboard' AND cm.deletioninprogress = 0
              ORDER BY cm.id";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Course module ids in section 0, in order.
     *
     * @param int $courseid The course.
     * @return int[]
     */
    private function section0(int $courseid): array {
        global $DB;
        $sequence = (string) $DB->get_field('course_sections', 'sequence', ['course' => $courseid, 'section' => 0]);
        return array_map('intval', array_filter(explode(',', $sequence)));
    }

    /**
     * Back up a course and restore it.
     *
     * @param int $fromcourseid The course to back up.
     * @param int $target backup::TARGET_xx.
     * @param int|null $intocourseid The course to restore into, or null for a new course.
     * @return int The course restored into.
     */
    private function backup_and_restore(int $fromcourseid, int $target, ?int $intocourseid = null): int {
        global $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $fromcourseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        if ($intocourseid === null) {
            $category = $this->getDataGenerator()->create_category();
            $intocourseid = \restore_dbops::create_new_course('Restored', 'restored' . random_string(6), $category->id);
        }
        $rc = new \restore_controller(
            $backupid,
            $intocourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            $target
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $intocourseid;
    }

    public function test_new_course_gets_one_leaderboard(): void {
        $course = $this->getDataGenerator()->create_course();
        $cmids = $this->leaderboards($course->id);
        $this->assertCount(1, $cmids);
        $this->assertSame($cmids[0], $this->section0($course->id)[0]);
        $marker = provisioner::get_marker($course->id);
        $this->assertSame(provisioner::STATUS_ADDED, $marker->status);
        $this->assertSame($cmids[0], (int) $marker->cmid);
        $this->assertSame(get_string('defaultname', 'leaderboard'), get_fast_modinfo($course->id)->get_cm($cmids[0])->name);
    }

    public function test_switched_off_or_skipped_format(): void {
        set_config('autoprovision', 0, 'leaderboard');
        $course = $this->getDataGenerator()->create_course();
        $this->assertCount(0, $this->leaderboards($course->id));

        set_config('autoprovision', 1, 'leaderboard');
        $single = $this->getDataGenerator()->create_course(['format' => 'singleactivity', 'activitytype' => 'forum']);
        $this->assertCount(0, $this->leaderboards($single->id));
    }

    public function test_added_before_existing_content_and_not_twice(): void {
        set_config('autoprovision', 0, 'leaderboard');
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'section' => 0]);

        $this->assertSame(provisioner::STATUS_ADDED, provisioner::ensure($course->id, 'backfill'));
        $cmids = $this->leaderboards($course->id);
        $this->assertSame([$cmids[0], (int) $label->cmid], $this->section0($course->id));

        $this->assertSame(provisioner::STATUS_ADDED, provisioner::ensure($course->id, 'backfill'));
        $this->assertCount(1, $this->leaderboards($course->id));
    }

    public function test_dry_run_changes_nothing(): void {
        set_config('autoprovision', 0, 'leaderboard');
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame('wouldadd', provisioner::ensure($course->id, 'backfill', ['dryrun' => true]));
        $this->assertCount(0, $this->leaderboards($course->id));
        $this->assertNull(provisioner::get_marker($course->id));
    }

    public function test_existing_leaderboard_is_recorded_not_duplicated(): void {
        set_config('autoprovision', 0, 'leaderboard');
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('leaderboard', ['course' => $course->id]);

        $this->assertSame(provisioner::STATUS_EXISTING, provisioner::ensure($course->id, 'backfill'));
        $this->assertCount(1, $this->leaderboards($course->id));
    }

    public function test_deleted_leaderboard_is_not_added_again(): void {
        $course = $this->getDataGenerator()->create_course();
        [$cmid] = $this->leaderboards($course->id);

        course_delete_module($cmid);

        $this->assertSame(provisioner::STATUS_REMOVED, provisioner::get_marker($course->id)->status);
        $this->assertSame(provisioner::STATUS_REMOVED, provisioner::ensure($course->id, 'backfill'));
        $this->assertCount(0, $this->leaderboards($course->id));
    }

    public function test_restore_as_new_course_keeps_exactly_one(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->assertCount(1, $this->leaderboards($course->id));

        $newcourseid = $this->backup_and_restore($course->id, \backup::TARGET_NEW_COURSE);

        $this->assertCount(1, $this->leaderboards($newcourseid));
        $this->assertSame(provisioner::STATUS_EXISTING, provisioner::get_marker($newcourseid)->status);
    }

    public function test_restore_as_new_course_without_leaderboard_adds_one(): void {
        set_config('autoprovision', 0, 'leaderboard');
        $course = $this->getDataGenerator()->create_course();
        set_config('autoprovision', 1, 'leaderboard');

        $newcourseid = $this->backup_and_restore($course->id, \backup::TARGET_NEW_COURSE);

        $this->assertCount(1, $this->leaderboards($newcourseid));
        $this->assertSame(provisioner::STATUS_ADDED, provisioner::get_marker($newcourseid)->status);
    }

    public function test_copy_of_course_whose_leaderboard_was_deleted_stays_without(): void {
        $course = $this->getDataGenerator()->create_course();
        [$cmid] = $this->leaderboards($course->id);
        course_delete_module($cmid);

        $newcourseid = $this->backup_and_restore($course->id, \backup::TARGET_NEW_COURSE);

        $this->assertCount(0, $this->leaderboards($newcourseid));
        $this->assertSame(provisioner::STATUS_INHERITEDREMOVED, provisioner::get_marker($newcourseid)->status);
    }

    public function test_import_keeps_the_original_leaderboard(): void {
        global $DB;
        $target = $this->getDataGenerator()->create_course();
        $source = $this->getDataGenerator()->create_course();
        [$original] = $this->leaderboards($target->id);
        // The target's leaderboard has been in use for a while.
        $DB->set_field('leaderboard_provision', 'timecreated', time() - WEEKSECS, ['courseid' => $target->id]);

        $this->backup_and_restore($source->id, \backup::TARGET_CURRENT_ADDING, $target->id);

        $this->assertSame([$original], $this->leaderboards($target->id));
        $this->assertSame(provisioner::STATUS_ADDED, provisioner::get_marker($target->id)->status);
    }

    public function test_template_upload_keeps_the_templates_leaderboard(): void {
        $template = $this->getDataGenerator()->create_course();
        [$templatecm] = $this->leaderboards($template->id);
        $templatename = get_fast_modinfo($template->id)->get_cm($templatecm)->name;

        // A new course got its own leaderboard seconds ago, then the template is imported into it.
        $course = $this->getDataGenerator()->create_course();
        [$fresh] = $this->leaderboards($course->id);

        $this->backup_and_restore($template->id, \backup::TARGET_CURRENT_ADDING, $course->id);

        $cmids = $this->leaderboards($course->id);
        $this->assertCount(1, $cmids);
        $this->assertNotSame($fresh, $cmids[0]);
        $this->assertSame($templatename, get_fast_modinfo($course->id)->get_cm($cmids[0])->name);
        $marker = provisioner::get_marker($course->id);
        $this->assertSame($cmids[0], (int) $marker->cmid);
        $this->assertSame(provisioner::STATUS_EXISTING, $marker->status, 'Deleting the duplicate must not mark it removed.');
    }

    public function test_course_created_inside_a_transaction_is_handled_after_commit(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $course = $this->getDataGenerator()->create_course();
        $this->assertCount(0, $this->leaderboards($course->id), 'Nothing is added inside someone else\'s transaction.');
        $transaction->allow_commit();

        $this->expectOutputRegex('/course ' . $course->id . ': added/');
        $this->runAdhocTasks(\mod_leaderboard\task\provision_course::class);
        $this->assertCount(1, $this->leaderboards($course->id));
    }

    public function test_course_deletion_removes_the_marker(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->assertNotNull(provisioner::get_marker($course->id));
        delete_course($course, false);
        $this->assertNull(provisioner::get_marker($course->id));
    }
}
