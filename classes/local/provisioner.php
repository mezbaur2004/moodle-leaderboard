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

namespace mod_leaderboard\local;

use core_course\hook\after_form_submission;

/**
 * Adds a leaderboard to the top of the General section of every course, once.
 *
 * The rules:
 *  - a course is handled at most once; the leaderboard_provision table remembers it, so a
 *    leaderboard a teacher deleted is never added back;
 *  - a course that already has a leaderboard (for example from a backup) gets no second one;
 *  - new courses are handled from the after_form_submission hook, which runs at the end of
 *    create_course() for every way of creating a course;
 *  - "restore as a new course" and course copy do not call create_course(), so they are
 *    handled from the course_restored event;
 *  - importing into a course, or uploading courses with a template, can bring a second
 *    leaderboard, which is then removed again;
 *  - existing courses are handled by cli/provision_existing.php.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioner {
    /** @var string A leaderboard was added. */
    public const STATUS_ADDED = 'added';
    /** @var string The course already had a leaderboard. */
    public const STATUS_EXISTING = 'existing';
    /** @var string The leaderboard was deleted afterwards. It is not added again. */
    public const STATUS_REMOVED = 'removed';
    /** @var string Copied from a course whose leaderboard had been deleted, so none was added. */
    public const STATUS_INHERITEDREMOVED = 'inheritedremoved';

    /** @var int How long after adding a leaderboard an imported one replaces it (template uploads). */
    private const RECENT = 600;

    /** @var bool True while this class deletes a duplicate, so the deletion is not recorded as a teacher's. */
    private static bool $deletingduplicate = false;

    /**
     * Hook callback: a course was created or updated through create_course() or update_course().
     *
     * @param after_form_submission $hook The hook.
     */
    public static function after_form_submission(after_form_submission $hook): void {
        global $DB;

        if (!$hook->isnewcourse || !self::is_enabled()) {
            return;
        }
        $courseid = (int) $hook->get_data()->id;

        // Inside someone else's transaction (for example the create courses web service) we must not
        // risk rolling it back, so the work is done right after it commits.
        if ($DB->is_transaction_started()) {
            self::queue($courseid);
            return;
        }

        try {
            self::ensure($courseid, 'create');
        } catch (\Throwable $e) {
            // No transaction was open before, so any open one is ours and can be undone safely.
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            debugging(
                'mod_leaderboard: could not add a leaderboard to course ' . $courseid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            self::queue($courseid);
        }
    }

    /**
     * Whether automatic provisioning is switched on.
     *
     * It is off while automated tests run, unless a test switches it on with the
     * leaderboard/autoprovisionintests setting, so other plugins' tests are not affected.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('leaderboard', 'autoprovision');
        if ($enabled !== false && !$enabled) {
            return false;
        }
        $testing = (defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING');
        if ($testing && !get_config('leaderboard', 'autoprovisionintests')) {
            return false;
        }
        return true;
    }

    /**
     * Make sure a course has been provisioned.
     *
     * @param int $courseid The course.
     * @param string $origin What started it: create, restore, task or backfill.
     * @param array $options 'position' => 'top' (default) or 'end'; 'visible' => bool (default true);
     *                       'dryrun' => bool (default false).
     * @return string The course's provisioning status, or 'skipped', 'disabled', 'wouldadd' or 'locked'.
     */
    public static function ensure(int $courseid, string $origin, array $options = []): string {
        global $DB;

        if ($courseid == SITEID) {
            return 'skipped';
        }
        $module = $DB->get_record('modules', ['name' => 'leaderboard']);
        if (!$module || !$module->visible) {
            return 'disabled';
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || self::is_skipped_format($course->format)) {
            return 'skipped';
        }
        if ($marker = self::get_marker($courseid)) {
            return $marker->status;
        }

        $lock = \core\lock\lock_config::get_lock_factory('mod_leaderboard_provision')->get_lock('course' . $courseid, 10);
        if (!$lock) {
            return 'locked';
        }
        try {
            if ($marker = self::get_marker($courseid)) {
                return $marker->status;
            }
            $existing = self::get_instance_cmids($courseid);
            if ($existing) {
                if (empty($options['dryrun'])) {
                    self::insert_marker($courseid, reset($existing), self::STATUS_EXISTING, $origin);
                }
                return self::STATUS_EXISTING;
            }
            if (!empty($options['dryrun'])) {
                return 'wouldadd';
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $cmid = self::add_instance($course, (int) $module->id, $options);
                self::insert_marker($courseid, $cmid, self::STATUS_ADDED, $origin);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // Undoes everything add_moduleinfo() did, then rethrows.
                $transaction->rollback($e);
            }
            return self::STATUS_ADDED;
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue provisioning for after the current request or transaction.
     *
     * @param int $courseid The course.
     */
    public static function queue(int $courseid): void {
        $task = new \mod_leaderboard\task\provision_course();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Event: a course was restored.
     *
     * @param \core\event\course_restored $event The event.
     */
    public static function course_restored(\core\event\course_restored $event): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/backup.class.php');

        $other = $event->other;
        if (($other['type'] ?? '') !== \backup::TYPE_1COURSE) {
            return;
        }
        $courseid = (int) $event->objectid;
        $target = (int) ($other['target'] ?? -1);

        if ($target === \backup::TARGET_NEW_COURSE) {
            if (!self::is_enabled()) {
                return;
            }
            // A copy of a course whose teacher deleted the leaderboard stays without one.
            if (!empty($other['samesite']) && !empty($other['originalcourseid'])) {
                $original = self::get_marker((int) $other['originalcourseid']);
                if (
                    $original && $original->status === self::STATUS_REMOVED && !self::get_instance_cmids($courseid)
                        && !self::get_marker($courseid)
                ) {
                    self::insert_marker($courseid, 0, self::STATUS_INHERITEDREMOVED, 'restore');
                    return;
                }
            }
            self::ensure($courseid, 'restore');
            return;
        }

        if ($target === \backup::TARGET_CURRENT_ADDING || $target === \backup::TARGET_EXISTING_ADDING) {
            self::remove_duplicates($courseid);
        }
    }

    /**
     * Keep one leaderboard when importing content brought a second one.
     *
     * Normally the leaderboard that was there first stays and the imported one goes. The
     * exception is a leaderboard this class added only minutes ago that nobody has edited:
     * that is the "upload courses with a template course" case, and the template's own
     * leaderboard wins.
     *
     * @param int $courseid The course.
     */
    public static function remove_duplicates(int $courseid): void {
        global $DB;

        $cmids = self::get_instance_cmids($courseid);
        if (count($cmids) < 2) {
            return;
        }
        $marker = self::get_marker($courseid);

        $keep = reset($cmids);
        if ($marker && in_array((int) $marker->cmid, $cmids, true)) {
            $keep = (int) $marker->cmid;
            if (
                $marker->status === self::STATUS_ADDED && $marker->timecreated >= time() - self::RECENT
                    && self::is_pristine((int) $marker->cmid)
            ) {
                $others = array_values(array_diff($cmids, [(int) $marker->cmid]));
                $keep = $others[0];
            }
        }

        if ($marker && (int) $marker->cmid !== $keep) {
            $marker->cmid = $keep;
            $marker->status = self::STATUS_EXISTING;
            $marker->timemodified = time();
            $DB->update_record('leaderboard_provision', $marker);
        }

        self::$deletingduplicate = true;
        try {
            foreach ($cmids as $cmid) {
                if ($cmid !== $keep) {
                    self::delete_cm($courseid, $cmid);
                }
            }
        } finally {
            self::$deletingduplicate = false;
        }
    }

    /**
     * Event: a course module was deleted. Remember when it was the provisioned leaderboard.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        if (self::$deletingduplicate || ($event->other['modulename'] ?? '') !== 'leaderboard') {
            return;
        }
        $marker = self::get_marker((int) $event->courseid);
        if ($marker && (int) $marker->cmid === (int) $event->objectid) {
            $marker->status = self::STATUS_REMOVED;
            $marker->timemodified = time();
            $DB->update_record('leaderboard_provision', $marker);
        }
    }

    /**
     * Event: a course was deleted.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('leaderboard_provision', ['courseid' => $event->objectid]);
    }

    /**
     * The provisioning record of a course.
     *
     * @param int $courseid The course.
     * @return \stdClass|null
     */
    public static function get_marker(int $courseid): ?\stdClass {
        global $DB;
        return $DB->get_record('leaderboard_provision', ['courseid' => $courseid]) ?: null;
    }

    /**
     * The default name of a provisioned leaderboard.
     *
     * @return string
     */
    public static function default_name(): string {
        $name = trim((string) get_config('leaderboard', 'autoprovisionname'));
        return $name !== '' ? $name : get_string('defaultname', 'leaderboard');
    }

    /**
     * Leaderboards in a course that are not being deleted, oldest first.
     *
     * @param int $courseid The course.
     * @return int[] Course module ids.
     */
    private static function get_instance_cmids(int $courseid): array {
        global $DB;
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid AND m.name = 'leaderboard' AND cm.deletioninprogress = 0
              ORDER BY cm.id";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Whether a leaderboard has never been edited since it was created.
     *
     * @param int $cmid The course module.
     * @return bool
     */
    private static function is_pristine(int $cmid): bool {
        global $DB;
        $sql = "SELECT l.timecreated, l.timemodified
                  FROM {leaderboard} l
                  JOIN {course_modules} cm ON cm.instance = l.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'leaderboard'
                 WHERE cm.id = :cmid";
        $record = $DB->get_record_sql($sql, ['cmid' => $cmid]);
        return $record && (int) $record->timecreated === (int) $record->timemodified;
    }

    /**
     * Whether courses in this format are left alone.
     *
     * @param string $format The course format.
     * @return bool
     */
    private static function is_skipped_format(string $format): bool {
        $skip = get_config('leaderboard', 'skipformats');
        if ($skip === false) {
            $skip = 'singleactivity,social';
        }
        return in_array($format, array_filter(explode(',', (string) $skip)), true);
    }

    /**
     * Add the leaderboard activity to section 0.
     *
     * @param \stdClass $course The course.
     * @param int $moduleid The id of the leaderboard module.
     * @param array $options See ensure().
     * @return int The new course module id.
     */
    private static function add_instance(\stdClass $course, int $moduleid, array $options): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $beforemod = null;
        if (($options['position'] ?? 'top') === 'top') {
            $sequence = (string) $DB->get_field('course_sections', 'sequence', ['course' => $course->id, 'section' => 0]);
            $first = (int) strtok($sequence, ',');
            if ($first > 0) {
                $beforemod = $first;
            }
        }

        $moduleinfo = (object) [
            'modulename' => 'leaderboard',
            'module' => $moduleid,
            'section' => 0,
            'visible' => array_key_exists('visible', $options) ? (int) (bool) $options['visible'] : 1,
            'visibleoncoursepage' => 1,
            'name' => self::default_name(),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'showdescription' => 0,
            'beforemod' => $beforemod,
        ];
        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        return (int) $moduleinfo->coursemodule;
    }

    /**
     * Write the provisioning record.
     *
     * @param int $courseid The course.
     * @param int $cmid The course module, or 0.
     * @param string $status The status.
     * @param string $origin What started it.
     */
    private static function insert_marker(int $courseid, int $cmid, string $status, string $origin): void {
        global $DB;
        $now = time();
        $DB->insert_record('leaderboard_provision', (object) [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'status' => $status,
            'origin' => $origin,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Delete a course module, using the newer API where it exists.
     *
     * @param int $courseid The course.
     * @param int $cmid The course module.
     */
    private static function delete_cm(int $courseid, int $cmid): void {
        global $CFG;
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid);
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        course_delete_module($cmid);
    }
}
