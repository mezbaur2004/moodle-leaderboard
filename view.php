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

/**
 * The full leaderboard page.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'leaderboard');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/leaderboard:view', $context);

$leaderboard = \mod_leaderboard\api::get_record($cm);

$event = \mod_leaderboard\event\course_module_viewed::create([
    'objectid' => $cm->instance,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('leaderboard', $leaderboard);
$event->trigger();

$url = new moodle_url('/mod/leaderboard/view.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->add_body_class('mod-leaderboard-page');

$board = new \mod_leaderboard\output\board($cm);
$renderer = $PAGE->get_renderer('mod_leaderboard');

echo $OUTPUT->header();

echo groups_print_activity_menu($cm, $url, true);

echo $renderer->render_from_template('mod_leaderboard/board', $board->export_full((int) $leaderboard->topn));

// Show the requested breakdown, or the viewer's own when they are ranked.
if (
    !$userid && $board->can_view_breakdown((int) $USER->id)
        && \mod_leaderboard\api::get_builder($cm)->is_ranked_user((int) $USER->id)
) {
    $userid = (int) $USER->id;
}
if ($userid) {
    if (!$board->can_view_breakdown($userid)) {
        throw new required_capability_exception($context, 'mod/leaderboard:viewallbreakdowns', 'nopermissions', '');
    }
    echo $renderer->render_from_template('mod_leaderboard/breakdown', $board->export_breakdown($userid));
}

echo $OUTPUT->footer();
