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

/**
 * Caches calculated rankings.
 *
 * Instead of deleting cache entries every time a grade changes (a burst of grading would
 * delete them hundreds of times), events only record "this course changed at time T".
 * A cached ranking is used while it was calculated after the last change, has not
 * expired, and was calculated with the current settings.
 *
 * Some grade changes fire no event at all, for example hiding or excluding one student's
 * grade. Every change to a grade writes a grade history row, though, so a cached ranking is
 * also not used once the course has a history row newer than the ranking. On a site with
 * grade history switched off, those changes show after the cache lifetime ("cachettl").
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ranking_cache {
    /**
     * A cached ranking, if it is still good.
     *
     * @param int $cmid The leaderboard.
     * @param int $groupid The group, or 0 for everyone.
     * @param int $courseid The course.
     * @param string $settingskey The current settings.
     * @param int $now The current time.
     * @return ranking|null
     */
    public static function get(int $cmid, int $groupid, int $courseid, string $settingskey, int $now): ?ranking {
        $data = self::rankings()->get(self::key($cmid, $groupid));
        if (!is_array($data)) {
            return null;
        }
        $ranking = ranking::from_array($data);
        $changed = (int) self::changes()->get($courseid);
        if (
            $ranking->get_generated() <= $changed
                || $ranking->get_valid_until() <= $now
                || $ranking->get_settings_key() !== $settingskey
                || self::grades_changed_since($courseid, $ranking->get_generated())
        ) {
            return null;
        }
        return $ranking;
    }

    /**
     * The last ranking stored, even if it is out of date, as long as it used the current settings.
     *
     * Used only while another request is recalculating it.
     *
     * @param int $cmid The leaderboard.
     * @param int $groupid The group, or 0 for everyone.
     * @param string $settingskey The current settings.
     * @return ranking|null
     */
    public static function get_previous(int $cmid, int $groupid, string $settingskey): ?ranking {
        $data = self::rankings()->get(self::key($cmid, $groupid));
        if (!is_array($data)) {
            return null;
        }
        $ranking = ranking::from_array($data);
        return $ranking->get_settings_key() === $settingskey ? $ranking : null;
    }

    /**
     * Whether any grade in the course changed at or after a time, going by the grade history.
     *
     * @param int $courseid The course.
     * @param int $since The time.
     * @return bool
     */
    private static function grades_changed_since(int $courseid, int $since): bool {
        global $CFG, $DB;
        if (!empty($CFG->disablegradehistory)) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {grade_grades_history} h
               JOIN {grade_items} gi ON gi.id = h.itemid
              WHERE h.timemodified >= :since AND gi.courseid = :courseid",
            ['since' => $since, 'courseid' => $courseid]
        );
    }

    /**
     * Store a ranking.
     *
     * @param int $cmid The leaderboard.
     * @param int $groupid The group, or 0 for everyone.
     * @param ranking $ranking The ranking.
     */
    public static function set(int $cmid, int $groupid, ranking $ranking): void {
        self::rankings()->set(self::key($cmid, $groupid), $ranking->to_array());
    }

    /**
     * Record that something affecting a course's rankings changed now.
     *
     * @param int $courseid The course.
     */
    public static function course_changed(int $courseid): void {
        if ($courseid <= 0 || $courseid == SITEID) {
            return;
        }
        self::changes()->set($courseid, time());
    }

    /**
     * Cache key for one leaderboard and group.
     *
     * @param int $cmid The leaderboard.
     * @param int $groupid The group.
     * @return string
     */
    private static function key(int $cmid, int $groupid): string {
        return $cmid . '_' . $groupid;
    }

    /**
     * The rankings cache.
     *
     * @return \cache
     */
    private static function rankings(): \cache {
        return \cache::make('mod_leaderboard', 'rankings');
    }

    /**
     * The course changes cache.
     *
     * @return \cache
     */
    private static function changes(): \cache {
        return \cache::make('mod_leaderboard', 'coursechanged');
    }
}
