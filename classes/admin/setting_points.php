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

namespace mod_leaderboard\admin;

/**
 * A "points per mark" site setting.
 *
 * It accepts any number of 0 or more the way the activity form does, including "1.50",
 * "2.0" or a decimal comma in languages that use one, and stores it as a plain number.
 * (A PARAM_FLOAT text setting only accepts values that are already in PHP's own format.)
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_points extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name The setting name, for example 'leaderboard/assignpoints'.
     * @param string $visiblename The label.
     * @param string $description The description.
     * @param float|int $defaultsetting The default value.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 6);
    }

    #[\Override]
    public function validate($data) {
        $value = unformat_float(trim((string) $data), true);
        if ($value === false || $value === null || $value < 0) {
            return get_string('errorpoints', 'leaderboard');
        }
        return true;
    }

    #[\Override]
    public function write_setting($data) {
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        $value = (string) unformat_float(trim((string) $data), true);
        return $this->config_write($this->name, $value) ? '' : get_string('errorsetting', 'admin');
    }
}
