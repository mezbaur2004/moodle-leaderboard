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
 * Add a leaderboard to existing courses.
 *
 * Safe to run more than once: a course that was handled before is skipped, including
 * courses where a teacher deleted the leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'execute' => false,
    'courseid' => 0,
    'categoryid' => 0,
    'position' => 'top',
    'hidden' => false,
    'help' => false,
], [
    'e' => 'execute',
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

$help = <<<EOT
Add a leaderboard to the General section of existing courses.

Without --execute nothing is changed: the script only reports what it would do.

Options:
  -e, --execute        Really add the leaderboards.
  --courseid=ID        Only this course.
  --categoryid=ID      Only courses directly in this category.
  --position=top|end   Where to put it in the General section (default: top).
  --hidden             Add it hidden from students, so teachers can check it first.
  -h, --help           Show this help.

Examples:
  php mod/leaderboard/cli/provision_existing.php
  php mod/leaderboard/cli/provision_existing.php --categoryid=3 --execute

On Moodle 5.1 and later the plugin lives under public/, so run
  php public/mod/leaderboard/cli/provision_existing.php

EOT;

if ($options['help']) {
    echo $help;
    exit(0);
}

if (!in_array($options['position'], ['top', 'end'], true)) {
    cli_error('--position must be top or end');
}

\core\cron::setup_user();

$conditions = ['id <> :siteid'];
$params = ['siteid' => SITEID];
if ($options['courseid']) {
    $conditions[] = 'id = :courseid';
    $params['courseid'] = (int) $options['courseid'];
}
if ($options['categoryid']) {
    $conditions[] = 'category = :categoryid';
    $params['categoryid'] = (int) $options['categoryid'];
}

$dryrun = !$options['execute'];
if ($dryrun) {
    cli_writeln('Dry run: nothing will be changed. Add --execute to add leaderboards.');
}

$counts = [];
$courses = $DB->get_recordset_select('course', implode(' AND ', $conditions), $params, 'id', 'id, shortname');
foreach ($courses as $course) {
    try {
        $status = \mod_leaderboard\local\provisioner::ensure((int) $course->id, 'backfill', [
            'position' => $options['position'],
            'visible' => !$options['hidden'],
            'dryrun' => $dryrun,
        ]);
    } catch (\Throwable $e) {
        $status = 'error: ' . $e->getMessage();
    }
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    cli_writeln(sprintf('%6d  %-30s  %s', $course->id, $course->shortname, $status));
}
$courses->close();

cli_writeln('');
foreach ($counts as $status => $count) {
    cli_writeln(sprintf('%-20s %d', $status, $count));
}
exit(0);
