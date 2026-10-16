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

use mod_leaderboard\local\source\gradebook_source;
use mod_leaderboard\local\source\result;
use mod_leaderboard\local\source\source;

/**
 * Calculates rankings and point breakdowns for one leaderboard.
 *
 * Who is ranked: users actively enrolled in the course (not suspended, enrolment not
 * expired) who hold mod/leaderboard:beranked in the leaderboard's context. By default
 * that is the student role. In a group, only that group's members are ranked.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ranking_builder {
    /** @var \cm_info The leaderboard course module. */
    private \cm_info $cm;

    /** @var \stdClass The leaderboard record. */
    private \stdClass $leaderboard;

    /** @var points_settings Points per mark. */
    private points_settings $settings;

    /**
     * Constructor.
     *
     * @param \cm_info $cm The leaderboard course module.
     * @param \stdClass $leaderboard The leaderboard record.
     */
    public function __construct(\cm_info $cm, \stdClass $leaderboard) {
        $this->cm = $cm;
        $this->leaderboard = $leaderboard;
        $this->settings = points_settings::for_instance($leaderboard);
    }

    /**
     * The points settings in use.
     *
     * @return points_settings
     */
    public function get_settings(): points_settings {
        return $this->settings;
    }

    /**
     * Identifies the settings, so a change of settings makes cached rankings invalid.
     *
     * @return string
     */
    public function get_settings_key(): string {
        return sha1(json_encode([
            $this->settings->to_array(),
            (int) $this->leaderboard->timemodified,
        ]));
    }

    /**
     * The ranking for a group, from the cache when possible.
     *
     * @param int $groupid The group, or 0 for all participants.
     * @return ranking
     */
    public function get_ranking(int $groupid): ranking {
        $now = time();
        $key = $this->get_settings_key();
        $cached = ranking_cache::get($this->cm->id, $groupid, $this->cm->course, $key, $now);
        if ($cached) {
            return $cached;
        }
        $ranking = $this->calculate($groupid, $now);
        ranking_cache::set($this->cm->id, $groupid, $ranking);
        return $ranking;
    }

    /**
     * Calculate a ranking without using the cache.
     *
     * @param int $groupid The group, or 0 for all participants.
     * @param int $now The time to evaluate visibility at.
     * @return ranking
     */
    public function calculate(int $groupid, int $now): ranking {
        global $DB;

        [$usersql, $userparams] = $this->get_ranked_users_sql($groupid);
        $users = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname FROM {user} u WHERE u.id IN ($usersql)",
            $userparams
        );

        $result = $this->collect($usersql, $userparams, $now);
        $totals = $result->get_totals();

        $points = [];
        $sortnames = [];
        foreach ($users as $user) {
            $points[(int) $user->id] = (int) round($totals[$user->id] ?? 0.0);
            $sortnames[(int) $user->id] = $user->lastname . ' ' . $user->firstname;
        }

        $ttl = max(30, (int) (get_config('leaderboard', 'cachettl') ?: 300));
        $validuntil = $now + $ttl;
        $nextchange = $result->get_next_change();
        if ($nextchange !== null && $nextchange < $validuntil) {
            $validuntil = $nextchange;
        }

        return new ranking(ranking::rank($points, $sortnames), $now, $validuntil, $this->get_settings_key());
    }

    /**
     * How one user's points were calculated.
     *
     * @param int $userid The user.
     * @return array List of lines, see result::add_line().
     */
    public function get_breakdown(int $userid): array {
        $usersql = 'SELECT lbbu.id FROM {user} lbbu WHERE lbbu.id = :lbbreakdownuser';
        return $this->collect($usersql, ['lbbreakdownuser' => $userid], time())->get_lines($userid);
    }

    /**
     * Whether a user is someone the leaderboard ranks.
     *
     * @param int $userid The user.
     * @return bool
     */
    public function is_ranked_user(int $userid): bool {
        global $DB;
        [$usersql, $userparams] = $this->get_ranked_users_sql(0);
        return $DB->record_exists_sql(
            "SELECT 1 FROM {user} WHERE id = :lbcheckuser AND id IN ($usersql)",
            $userparams + ['lbcheckuser' => $userid]
        );
    }

    /**
     * SQL returning the ids of the users this leaderboard ranks.
     *
     * @param int $groupid The group, or 0 for all participants.
     * @return array [sql, params]
     */
    public function get_ranked_users_sql(int $groupid): array {
        $context = \context_module::instance($this->cm->id);
        [$esql, $eparams] = get_enrolled_sql($context, 'mod/leaderboard:beranked', $groupid, true);
        $sql = "SELECT lbu.id
                  FROM {user} lbu
                  JOIN ($esql) lbje ON lbje.id = lbu.id
                 WHERE lbu.deleted = 0 AND lbu.suspended = 0";
        return [$sql, $eparams];
    }

    /**
     * Collect point lines from every source.
     *
     * @param string $usersql SQL returning user ids.
     * @param array $userparams Its parameters.
     * @param int $now The time to evaluate visibility at.
     * @return result
     */
    private function collect(string $usersql, array $userparams, int $now): result {
        $result = new result();
        foreach ($this->get_sources() as $source) {
            $result->merge($source->collect((int) $this->cm->course, $usersql, $userparams, $this->settings, $now));
        }
        return $result;
    }

    /**
     * The point sources in use.
     *
     * Version 1 reads everything from the gradebook. A later version can add a Zoom
     * attendance source here and pass ['zoom'] to the gradebook source to exclude it.
     *
     * @return source[]
     */
    private function get_sources(): array {
        return [new gradebook_source()];
    }
}
