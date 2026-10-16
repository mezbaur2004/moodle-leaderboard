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
 * List of the leaderboards in a course.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);
$coursecontext = context_course::instance($course->id);

$event = \mod_leaderboard\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/leaderboard/index.php', ['id' => $course->id]);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('modulenameplural', 'leaderboard'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $coursecontext]));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'leaderboard'));

$modinfo = get_fast_modinfo($course);
$table = new html_table();
$table->head = [get_string('name')];
$table->attributes['class'] = 'generaltable mod_index';
foreach ($modinfo->get_instances_of('leaderboard') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $link = html_writer::link(
        new moodle_url('/mod/leaderboard/view.php', ['id' => $cm->id]),
        format_string($cm->name, true, ['context' => $cm->context]),
        $cm->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link];
}

if (empty($table->data)) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'leaderboard')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
