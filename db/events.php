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
 * Event observers for mod_leaderboard.
 *
 * Every observer is 'internal' => false, so it runs after the database transaction
 * that triggered the event has been committed. Nothing here can roll back a grade,
 * a submission or a course restore.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$changed = '\mod_leaderboard\observer::course_data_changed';

$observers = [
    // Anything that can change points, visibility, lateness or who is ranked.
    ['eventname' => '\core\event\user_graded', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\grade_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\grade_item_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\grade_item_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\grade_item_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\user_enrolment_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\user_enrolment_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\user_enrolment_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\role_assigned', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\role_unassigned', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\group_member_added', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\group_member_removed', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\core\event\course_module_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\assessable_submitted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\submission_status_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\extension_granted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\user_override_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_assign\event\group_override_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\user_override_deleted', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_created', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_updated', 'callback' => $changed, 'internal' => false],
    ['eventname' => '\mod_quiz\event\group_override_deleted', 'callback' => $changed, 'internal' => false],

    // Automatic provisioning.
    [
        'eventname' => '\core\event\course_restored',
        'callback' => '\mod_leaderboard\observer::course_restored',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\mod_leaderboard\observer::course_module_deleted',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\mod_leaderboard\observer::course_deleted',
        'internal' => false,
    ],
];
