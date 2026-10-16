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

namespace mod_leaderboard\local\source;

/**
 * What a source found: point lines per user, and when the answer may next change on its own.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result {
    /** @var bool Whether to keep every line, or only the totals (enough for a ranking, and much smaller). */
    private bool $keeplines;

    /** @var array userid => list of line arrays. */
    private array $lines = [];

    /** @var float[] userid => total points. */
    private array $totals = [];

    /** @var int|null The earliest future time a hidden grade becomes visible, or null. */
    private ?int $nextchange = null;

    /**
     * Constructor.
     *
     * @param bool $keeplines False to keep only the totals.
     */
    public function __construct(bool $keeplines = true) {
        $this->keeplines = $keeplines;
    }

    /**
     * Whether the lines are kept, so a source can skip building them.
     *
     * @return bool
     */
    public function keeps_lines(): bool {
        return $this->keeplines;
    }

    /**
     * Add one line.
     *
     * Each line is an array with: source, itemid, cmid, modname, name, mark, grademax,
     * late, permark, points.
     *
     * @param int $userid The user.
     * @param array $line The line.
     */
    public function add_line(int $userid, array $line): void {
        $this->add_points($userid, (float) $line['points']);
        if ($this->keeplines) {
            $this->lines[$userid][] = $line;
        }
    }

    /**
     * Add points without a line, when lines are not kept.
     *
     * @param int $userid The user.
     * @param float $points The points.
     */
    public function add_points(int $userid, float $points): void {
        $this->totals[$userid] = ($this->totals[$userid] ?? 0.0) + $points;
    }

    /**
     * Remember that the answer changes at a given time even without any event.
     *
     * @param int $time A future timestamp.
     */
    public function change_at(int $time): void {
        if ($this->nextchange === null || $time < $this->nextchange) {
            $this->nextchange = $time;
        }
    }

    /**
     * The lines for one user.
     *
     * @param int $userid The user.
     * @return array
     */
    public function get_lines(int $userid): array {
        return $this->lines[$userid] ?? [];
    }

    /**
     * Total points per user, before rounding.
     *
     * @return float[] userid => points.
     */
    public function get_totals(): array {
        return $this->totals;
    }

    /**
     * The earliest future time the answer changes on its own.
     *
     * @return int|null
     */
    public function get_next_change(): ?int {
        return $this->nextchange;
    }

    /**
     * Merge another result into this one.
     *
     * @param result $other The other result.
     */
    public function merge(result $other): void {
        foreach ($other->totals as $userid => $points) {
            $this->add_points($userid, $points);
        }
        if ($this->keeplines) {
            foreach ($other->lines as $userid => $lines) {
                foreach ($lines as $line) {
                    $this->lines[$userid][] = $line;
                }
            }
        }
        if ($other->nextchange !== null) {
            $this->change_at($other->nextchange);
        }
    }
}
