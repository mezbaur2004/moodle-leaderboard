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
 * Settings form for a leaderboard.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_leaderboard\local\points_settings;

/**
 * Settings form for a leaderboard.
 */
class mod_leaderboard_mod_form extends moodleform_mod {
    #[\Override]
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '48']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setDefault('name', \mod_leaderboard\local\provisioner::default_name());
        $this->standard_intro_elements();

        // Points per mark.
        $mform->addElement('header', 'pointsheading', get_string('settings_points', 'leaderboard'));
        $locked = points_settings::is_locked();
        if ($locked) {
            $mform->addElement('static', 'lockedinfo', '', get_string('pointslocked', 'leaderboard'));
        }
        $site = points_settings::site();
        foreach (points_settings::FIELDS as $field => $unused) {
            $mform->addElement('text', $field, get_string($field, 'leaderboard'), ['size' => 6]);
            $mform->setType($field, PARAM_RAW_TRIMMED);
            $mform->setDefault($field, format_float($site->get($field), 2, true, true));
            $mform->addHelpButton($field, $field, 'leaderboard');
            if ($locked) {
                $mform->freeze($field);
            }
        }

        // Display.
        $mform->addElement('header', 'displayheading', get_string('settings_display', 'leaderboard'));
        $mform->addElement('text', 'topn', get_string('topn', 'leaderboard'), ['size' => 4]);
        $mform->setType('topn', PARAM_INT);
        $defaulttopn = get_config('leaderboard', 'defaulttopn');
        $mform->setDefault('topn', $defaulttopn === false ? 10 : (int) $defaulttopn);
        $mform->addHelpButton('topn', 'topn', 'leaderboard');

        $mform->addElement('advcheckbox', 'showinline', get_string('showinline', 'leaderboard'));
        $mform->setDefault('showinline', 1);
        $mform->addHelpButton('showinline', 'showinline', 'leaderboard');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    #[\Override]
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        // Show stored values in the user's number format, or the site values when they are locked.
        $site = points_settings::site();
        foreach (points_settings::FIELDS as $field => $unused) {
            if (points_settings::is_locked()) {
                $defaultvalues[$field] = format_float($site->get($field), 2, true, true);
            } else if (isset($defaultvalues[$field])) {
                $defaultvalues[$field] = format_float((float) $defaultvalues[$field], 2, true, true);
            }
        }
    }

    #[\Override]
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (!points_settings::is_locked()) {
            foreach (points_settings::FIELDS as $field => $unused) {
                $value = unformat_float($data[$field] ?? '', true);
                if ($value === false || $value === null || $value < 0) {
                    $errors[$field] = get_string('errorpoints', 'leaderboard');
                }
            }
        }
        if (isset($data['topn']) && (int) $data['topn'] < 0) {
            $errors['topn'] = get_string('errortopn', 'leaderboard');
        }

        // One leaderboard per course.
        if (empty($data['instance'])) {
            $sql = "SELECT 1
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                     WHERE cm.course = :courseid AND m.name = 'leaderboard' AND cm.deletioninprogress = 0";
            if ($DB->record_exists_sql($sql, ['courseid' => $this->current->course])) {
                $errors['name'] = get_string('erroronlyone', 'leaderboard');
            }
        }
        return $errors;
    }
}
