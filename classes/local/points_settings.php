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

/**
 * Points per mark for each kind of graded activity.
 *
 * Site settings hold the defaults. Each leaderboard keeps its own copy, made when it is
 * created, which teachers may change unless the site locks the values ("lockpoints"),
 * in which case the site values always apply.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class points_settings {
    /** @var array Setting name => built-in default, used when the site setting has never been saved. */
    public const FIELDS = [
        'assignpoints' => 2,
        'assignlatepoints' => 1,
        'quizpoints' => 2,
        'zoompoints' => 2,
        'zoomattendpoints' => 2,
        'otherpoints' => 0,
    ];

    /** @var string Zoom points come from the Zoom grades in the gradebook. */
    public const ZOOM_GRADEBOOK = 'gradebook';

    /** @var string Zoom points come from local_zoomattendance: attendance percentage times points per percent. */
    public const ZOOM_ATTENDANCE = 'attendance';

    /** @var string[] Activity types that have their own setting. Everything else uses otherpoints. */
    public const NAMED_MODULES = ['assign', 'quiz', 'zoom'];

    /** @var float[] Field => value. */
    private array $values;

    /** @var string Where Zoom points come from: ZOOM_GRADEBOOK or ZOOM_ATTENDANCE. */
    private string $zoomsource;

    /**
     * Constructor.
     *
     * @param array $values Field => points per mark. Missing fields use the built-in defaults.
     * @param string $zoomsource Where Zoom points come from.
     */
    public function __construct(array $values, string $zoomsource = self::ZOOM_GRADEBOOK) {
        $this->values = [];
        foreach (self::FIELDS as $field => $default) {
            $value = $values[$field] ?? $default;
            $this->values[$field] = max(0.0, (float) $value);
        }
        $this->zoomsource = $zoomsource === self::ZOOM_ATTENDANCE ? self::ZOOM_ATTENDANCE : self::ZOOM_GRADEBOOK;
    }

    /**
     * Whether local_zoomattendance is installed, so it can be a source of Zoom points.
     *
     * @return bool
     */
    public static function attendance_available(): bool {
        // Version 0.5.0 (2026100700) has the attendance API this plugin uses.
        return class_exists(\local_zoomattendance\local\attendance::class)
            && (int) get_config('local_zoomattendance', 'version') >= 2026100700;
    }

    /**
     * Whether Zoom points come from local_zoomattendance. Falls back to the gradebook when the
     * plugin is not installed.
     *
     * @return bool
     */
    public function uses_zoom_attendance(): bool {
        return $this->zoomsource === self::ZOOM_ATTENDANCE && self::attendance_available();
    }

    /**
     * Where Zoom points are set to come from.
     *
     * @return string ZOOM_GRADEBOOK or ZOOM_ATTENDANCE.
     */
    public function get_zoom_source(): string {
        return $this->zoomsource;
    }

    /**
     * The site defaults.
     *
     * @return self
     */
    public static function site(): self {
        $values = [];
        foreach (self::FIELDS as $field => $default) {
            $configured = get_config('leaderboard', $field);
            $values[$field] = ($configured === false || $configured === '') ? $default : $configured;
        }
        return new self($values, (string) get_config('leaderboard', 'zoomsource'));
    }

    /**
     * Whether the site forces its values on every leaderboard.
     *
     * @return bool
     */
    public static function is_locked(): bool {
        return (bool) get_config('leaderboard', 'lockpoints');
    }

    /**
     * The values that apply to one leaderboard.
     *
     * @param \stdClass $leaderboard A record from the leaderboard table.
     * @return self
     */
    public static function for_instance(\stdClass $leaderboard): self {
        if (self::is_locked()) {
            return self::site();
        }
        $values = [];
        foreach (self::FIELDS as $field => $default) {
            $values[$field] = $leaderboard->{$field} ?? $default;
        }
        return new self($values, (string) ($leaderboard->zoomsource ?? self::ZOOM_GRADEBOOK));
    }

    /**
     * Points per mark for a grade from the given activity type.
     *
     * @param string $modname Activity type, for example 'assign'.
     * @param bool $late Whether the work was handed in after the student's due date (assignments only).
     * @return float
     */
    public function per_mark(string $modname, bool $late = false): float {
        switch ($modname) {
            case 'assign':
                return $late ? $this->values['assignlatepoints'] : $this->values['assignpoints'];
            case 'quiz':
                return $this->values['quizpoints'];
            case 'zoom':
                return $this->values['zoompoints'];
            case 'leaderboard':
                return 0.0;
            default:
                return $this->values['otherpoints'];
        }
    }

    /**
     * Whether late assignments earn a different rate, so lateness has to be worked out at all.
     *
     * @return bool
     */
    public function late_rate_differs(): bool {
        return abs($this->values['assignpoints'] - $this->values['assignlatepoints']) > 0.000001;
    }

    /**
     * Whether a grade from this activity type can earn any points.
     *
     * @param string $modname Activity type.
     * @return bool
     */
    public function counts(string $modname): bool {
        if ($modname === 'assign') {
            return $this->values['assignpoints'] > 0 || $this->values['assignlatepoints'] > 0;
        }
        return $this->per_mark($modname) > 0;
    }

    /**
     * Whether activity types without their own setting count.
     *
     * @return bool
     */
    public function counts_other_modules(): bool {
        return $this->values['otherpoints'] > 0;
    }

    /**
     * One value.
     *
     * @param string $field One of the FIELDS keys.
     * @return float
     */
    public function get(string $field): float {
        return $this->values[$field];
    }

    /**
     * All values and the Zoom source, for cache keys.
     *
     * @return array
     */
    public function to_array(): array {
        return $this->values + ['zoomsource' => $this->zoomsource];
    }
}
