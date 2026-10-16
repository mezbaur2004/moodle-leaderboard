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

use core_availability\info_module;
use core_availability\info_section;

/**
 * Whether a student may see the grade of an activity that has access restrictions.
 *
 * The user grade report hides a grade from a student who cannot access the activity when
 * the restriction hides the activity entirely, instead of showing it greyed out with the
 * reason. A restriction on the activity's section (or on a subsection it sits in) has the
 * same effect. Those grades are left out here as well.
 *
 * Only activities with a restriction on themselves or on a section around them are
 * checked, so the others cost nothing. A restriction made only of conditions core can
 * check for a whole list of users at once (group, grouping, user profile) is checked that
 * way; a student it excludes does not get the grade counted, even when the restriction
 * shows the activity greyed out (such a student normally has no grade there anyway).
 * Other restrictions (dates, completion, grades) are checked one student at a time, the
 * way core checks them; those conditions are cheap, and the result is cached with the
 * ranking.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_access {
    /** @var \course_modinfo|null Course information shared by all checks. */
    private ?\course_modinfo $modinfo = null;

    /** @var array cmid => list of cm_info and section_info objects whose restrictions apply, innermost first. */
    private array $chains = [];

    /** @var array cmid => list of the activities and sections whose restriction uses groupings. */
    private array $usegroupings = [];

    /** @var info_module[]|info_section[] Availability helpers, by key. */
    private array $infos = [];

    /** @var bool[] Helper key => whether every condition can be checked for a list of users at once. */
    private array $listonly = [];

    /** @var array Helper key => [userid => true] for users a list-only restriction lets through. */
    private array $allowed = [];

    /** @var array cmid => [userid => true] for the users whose grades will be checked. */
    private array $users = [];

    /** @var bool[] "cmid_userid" => whether the grade is hidden. */
    private array $hidden = [];

    /** @var int|null The user $usermodinfo belongs to. */
    private ?int $usermodinfouser = null;

    /** @var \course_modinfo|null Course information for one student, for grouping restrictions. */
    private ?\course_modinfo $usermodinfo = null;

    /**
     * Constructor.
     *
     * @param int $courseid The course.
     * @param int[] $cmids The activities whose grades may count.
     * @param int $now The current time.
     * @param result $result Collects the next time a date restriction changes.
     */
    public function __construct(int $courseid, array $cmids, int $now, result $result) {
        global $CFG;

        if (empty($CFG->enableavailability) || !$cmids) {
            return;
        }
        $this->modinfo = get_fast_modinfo($courseid);
        $cms = $this->modinfo->get_cms();
        foreach ($cmids as $cmid) {
            if (!isset($cms[$cmid])) {
                continue;
            }
            $chain = $this->get_chain($cms[$cmid]);
            if (!$chain) {
                continue;
            }
            $this->chains[$cmid] = $chain;
            foreach ($chain as $object) {
                $usesgroupings = false;
                self::scan_tree(json_decode((string) $object->availability), $now, $result, $usesgroupings);
                if ($usesgroupings) {
                    $this->usegroupings[$cmid][] = $object;
                }
            }
        }
    }

    /**
     * Whether an activity has restrictions, so its grades must be checked one student at a time.
     *
     * @param int $cmid The activity.
     * @return bool
     */
    public function is_restricted(int $cmid): bool {
        return isset($this->chains[$cmid]);
    }

    /**
     * Say which students will be checked for each activity, so list-only restrictions can be
     * checked for all of them at once.
     *
     * @param array $userids cmid => list of user ids.
     */
    public function preload(array $userids): void {
        foreach ($userids as $cmid => $ids) {
            foreach ($ids as $userid) {
                $this->users[$cmid][(int) $userid] = true;
            }
        }
    }

    /**
     * Whether a student's grade for an activity is hidden from them by its restrictions.
     *
     * @param int $cmid The activity.
     * @param int $userid The student.
     * @return bool
     */
    public function is_hidden_from(int $cmid, int $userid): bool {
        if (!isset($this->chains[$cmid])) {
            return false;
        }
        $key = $cmid . '_' . $userid;
        if (!isset($this->hidden[$key])) {
            $this->hidden[$key] = $this->needs_user_modinfo($cmid)
                ? $this->is_hidden_by_user_modinfo($cmid, $userid)
                : $this->is_hidden_by_chain($cmid, $userid);
        }
        return $this->hidden[$key];
    }

    /**
     * Whether a grouping condition is mixed with conditions that must be checked one student
     * at a time. Core checks grouping conditions with the student's own course information,
     * so that case is checked through it.
     *
     * @param int $cmid The activity.
     * @return bool
     */
    private function needs_user_modinfo(int $cmid): bool {
        foreach ($this->usegroupings[$cmid] ?? [] as $object) {
            if (!$this->is_list_only(self::key($object), $this->get_info($object))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check the restrictions of the activity and its sections, like cm_info does for its user.
     *
     * @param int $cmid The activity.
     * @param int $userid The student.
     * @return bool
     */
    private function is_hidden_by_chain(int $cmid, int $userid): bool {
        foreach ($this->chains[$cmid] as $object) {
            $key = self::key($object);
            $info = $this->get_info($object);
            if ($this->is_list_only($key, $info)) {
                if (!isset($this->allowed[$key])) {
                    $users = [];
                    foreach (array_keys($this->users[$cmid] ?? [$userid => true]) as $id) {
                        $users[$id] = (object) ['id' => $id];
                    }
                    $users[$userid] = (object) ['id' => $userid];
                    $this->allowed[$key] = array_fill_keys(array_keys($info->filter_user_list($users)), true);
                }
                if (!isset($this->allowed[$key][$userid])) {
                    return true;
                }
                continue;
            }
            $information = '';
            if ($info->is_available($information, true, $userid, $this->modinfo)) {
                continue;
            }
            // The activity's own restriction may show it greyed out with the reason, and then the
            // grade report still shows the grade. A restriction on a section around it leaves the
            // activity without a reason, so the grade is hidden.
            $isactivity = $object instanceof \cm_info && (int) $object->id === $cmid;
            return !$isactivity || empty($information);
        }
        return false;
    }

    /**
     * Check through the student's own course information, as the grade report does.
     *
     * @param int $cmid The activity.
     * @param int $userid The student.
     * @return bool
     */
    private function is_hidden_by_user_modinfo(int $cmid, int $userid): bool {
        if ($this->usermodinfouser !== $userid) {
            // Built directly, so the current user's course information in get_fast_modinfo() stays cached.
            $this->usermodinfo = new \course_modinfo($this->modinfo->get_course(), $userid);
            $this->usermodinfouser = $userid;
        }
        $cm = $this->usermodinfo->get_cm($cmid);
        return !$cm->uservisible && empty($cm->availableinfo);
    }

    /**
     * The activity and the sections around it that have restrictions, innermost first.
     *
     * @param \cm_info $cm The activity.
     * @return array List of cm_info and section_info objects.
     */
    private function get_chain(\cm_info $cm): array {
        $chain = [];
        if ($cm->availability !== null) {
            $chain[] = $cm;
        }
        $section = $cm->get_section_info();
        for ($depth = 0; $section && $depth < 10; $depth++) {
            if ($section->availability !== null) {
                $chain[] = $section;
            }
            if (!$section->is_delegated()) {
                break;
            }
            // A subsection: its own activity, and the section that activity is in, apply too.
            $delegate = $section->get_component_instance();
            if (!$delegate instanceof \core_courseformat\sectiondelegatemodule) {
                break;
            }
            $parent = $delegate->get_cm();
            if ($parent->availability !== null) {
                $chain[] = $parent;
            }
            $section = $parent->get_section_info();
        }
        return $chain;
    }

    /**
     * The availability helper for an activity or a section.
     *
     * @param \cm_info|\section_info $object The activity or section.
     * @return info_module|info_section
     */
    private function get_info($object) {
        $key = self::key($object);
        if (!isset($this->infos[$key])) {
            $this->infos[$key] = $object instanceof \cm_info ? new info_module($object) : new info_section($object);
        }
        return $this->infos[$key];
    }

    /**
     * A key for an activity or a section.
     *
     * @param \cm_info|\section_info $object The activity or section.
     * @return string
     */
    private static function key($object): string {
        return ($object instanceof \cm_info ? 'cm' : 's') . $object->id;
    }

    /**
     * Whether every condition of a restriction can be checked for a list of users at once.
     *
     * @param string $key The helper key.
     * @param info_module|info_section $info The helper.
     * @return bool
     */
    private function is_list_only(string $key, $info): bool {
        if (!isset($this->listonly[$key])) {
            $listonly = true;
            try {
                foreach ($info->get_availability_tree()->get_all_children(\core_availability\condition::class) as $condition) {
                    if (!$condition->is_applied_to_user_lists()) {
                        $listonly = false;
                        break;
                    }
                }
            } catch (\coding_exception $e) {
                // An invalid restriction: let the one-by-one check deal with it as core does.
                $listonly = false;
            }
            $this->listonly[$key] = $listonly;
        }
        return $this->listonly[$key];
    }

    /**
     * Find future dates where a restriction changes, and whether it uses groupings.
     *
     * @param mixed $node A node of the decoded availability tree.
     * @param int $now The current time.
     * @param result $result Collects the dates.
     * @param bool $usesgroupings Set to true when a grouping condition is found.
     */
    private static function scan_tree($node, int $now, result $result, bool &$usesgroupings): void {
        if (!is_object($node)) {
            return;
        }
        if (isset($node->c) && is_array($node->c)) {
            foreach ($node->c as $child) {
                self::scan_tree($child, $now, $result, $usesgroupings);
            }
            return;
        }
        $type = $node->type ?? '';
        if ($type === 'date' && isset($node->t) && (int) $node->t > $now) {
            $result->change_at((int) $node->t);
        } else if ($type === 'grouping') {
            $usesgroupings = true;
        }
    }
}
