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
 *    handled from the course_restored event. A copy of a course whose leaderboard was
 *    deleted stays without one, however many times it is copied, and so does a deleted
 *    course restored on the same site;
 *  - importing or restoring into a course can bring a second leaderboard. The one brought
 *    in is removed again, unless the course's own leaderboard was added automatically in
 *    the same request (a course created from a template, or restored from the recycle
 *    bin): then the one brought in, with its settings, takes its place at the top;
 *  - a restore that first deletes the course contents also deletes the leaderboard
 *    without a deletion event; the course gets the restored one, or a new one;
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

    /** @var bool True while this class deletes a duplicate, so the deletion is not recorded as a teacher's. */
    private static bool $deletingduplicate = false;

    /** @var array courseid => list of leaderboard cmids restored into it in this request. */
    private static array $restored = [];

    /** @var int[] courseid => the leaderboard this class added to it in this request. */
    private static array $added = [];

    /**
     * Forget what happened earlier in this request. For tests that simulate a later request.
     */
    public static function reset_caches(): void {
        self::$restored = [];
        self::$added = [];
    }

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
        $marker = self::get_marker($courseid);
        if ($marker && !self::is_stale($marker)) {
            return $marker->status;
        }

        $lock = \core\lock\lock_config::get_lock_factory('mod_leaderboard_provision')->get_lock('course' . $courseid, 10);
        if (!$lock) {
            return 'locked';
        }
        try {
            $marker = self::get_marker($courseid);
            if ($marker && !self::is_stale($marker)) {
                return $marker->status;
            }
            $dryrun = !empty($options['dryrun']);
            $existing = self::get_instance_cmids($courseid);
            if ($existing) {
                if (!$dryrun) {
                    if ($marker) {
                        self::update_marker($marker, reset($existing), self::STATUS_EXISTING);
                    } else {
                        self::insert_marker($courseid, reset($existing), self::STATUS_EXISTING, $origin);
                    }
                }
                return self::STATUS_EXISTING;
            }
            if ($dryrun) {
                return 'wouldadd';
            }

            $wasintransaction = $DB->is_transaction_started();
            $transaction = $DB->start_delegated_transaction();
            try {
                $cmid = self::add_instance($course, (int) $module->id, $options);
                if ($marker) {
                    // The marker of a leaderboard that disappeared without a deletion event.
                    $DB->delete_records('leaderboard_provision', ['id' => $marker->id]);
                }
                self::insert_marker($courseid, $cmid, self::STATUS_ADDED, $origin);
                $transaction->allow_commit();
                self::$added[$courseid] = $cmid;
            } catch (\Throwable $e) {
                if (!$wasintransaction && $DB->is_transaction_started()) {
                    // When add_moduleinfo() fails it leaves its own inner transaction open, and then
                    // rolling back ours would only mark it for rollback. Nothing was open before we
                    // started, so undo everything now and leave the connection usable.
                    $DB->force_transaction_rollback();
                    throw $e;
                }
                $transaction->rollback($e);
            }
            return self::STATUS_ADDED;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a marker points at a leaderboard that is gone although nobody deleted it.
     *
     * That happens when a restore deletes the course contents first: core removes every
     * activity directly, without a course_module_deleted event. A leaderboard that is only
     * being deleted in the background still has its course module, so it is not stale.
     *
     * @param \stdClass $marker The marker.
     * @return bool
     */
    private static function is_stale(\stdClass $marker): bool {
        global $DB;
        if (!in_array($marker->status, [self::STATUS_ADDED, self::STATUS_EXISTING], true) || empty($marker->cmid)) {
            return false;
        }
        return !$DB->record_exists('course_modules', ['id' => $marker->cmid, 'course' => $marker->courseid]);
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
     * Remember a leaderboard restored in this request (called by the restore step), so the
     * course_restored event knows which leaderboards the restore brought.
     *
     * @param int $courseid The course.
     * @param int $cmid The restored course module.
     */
    public static function note_restored(int $courseid, int $cmid): void {
        self::$restored[$courseid][] = $cmid;
    }

    /**
     * Event: a course was restored.
     *
     * @param \core\event\course_restored $event The event.
     */
    public static function course_restored(\core\event\course_restored $event): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/backup.class.php');

        $courseid = (int) $event->objectid;
        $restored = self::$restored[$courseid] ?? [];
        unset(self::$restored[$courseid]);

        $other = $event->other;
        if (($other['type'] ?? '') !== \backup::TYPE_1COURSE) {
            return;
        }
        $target = (int) ($other['target'] ?? -1);

        if ($target === \backup::TARGET_NEW_COURSE) {
            if (!self::is_enabled()) {
                return;
            }
            // A copy of a course whose teacher deleted the leaderboard stays without one, and so do
            // copies of that copy, and the course itself when it comes back from the recycle bin.
            if (!empty($other['samesite']) && !empty($other['originalcourseid']) && !$restored) {
                $original = self::get_marker((int) $other['originalcourseid']);
                $optedout = [self::STATUS_REMOVED, self::STATUS_INHERITEDREMOVED];
                if ($original && in_array($original->status, $optedout, true)) {
                    self::inherit_removal($courseid);
                    return;
                }
            }
            // Restoring from the recycle bin creates the course first, which already added a leaderboard.
            self::remove_duplicates($courseid, $restored);
            self::ensure($courseid, 'restore');
            return;
        }

        if ($target === \backup::TARGET_CURRENT_ADDING || $target === \backup::TARGET_EXISTING_ADDING) {
            self::remove_duplicates($courseid, $restored);
            return;
        }

        if ($target === \backup::TARGET_CURRENT_DELETING || $target === \backup::TARGET_EXISTING_DELETING) {
            // The restore deleted the leaderboard with the rest of the contents. Point the marker at
            // the restored leaderboard, or add a new one. Courses never provisioned are left alone.
            if (self::is_enabled() && self::get_marker($courseid)) {
                self::ensure($courseid, 'restore');
            }
        }
    }

    /**
     * Keep one leaderboard when a restore or import brought another.
     *
     * Only leaderboards the restore brought are removed; others already in the course stay.
     * Normally the course's own leaderboard stays and the restored one goes. When the
     * course's own one was added automatically earlier in the same request and not edited
     * since (a course created from a template, or restored from the recycle bin), the
     * restored one takes its place.
     *
     * @param int $courseid The course.
     * @param int[] $restored Course modules the restore brought.
     */
    public static function remove_duplicates(int $courseid, array $restored): void {
        $cmids = self::get_instance_cmids($courseid);
        $restored = array_values(array_intersect($restored, $cmids));
        $previous = array_values(array_diff($cmids, $restored));
        if (!$restored || !$previous) {
            return;
        }

        $marker = self::get_marker($courseid);
        $replace = $marker && $marker->status === self::STATUS_ADDED && count($previous) === 1
            && (int) $marker->cmid === $previous[0] && (self::$added[$courseid] ?? 0) === $previous[0]
            && self::is_pristine($previous[0]);

        if (!$replace) {
            foreach ($restored as $cmid) {
                self::delete_duplicate($courseid, $cmid);
            }
            return;
        }

        $keep = $restored[0];
        self::move_before($courseid, $keep, $previous[0]);
        self::update_marker($marker, $keep, self::STATUS_EXISTING);
        foreach (array_merge($previous, array_slice($restored, 1)) as $cmid) {
            self::delete_duplicate($courseid, $cmid);
        }
    }

    /**
     * A new course copied or restored from one whose teacher deleted the leaderboard: no leaderboard.
     *
     * @param int $courseid The course.
     */
    private static function inherit_removal(int $courseid): void {
        $marker = self::get_marker($courseid);
        if ($marker && $marker->status === self::STATUS_ADDED && (self::$added[$courseid] ?? 0) === (int) $marker->cmid) {
            // The recycle bin created the course first, which added a default leaderboard.
            $cmids = self::get_instance_cmids($courseid);
            if (in_array((int) $marker->cmid, $cmids, true) && self::is_pristine((int) $marker->cmid)) {
                self::delete_duplicate($courseid, (int) $marker->cmid);
            }
        }
        if (self::get_instance_cmids($courseid)) {
            return;
        }
        if ($marker) {
            self::update_marker($marker, 0, self::STATUS_INHERITEDREMOVED);
        } else {
            self::insert_marker($courseid, 0, self::STATUS_INHERITEDREMOVED, 'restore');
        }
    }

    /**
     * Event: a course module was deleted. When it was the course's leaderboard, remember that
     * the teacher does not want one, so it is never added back.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (self::$deletingduplicate || ($event->other['modulename'] ?? '') !== 'leaderboard') {
            return;
        }
        $courseid = (int) $event->courseid;
        $cmid = (int) $event->objectid;
        $remaining = self::get_instance_cmids($courseid);
        $marker = self::get_marker($courseid);
        if (!$marker) {
            // Deleted before the course was ever provisioned (for example before the backfill ran).
            if (!$remaining) {
                self::insert_marker($courseid, $cmid, self::STATUS_REMOVED, 'delete');
            }
            return;
        }
        if ((int) $marker->cmid !== $cmid) {
            return;
        }
        if ($remaining) {
            self::update_marker($marker, reset($remaining), self::STATUS_EXISTING);
        } else {
            self::update_marker($marker, $cmid, self::STATUS_REMOVED);
        }
    }

    /**
     * Event: a course was deleted.
     *
     * A teacher's choice not to have a leaderboard is kept, so the course stays without one
     * when it is restored on this site from a backup or from the recycle bin. Course ids are
     * never reused, and the record holds no personal data.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        [$statussql, $params] = $DB->get_in_or_equal(
            [self::STATUS_REMOVED, self::STATUS_INHERITEDREMOVED],
            SQL_PARAMS_NAMED,
            'lbstatus',
            false
        );
        $params['courseid'] = $event->objectid;
        $DB->delete_records_select('leaderboard_provision', "courseid = :courseid AND status $statussql", $params);
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
            // The course defaults, as the "add an activity" form uses them.
            'groupmode' => (int) $course->groupmode,
            'groupingid' => (int) $course->defaultgroupingid,
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
     * Change the provisioning record.
     *
     * @param \stdClass $marker The record.
     * @param int $cmid The course module, or 0.
     * @param string $status The status.
     */
    private static function update_marker(\stdClass $marker, int $cmid, string $status): void {
        global $DB;
        $marker->cmid = $cmid;
        $marker->status = $status;
        $marker->timemodified = time();
        $DB->update_record('leaderboard_provision', $marker);
    }

    /**
     * Delete a leaderboard this class does not want, without recording it as a teacher's
     * deletion and without putting it in the course recycle bin (restoring it from there
     * would bring the duplicate back).
     *
     * @param int $courseid The course.
     * @param int $cmid The course module.
     */
    private static function delete_duplicate(int $courseid, int $cmid): void {
        global $CFG;
        $hadforced = isset($CFG->forced_plugin_settings['tool_recyclebin']);
        $forced = $CFG->forced_plugin_settings['tool_recyclebin'] ?? [];
        $CFG->forced_plugin_settings['tool_recyclebin']['coursebinenable'] = 0;
        self::$deletingduplicate = true;
        try {
            self::delete_cm($courseid, $cmid);
        } finally {
            self::$deletingduplicate = false;
            if ($hadforced) {
                $CFG->forced_plugin_settings['tool_recyclebin'] = $forced;
            } else {
                unset($CFG->forced_plugin_settings['tool_recyclebin']);
            }
        }
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

    /**
     * Move a course module to just before another one, using the newer API where it exists.
     *
     * @param int $courseid The course.
     * @param int $cmid The course module to move.
     * @param int $beforecmid The course module to put it before.
     */
    private static function move_before(int $courseid, int $cmid, int $beforecmid): void {
        global $CFG, $DB;
        if (method_exists(\core_courseformat\local\cmactions::class, 'move_before')) {
            \core_courseformat\formatactions::cm($courseid)->move_before($cmid, $beforecmid);
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        $cm = get_coursemodule_from_id('leaderboard', $cmid, $courseid, false, MUST_EXIST);
        $beforecm = $DB->get_record('course_modules', ['id' => $beforecmid, 'course' => $courseid], 'id, section', MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $beforecm->section], '*', MUST_EXIST);
        moveto_module($cm, $section, $beforecmid);
        rebuild_course_cache($courseid, true);
    }
}
