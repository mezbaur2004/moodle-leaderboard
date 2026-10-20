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
 * English strings for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activity'] = 'Activity';
$string['anonymousstudent'] = 'Anonymous student';
$string['assignlatepoints'] = 'Assignment, submitted late';
$string['assignlatepoints_desc'] = 'Points for each mark of an assignment submitted after the student\'s due date. Extensions and overrides count, so a student is only late if they miss their own date.';
$string['assignlatepoints_help'] = 'Points for each mark of an assignment submitted after the student\'s own due date (including any extension or override). For example, with 1, a late assignment marked 40/50 earns 40 points.';
$string['assignlatepoints_rule'] = 'Assignment submitted late';
$string['assignpoints'] = 'Assignment, submitted on time';
$string['assignpoints_desc'] = 'Points for each mark of an assignment submitted on time, or of an assignment without a due date.';
$string['assignpoints_help'] = 'Points for each mark of an assignment submitted on time, or that has no due date. For example, with 2, an assignment marked 40/50 earns 80 points.';
$string['assignpoints_rule'] = 'Assignment submitted on time';
$string['autoprovision'] = 'Add to every new course';
$string['autoprovision_desc'] = 'Add a leaderboard to the top of the General section of every new course, including restored and copied courses. If a teacher deletes it, it is not added again. Existing courses can be handled with the command line script mod/leaderboard/cli/provision_existing.php.';
$string['autoprovisionname'] = 'Name for added leaderboards';
$string['autoprovisionname_desc'] = 'The name given to leaderboards that are added automatically. Avoid a single common word such as "Leaderboard" if the "Activity names auto-linking" filter is on, because every occurrence of the name in course text becomes a link.';
$string['cachedef_coursechanged'] = 'When something affecting a course\'s leaderboard last changed';
$string['cachedef_rankings'] = 'Calculated leaderboard rankings';
$string['cachettl'] = 'Recalculate at least every (seconds)';
$string['cachettl_desc'] = 'Rankings are recalculated when grades, submissions, enrolments or groups change, and at least this often. Lower values give fresher rankings but more database work. Changes that Moodle records without an event, such as an access restriction lifting after a completion, show within this time.';
$string['defaultname'] = 'Course leaderboard';
$string['defaulttopn'] = 'Students shown';
$string['defaulttopn_desc'] = 'Default number of top students shown on the leaderboard page. 0 shows everyone.';
$string['erroronlyone'] = 'This course already has a leaderboard.';
$string['errorpoints'] = 'Enter a number of 0 or more.';
$string['errortopn'] = 'Enter 0 or more.';
$string['howitworks'] = 'How points work';
$string['howitworks_desc'] = 'Points come from your grades in the gradebook. Only grades you can already see count. A grade that is changed later changes your points too.';
$string['identitymode'] = 'Names on the leaderboard';
$string['identitymode_anonymous'] = 'Anonymous: students only see their own name';
$string['identitymode_desc'] = 'Whether students see each other\'s names. Teachers always see names.';
$string['identitymode_fullname'] = 'Show names';
$string['lastupdated'] = 'Last updated {$a}';
$string['late'] = 'Late';
$string['leaderboard:addinstance'] = 'Add a leaderboard';
$string['leaderboard:beranked'] = 'Appear in the leaderboard ranking';
$string['leaderboard:view'] = 'View the leaderboard';
$string['leaderboard:viewallbreakdowns'] = 'See how any student\'s points were calculated';
$string['leaderboard:viewnames'] = 'See student names on an anonymous leaderboard';
$string['lockpoints'] = 'Use these values in every course';
$string['lockpoints_desc'] = 'If ticked, every leaderboard uses the values above and teachers cannot change them. If not ticked, these are the defaults for new leaderboards and teachers can change them per course.';
$string['mark'] = 'Mark';
$string['markoutof'] = '{$a->mark} / {$a->max}';
$string['medal1'] = 'Gold';
$string['medal2'] = 'Silver';
$string['medal3'] = 'Bronze';
$string['mincohort'] = 'Minimum students for an anonymous leaderboard';
$string['mincohort_desc'] = 'When names are hidden, the leaderboard is only shown once at least this many students are ranked, so nobody can be recognised in a very small group. Not used when names are shown. In a course with overlapping groups that students can switch between, comparing two groups\' leaderboards can still reveal who has which points; use one group per student, or show names.';
$string['mobile_lastupdated'] = 'Updated {$a}';
$string['mobile_pulltorefresh'] = 'Pull down to refresh.';
$string['modulename'] = 'Leaderboard';
$string['modulename_help'] = 'The leaderboard ranks students by points earned from their grades. Assignments, quizzes and Zoom meetings earn points for every mark, as set by the site. Only grades students can already see count.';
$string['modulenameplural'] = 'Leaderboards';
$string['nogroup'] = 'You are not in a group yet, so there is no group ranking to show.';
$string['nopointsyet'] = 'No points yet. Points appear here once your work is graded.';
$string['nostudents'] = 'No students to rank yet.';
$string['otherpoints'] = 'Other graded activities';
$string['otherpoints_desc'] = 'Points for each mark of any other graded activity (for example lessons or workshops). 0 means they do not count.';
$string['otherpoints_help'] = 'Points for each mark of graded activities other than assignments, quizzes and Zoom meetings. 0 means they do not count.';
$string['otherpoints_rule'] = 'Other graded activities';
$string['permark'] = 'Points per mark';
$string['pluginadministration'] = 'Leaderboard administration';
$string['pluginname'] = 'Leaderboard';
$string['points'] = 'Points';
$string['pointslocked'] = 'The site sets these values for every course.';
$string['pointsof'] = 'Points of {$a}';
$string['pointspermark'] = '{$a} points per mark';
$string['pointsperpercent'] = '{$a} points per percent attended';
$string['pointsshort'] = '{$a} pts';
$string['privacy:metadata'] = 'The leaderboard does not store personal data. It calculates rankings from the gradebook when they are viewed, and keeps the results only in Moodle\'s temporary caches.';
$string['provisionlocked'] = 'Another process is adding a leaderboard to this course. The task will try again.';
$string['quizpoints'] = 'Quiz';
$string['quizpoints_desc'] = 'Points for each mark of a quiz.';
$string['quizpoints_help'] = 'Points for each mark of a quiz, out of the quiz\'s maximum grade. For example, with 2, a quiz marked 8/10 earns 16 points.';
$string['quizpoints_rule'] = 'Quiz';
$string['rank'] = 'Rank';
$string['ranking'] = 'Ranking';
$string['settings_display'] = 'Display';
$string['settings_points'] = 'Points per mark';
$string['settings_points_desc'] = 'Points are the marks in the gradebook multiplied by these values. Marks are the grades students see, for example 8 for a quiz graded 8/10.';
$string['settings_provision'] = 'Automatic leaderboards';
$string['settings_provision_desc'] = '';
$string['showbreakdown'] = 'Show how these points were calculated';
$string['showingall'] = 'Showing all {$a} students';
$string['showingtop'] = 'Showing the top {$a->shown} of {$a->total} students';
$string['showinline'] = 'Show a summary on the course page';
$string['showinline_help'] = 'Show the top 3 students and the student\'s own place under the leaderboard on the course page.';
$string['skipformats'] = 'Leave these course formats alone';
$string['skipformats_desc'] = 'No leaderboard is added automatically to courses in these formats.';
$string['student'] = 'Student';
$string['toofew'] = 'The leaderboard appears once more students are ranked.';
$string['topn'] = 'Students shown';
$string['topn_help'] = 'How many top students the leaderboard page shows. A student outside the top always sees their own place too. 0 shows everyone.';
$string['total'] = 'Total';
$string['you'] = 'You';
$string['yourplace'] = 'You are #{$a->rank} of {$a->total} with {$a->points} points';
$string['yourpoints'] = 'Your points';
$string['zoomattendpoints'] = 'Zoom attendance, per percent';
$string['zoomattendpoints_desc'] = 'Points for each percent of a Zoom class a student attended, as measured by the Zoom attendance plugin. With 2, a student who attended 92% of a class gets 184 points. Only used when Zoom points come from the Zoom attendance plugin.';
$string['zoomattendpoints_help'] = 'Points for each percent of a Zoom class attended. With 2, attending 92% of a class gives 184 points.';
$string['zoomattendpoints_rule'] = 'Zoom class attended';
$string['zoomclass'] = '{$a->name}, {$a->date}';
$string['zoompercent'] = '{$a}% attended';
$string['zoompoints'] = 'Zoom meeting';
$string['zoompoints_desc'] = 'Points for each mark of a Zoom meeting, as written to the gradebook by the Zoom activity. A Zoom activity has no grade unless the teacher sets one, and with the "Upon entry" grading method students get full marks for clicking Join, so keep its maximum grade small.';
$string['zoompoints_help'] = 'Points for each mark of a Zoom meeting grade in the gradebook. With the "Upon entry" grading method, students get full marks for clicking Join.';
$string['zoompoints_rule'] = 'Zoom meeting';
$string['zoomsource'] = 'Zoom points from';
$string['zoomsource_attendance'] = 'Zoom attendance plugin (percent attended)';
$string['zoomsource_desc'] = 'Where Zoom points come from: the Zoom grades in the gradebook, or the attendance measured by the Zoom attendance plugin (local_zoomattendance), counted per class from the percentage attended. With the attendance plugin, Zoom grades in the gradebook do not count. If the plugin is not installed, the gradebook is used.';
$string['zoomsource_gradebook'] = 'Gradebook (Zoom grades)';
$string['zoomsource_help'] = 'Gradebook: Zoom grades count like other grades. Zoom attendance plugin: each finished class counts by the percentage of it the student attended, also when the Zoom activity is hidden, and Zoom grades do not count.';
