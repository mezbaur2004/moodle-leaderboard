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
 * Library of functions for mod_leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_leaderboard\local\points_settings;

/**
 * Which Moodle features this activity supports.
 *
 * The leaderboard has no grade of its own, so it can never count itself.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True, false, a value, or null when unknown.
 */
function leaderboard_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_ADVANCED_GRADING:
        case FEATURE_RATE:
        case FEATURE_COMMENT:
        case FEATURE_PLAGIARISM:
        case FEATURE_COMPLETION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_NO_VIEW_LINK:
            return false;
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_OTHER;
        default:
            return null;
    }
}

/**
 * Add a leaderboard.
 *
 * Settings that were not given (for example when the provisioner adds one) are taken
 * from the site defaults.
 *
 * @param stdClass $data Form data or provisioner data.
 * @param mod_leaderboard_mod_form|null $mform The form.
 * @return int The new instance id.
 */
function leaderboard_add_instance($data, $mform = null) {
    global $DB;

    $record = leaderboard_prepare_record($data);
    $record->timecreated = time();
    $record->timemodified = $record->timecreated;
    $id = $DB->insert_record('leaderboard', $record);

    \mod_leaderboard\api::course_changed((int) $record->course);
    return $id;
}

/**
 * Update a leaderboard.
 *
 * @param stdClass $data Form data.
 * @param mod_leaderboard_mod_form|null $mform The form.
 * @return bool
 */
function leaderboard_update_instance($data, $mform = null) {
    global $DB;

    $record = leaderboard_prepare_record($data);
    $record->id = $data->instance;
    $record->timemodified = time();
    $DB->update_record('leaderboard', $record);

    \mod_leaderboard\api::reset_caches();
    \mod_leaderboard\api::course_changed((int) $record->course);
    return true;
}

/**
 * Delete a leaderboard. It stores no per-user data, so only its own record goes.
 *
 * @param int $id The instance id.
 * @return bool
 */
function leaderboard_delete_instance($id) {
    global $DB;

    $record = $DB->get_record('leaderboard', ['id' => $id]);
    if (!$record) {
        return false;
    }
    $DB->delete_records('leaderboard', ['id' => $id]);
    \mod_leaderboard\api::course_changed((int) $record->course);
    return true;
}

/**
 * Turn form or provisioner data into a leaderboard record, filling in site defaults.
 *
 * @param stdClass $data The data.
 * @return stdClass
 */
function leaderboard_prepare_record(stdClass $data): stdClass {
    $site = points_settings::site();
    $record = new stdClass();
    $record->course = (int) $data->course;
    $record->name = $data->name;
    $record->intro = $data->intro ?? '';
    $record->introformat = $data->introformat ?? FORMAT_HTML;

    foreach (points_settings::FIELDS as $field => $unused) {
        if (points_settings::is_locked() || !isset($data->{$field}) || $data->{$field} === '') {
            $record->{$field} = $site->get($field);
        } else {
            $record->{$field} = max(0.0, (float) unformat_float($data->{$field}));
        }
    }

    $defaulttopn = get_config('leaderboard', 'defaulttopn');
    $record->topn = isset($data->topn) ? max(0, (int) $data->topn) : ($defaulttopn === false ? 10 : (int) $defaulttopn);
    $record->showinline = isset($data->showinline) ? (int) (bool) $data->showinline : 1;
    return $record;
}

/**
 * Information about the activity that is the same for every user, for the course cache.
 *
 * @param stdClass $coursemodule The course module.
 * @return cached_cm_info|false
 */
function leaderboard_get_coursemodule_info($coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, topn, showinline';
    if (!$leaderboard = $DB->get_record('leaderboard', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $leaderboard->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('leaderboard', $leaderboard, $coursemodule->id, false);
    }
    $info->customdata = [
        'showinline' => (int) $leaderboard->showinline,
        'topn' => (int) $leaderboard->topn,
    ];
    return $info;
}

/**
 * Per-user changes to the course module before it is shown.
 *
 * On Moodle 5.2 and later, keep the leaderboard out of "previous / next activity"
 * navigation, so students are not routed through it.
 *
 * @param cm_info $cm The course module.
 */
function leaderboard_cm_info_dynamic(cm_info $cm) {
    if (method_exists($cm, 'set_navigation_url')) {
        $cm->set_navigation_url(null);
    }
}

/**
 * Add the short "top students and your place" summary below the activity on the course page.
 *
 * This runs for each viewer. It is skipped for web service requests (the Moodle app shows its
 * own summary through db/mobile.php), and it never lets an error break the course page.
 *
 * @param cm_info $cm The course module.
 */
function leaderboard_cm_info_view(cm_info $cm) {
    global $PAGE;

    if (defined('WS_SERVER') && WS_SERVER) {
        return;
    }
    $customdata = $cm->customdata;
    if (
        empty($customdata['showinline']) || !$cm->uservisible
            || !has_capability('mod/leaderboard:view', $cm->context)
    ) {
        return;
    }

    try {
        $board = new \mod_leaderboard\output\board($cm);
        $html = $PAGE->get_renderer('mod_leaderboard')->render_from_template(
            'mod_leaderboard/summary',
            $board->export_summary(3)
        );
        // The description (when shown on the course page) is formatted first, with the same
        // options as the course page uses, then the summary is added.
        $existing = $cm->get_formatted_content(['overflowdiv' => true, 'noclean' => true]);
        $cm->set_content($existing . $html, true);
    } catch (\Throwable $e) {
        debugging('mod_leaderboard: could not show the summary: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Mark the course as changed when its rankings may need recalculating, for example after a course reset.
 *
 * @param stdClass $data The reset form data.
 * @return array Status messages.
 */
function leaderboard_reset_userdata($data) {
    \mod_leaderboard\api::course_changed((int) $data->courseid);
    return [];
}
