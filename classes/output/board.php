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

namespace mod_leaderboard\output;

use mod_leaderboard\api;
use mod_leaderboard\local\points_settings;
use mod_leaderboard\local\ranking;

/**
 * Turns a ranking into data for the leaderboard templates, for the current user.
 *
 * This is where names are added and where the site's identity mode is applied:
 *  - "fullname": everyone sees names;
 *  - "anonymous": students see "Anonymous student" instead of other students' names, and
 *    the board is hidden while fewer students than the minimum are ranked. Users with
 *    mod/leaderboard:viewnames (teachers) still see names.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class board {
    /** @var \cm_info The leaderboard. */
    private \cm_info $cm;

    /** @var bool Whether other students' names are hidden from the current user. */
    private bool $anonymous;

    /** @var int Minimum number of ranked students before an anonymous board is shown. */
    private int $mincohort;

    /**
     * Constructor.
     *
     * @param \cm_info $cm The leaderboard.
     */
    public function __construct(\cm_info $cm) {
        $this->cm = $cm;
        $mode = get_config('leaderboard', 'identitymode') ?: 'fullname';
        $this->anonymous = $mode === 'anonymous' && !has_capability('mod/leaderboard:viewnames', $cm->context);
        $mincohort = get_config('leaderboard', 'mincohort');
        $this->mincohort = $mincohort === false ? 5 : max(0, (int) $mincohort);
    }

    /**
     * Data for the short summary on the course page (template mod_leaderboard/summary).
     *
     * @param int $limit How many top students to show.
     * @return array
     */
    public function export_summary(int $limit): array {
        global $USER;

        ['ranking' => $ranking] = api::get_viewer_ranking($this->cm);
        $data = $this->export_common($ranking);
        if (!$data['showboard']) {
            return $data;
        }

        $data['rows'] = $this->export_rows($ranking->get_top($limit), false);
        $me = $ranking->get_entry((int) $USER->id);
        $data['me'] = $me ? $this->export_me($me, $ranking) : null;
        return $data;
    }

    /**
     * Data for the full leaderboard page (template mod_leaderboard/board).
     *
     * @param int $topn How many top students to show; 0 for everyone.
     * @param bool $pictures Whether to include user pictures (the app does not show them).
     * @return array
     */
    public function export_full(int $topn, bool $pictures = true): array {
        global $USER;

        ['ranking' => $ranking] = api::get_viewer_ranking($this->cm, true);
        $data = $this->export_common($ranking);
        if (!$data['showboard']) {
            return $data;
        }

        $top = $ranking->get_top($topn);
        $data['rows'] = $this->export_rows($top, $pictures);

        $me = $ranking->get_entry((int) $USER->id);
        $data['me'] = $me ? $this->export_me($me, $ranking) : null;
        $inlist = false;
        foreach ($top as $entry) {
            if ($me && $entry['userid'] === $me['userid']) {
                $inlist = true;
            }
        }
        $data['meoutside'] = $me && !$inlist;
        if ($data['meoutside']) {
            $data['merow'] = $this->export_rows([$me], $pictures)[0];
        }
        $data['showall'] = $topn === 0 || $topn >= $ranking->count();
        $data['shown'] = count($top);
        $data['rules'] = $this->export_rules();
        return $data;
    }

    /**
     * Data for one user's breakdown (template mod_leaderboard/breakdown).
     *
     * @param int $userid The user.
     * @return array
     */
    public function export_breakdown(int $userid): array {
        global $DB, $USER;

        $lines = api::get_breakdown($this->cm, $userid);
        $rows = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['points'];
            $url = null;
            if ($line['cmid']) {
                $url = (new \moodle_url('/mod/' . $line['modname'] . '/view.php', ['id' => $line['cmid']]))->out(false);
            }
            $rows[] = [
                'name' => format_string($line['name'], true, ['context' => $this->cm->context]),
                'url' => $url,
                'type' => get_string('modulename', $line['modname']),
                'mark' => self::format_number($line['mark']),
                'grademax' => self::format_number($line['grademax']),
                'permark' => self::format_number($line['permark']),
                'late' => (bool) $line['late'],
                'points' => self::format_number($line['points']),
            ];
        }

        $isme = $userid === (int) $USER->id;
        $title = get_string('yourpoints', 'leaderboard');
        if (!$isme) {
            $user = $DB->get_record('user', ['id' => $userid], implode(',', \core_user\fields::get_name_fields()) . ', id');
            $title = get_string('pointsof', 'leaderboard', $user ? fullname($user) : '');
        }
        return [
            'title' => $title,
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'total' => self::format_number(round($total)),
        ];
    }

    /**
     * Whether the current user may see a given user's breakdown.
     *
     * Anyone may see their own. Another user's needs mod/leaderboard:viewallbreakdowns, and
     * that user must be someone this leaderboard ranks and, in separate groups mode, share a
     * group with the viewer (unless the viewer can access all groups), as in the grader report.
     *
     * @param int $userid The user.
     * @return bool
     */
    public function can_view_breakdown(int $userid): bool {
        global $USER;
        if ($userid === (int) $USER->id) {
            return true;
        }
        $context = $this->cm->context;
        if (!has_capability('mod/leaderboard:viewallbreakdowns', $context)) {
            return false;
        }
        if (!api::get_builder($this->cm)->is_ranked_user($userid)) {
            return false;
        }
        if (
            groups_get_activity_groupmode($this->cm) != SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $context)
        ) {
            return true;
        }
        foreach (groups_get_activity_allowed_groups($this->cm) as $group) {
            if (groups_is_member($group->id, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Data shared by the summary and the full page.
     *
     * @param ranking|null $ranking The ranking, or null when the user has no group.
     * @return array
     */
    private function export_common(?ranking $ranking): array {
        $data = [
            'cmid' => $this->cm->id,
            'name' => format_string($this->cm->name, true, ['context' => $this->cm->context]),
            'url' => (new \moodle_url('/mod/leaderboard/view.php', ['id' => $this->cm->id]))->out(false),
            'nogroup' => $ranking === null,
            'empty' => false,
            'toofew' => false,
            'showboard' => false,
            'rows' => [],
            'me' => null,
            'total' => 0,
            'updated' => '',
        ];
        if ($ranking === null) {
            return $data;
        }
        $data['total'] = $ranking->count();
        $data['updated'] = userdate($ranking->get_generated(), get_string('strftimedatetimeshort', 'core_langconfig'));
        if ($ranking->count() === 0) {
            $data['empty'] = true;
            return $data;
        }
        if ($this->anonymous && $ranking->count() < $this->mincohort) {
            $data['toofew'] = true;
            return $data;
        }
        $data['showboard'] = true;
        return $data;
    }

    /**
     * Rows for a list of entries, with names (or anonymous labels) and pictures.
     *
     * @param array $entries Ranking entries.
     * @param bool $pictures Whether to include user pictures.
     * @return array
     */
    private function export_rows(array $entries, bool $pictures): array {
        global $DB, $OUTPUT, $USER;

        $userids = array_column($entries, 'userid');
        $users = [];
        if ($userids) {
            $fields = \core_user\fields::for_userpic()->get_sql('u', false, '', '', false)->selects;
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lbrow');
            $users = $DB->get_records_sql("SELECT $fields FROM {user} u WHERE u.id $insql", $params);
        }
        $viewfullnames = has_capability('moodle/site:viewfullnames', $this->cm->context);
        $canviewall = has_capability('mod/leaderboard:viewallbreakdowns', $this->cm->context);

        $rows = [];
        foreach ($entries as $entry) {
            $isme = $entry['userid'] === (int) $USER->id;
            $user = $users[$entry['userid']] ?? null;
            $hidename = $this->anonymous && !$isme;

            $row = [
                'rank' => $entry['rank'],
                'points' => self::format_number($entry['points']),
                'isme' => $isme,
                'istop' => $entry['rank'] <= 3,
                'medal' => $entry['rank'] <= 3 ? get_string('medal' . $entry['rank'], 'leaderboard') : '',
                'name' => $hidename || !$user ? get_string('anonymousstudent', 'leaderboard') : fullname($user, $viewfullnames),
                'picture' => '',
                'breakdownurl' => null,
            ];
            if ($pictures && !$hidename && $user) {
                $row['picture'] = $OUTPUT->user_picture($user, ['size' => 35, 'courseid' => $this->cm->course, 'link' => false,
                    'alttext' => false]);
            }
            if ($isme || $canviewall) {
                $row['breakdownurl'] = (new \moodle_url(
                    '/mod/leaderboard/view.php',
                    ['id' => $this->cm->id, 'userid' => $entry['userid']]
                ))->out(false) . '#leaderboard-breakdown';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The current user's own place.
     *
     * @param array $entry The user's ranking entry.
     * @param ranking $ranking The ranking.
     * @return array
     */
    private function export_me(array $entry, ranking $ranking): array {
        return [
            'rank' => $entry['rank'],
            'points' => self::format_number($entry['points']),
            'total' => $ranking->count(),
            'text' => get_string('yourplace', 'leaderboard', (object) [
                'rank' => $entry['rank'],
                'total' => $ranking->count(),
                'points' => self::format_number($entry['points']),
            ]),
        ];
    }

    /**
     * How points are earned with the current settings, for the "how it works" box.
     *
     * @return array
     */
    private function export_rules(): array {
        $settings = points_settings::for_instance(api::get_record($this->cm));
        $rules = [];
        foreach (points_settings::FIELDS as $field => $unused) {
            $value = $settings->get($field);
            if ($field === 'otherpoints' && $value <= 0) {
                continue;
            }
            // Only the Zoom rate that applies.
            if ($field === ($settings->uses_zoom_attendance() ? 'zoompoints' : 'zoomattendpoints')) {
                continue;
            }
            $rules[] = [
                'label' => get_string($field . '_rule', 'leaderboard'),
                'value' => get_string(
                    $field === 'zoomattendpoints' ? 'pointsperpercent' : 'pointspermark',
                    'leaderboard',
                    self::format_number($value)
                ),
            ];
        }
        return $rules;
    }

    /**
     * Format a number for display, without needless decimals.
     *
     * @param float|int $value The number.
     * @return string
     */
    public static function format_number($value): string {
        return format_float((float) $value, 2, true, true);
    }
}
