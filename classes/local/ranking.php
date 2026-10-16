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
 * A calculated ranking: who has how many points, and their rank.
 *
 * It holds user ids and numbers only. Names are looked up when it is displayed, so a
 * cached ranking never contains stale or hidden identities.
 *
 * @package    mod_leaderboard
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ranking {
    /** @var array List of ['userid' => int, 'points' => int, 'rank' => int], best first. */
    private array $entries;

    /** @var array userid => position in $entries. */
    private array $positions = [];

    /** @var int When the calculation started. */
    private int $generated;

    /** @var int When it must be recalculated even if nothing else happened. */
    private int $validuntil;

    /** @var string Identifies the settings it was calculated with. */
    private string $settingskey;

    /**
     * Constructor.
     *
     * @param array $entries Ranked entries, best first.
     * @param int $generated When the calculation started.
     * @param int $validuntil When it expires.
     * @param string $settingskey Identifies the settings used.
     */
    public function __construct(array $entries, int $generated, int $validuntil, string $settingskey) {
        $this->entries = array_values($entries);
        foreach ($this->entries as $position => $entry) {
            $this->positions[$entry['userid']] = $position;
        }
        $this->generated = $generated;
        $this->validuntil = $validuntil;
        $this->settingskey = $settingskey;
    }

    /**
     * Rank users by points, highest first, using standard competition ranking (1, 2, 2, 4).
     *
     * @param array $points userid => points (whole numbers).
     * @param array $sortnames userid => name used to order students who share a rank.
     * @return array Ranked entries.
     */
    public static function rank(array $points, array $sortnames): array {
        $userids = array_keys($points);
        usort($userids, function (int $a, int $b) use ($points, $sortnames): int {
            if ($points[$a] !== $points[$b]) {
                return $points[$b] <=> $points[$a];
            }
            $byname = strcmp(\core_text::strtolower($sortnames[$a] ?? ''), \core_text::strtolower($sortnames[$b] ?? ''));
            return $byname !== 0 ? $byname : $a <=> $b;
        });

        $entries = [];
        $rank = 0;
        $previous = null;
        foreach ($userids as $position => $userid) {
            if ($previous === null || $points[$userid] !== $previous) {
                $rank = $position + 1;
                $previous = $points[$userid];
            }
            $entries[] = ['userid' => $userid, 'points' => $points[$userid], 'rank' => $rank];
        }
        return $entries;
    }

    /**
     * All entries, best first.
     *
     * @return array
     */
    public function get_entries(): array {
        return $this->entries;
    }

    /**
     * The best entries.
     *
     * @param int $limit How many. 0 means all.
     * @return array
     */
    public function get_top(int $limit): array {
        return $limit > 0 ? array_slice($this->entries, 0, $limit) : $this->entries;
    }

    /**
     * One user's entry.
     *
     * @param int $userid The user.
     * @return array|null Null when the user is not ranked.
     */
    public function get_entry(int $userid): ?array {
        return isset($this->positions[$userid]) ? $this->entries[$this->positions[$userid]] : null;
    }

    /**
     * How many students are ranked.
     *
     * @return int
     */
    public function count(): int {
        return count($this->entries);
    }

    /**
     * When the calculation started.
     *
     * @return int
     */
    public function get_generated(): int {
        return $this->generated;
    }

    /**
     * When the ranking expires.
     *
     * @return int
     */
    public function get_valid_until(): int {
        return $this->validuntil;
    }

    /**
     * Identifies the settings it was calculated with.
     *
     * @return string
     */
    public function get_settings_key(): string {
        return $this->settingskey;
    }

    /**
     * For storing in the cache.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'entries' => $this->entries,
            'generated' => $this->generated,
            'validuntil' => $this->validuntil,
            'settingskey' => $this->settingskey,
        ];
    }

    /**
     * From the cache.
     *
     * @param array $data What to_array() returned.
     * @return self
     */
    public static function from_array(array $data): self {
        return new self($data['entries'], $data['generated'], $data['validuntil'], $data['settingskey']);
    }
}
