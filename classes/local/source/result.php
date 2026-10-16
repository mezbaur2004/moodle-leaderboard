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
    /** @var array userid => list of line arrays. */
    private array $lines = [];

    /** @var int|null The earliest future time a hidden grade becomes visible, or null. */
    private ?int $nextchange = null;

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
        $this->lines[$userid][] = $line;
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
        $totals = [];
        foreach ($this->lines as $userid => $lines) {
            $totals[$userid] = array_sum(array_column($lines, 'points'));
        }
        return $totals;
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
        foreach ($other->lines as $userid => $lines) {
            foreach ($lines as $line) {
                $this->add_line($userid, $line);
            }
        }
        if ($other->nextchange !== null) {
            $this->change_at($other->nextchange);
        }
    }
}
