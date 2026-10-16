# Leaderboard (mod_leaderboard)

A Moodle activity that ranks the students of a course by points earned from their grades.

* Points come from the **gradebook**: each graded activity's final grade, times points per mark.
* Defaults: **assignment 2 points per mark** (1 when submitted after the student's own due
  date), **quiz 2 points per mark**, **Zoom meeting 2 points per mark** (whatever mod_zoom
  writes to the gradebook). Other graded activities do not count unless switched on.
* Only grades a student can already see count, so the leaderboard never reveals marks early.
* A leaderboard is added automatically to the top of the **General section of every new
  course**, including restored and copied courses. If a teacher deletes it, it is not added back.
* Works in the **Moodle app**: a full leaderboard page and a short summary on the course page.

Requires Moodle **5.0 or later** (tested on 5.0.11). The plugin stores no personal data of its own.

## Installing

The plugin folder must be called `leaderboard` and go into the activity modules folder:

| Moodle version | Folder |
|---|---|
| 5.0 | `mod/leaderboard` |
| 5.1 and later | `public/mod/leaderboard` |

Then visit *Site administration > Notifications* (or run `php admin/cli/upgrade.php`).

From a clone of this repository:

```sh
git clone https://github.com/mezbaur2004/moodle-leaderboard.git /path/to/moodle/mod/leaderboard
```

## How points are calculated

For every visible, graded activity in the course:

```
points = final grade in the gradebook  x  points per mark for that activity type
```

The total is rounded to whole points. "Marks" are the grades students see, for example 8
for a quiz graded 8 out of 10 (out of the quiz's *Maximum grade*).

A grade counts only when:

* the activity is visible and not being deleted;
* its grade item is not hidden, and any "hidden until" date has passed;
* no grade category it is in is hidden (or hidden until a later date);
* for quizzes whose marks are hidden until the close date: every user or group override's
  close date has passed too, so students with extra time never see others' scores early. An
  override with no close date holds the quiz back until it is removed or given one;
* the student's own grade is not hidden, excluded or empty;
* access restrictions do not hide the activity entirely from that student (as in the user
  grade report: a restriction shown greyed out with its reason does not hide the grade).

**Late assignments.** An assignment is late when it was submitted after the student's own due
date: their extension if they have one, otherwise their user or group override, otherwise the
assignment's due date (moved by the student's start date in a course with relative dates).
This is the same rule the assignment grading table uses. Team assignments use the team's
submission. With several attempts, the attempt whose grade is in the gradebook decides, so a
reopened attempt the student is still working on does not change it. Assignments without a
due date, or graded without an online submission, are never late.

**Who is ranked.** Users actively enrolled in the course who have the capability
`mod/leaderboard:beranked` (the student role by default). Teachers are not ranked. With
separate groups, students are ranked within their own group.

**Ties** share a rank: 1, 2, 2, 4.

Points can go down: if a teacher lowers a grade, the points follow.

## Settings

*Site administration > Plugins > Activity modules > Leaderboard*

| Setting | Default | Meaning |
|---|---|---|
| Assignment, submitted on time | 2 | Points per mark |
| Assignment, submitted late | 1 | Points per mark (set to 2 to ignore lateness) |
| Quiz | 2 | Points per mark |
| Zoom meeting | 2 | Points per mark |
| Other graded activities | 0 | Points per mark; 0 means they do not count |
| Use these values in every course | off | When on, teachers cannot change the values per course |
| Names on the leaderboard | Show names | Or anonymous: students only see their own name |
| Minimum students for an anonymous leaderboard | 5 | Only used in anonymous mode |
| Students shown | 10 | Default for new leaderboards; 0 shows everyone |
| Recalculate at least every (seconds) | 300 | Rankings are also recalculated when grades change |
| Add to every new course | on | |
| Name for added leaderboards | Course leaderboard | |
| Leave these course formats alone | Single activity, Social | |

Each leaderboard also has its own points values (copied from the site defaults when it is
created), the number of students shown, and whether a summary appears on the course page.

> **Zoom grades.** A Zoom activity only has a grade when the teacher sets one. With mod_zoom's
> "Upon entry" grading method, students get full marks just for clicking Join, so a Zoom
> activity with a maximum grade of 100 would give 200 points per click. Keep Zoom's maximum
> grade small (for example 1 to 5), or lower the Zoom points per mark.

> **Anonymous mode and groups.** Anonymous mode hides names, but the points stay the same in
> every group's leaderboard. If students can switch between overlapping groups (visible groups,
> or several groups each), comparing two groups can reveal who has which points.

> **Activity name links.** If the "Activity names auto-linking" filter is on, every place the
> leaderboard's name appears in course text becomes a link. That is why the default name is
> "Course leaderboard" rather than "Leaderboard".

## Existing courses

New courses get a leaderboard automatically. For courses that already exist, run the backfill
script. It changes nothing unless you add `--execute`:

```sh
# See what would happen.
php mod/leaderboard/cli/provision_existing.php

# Add to every course, or one category, or one course.
php mod/leaderboard/cli/provision_existing.php --execute
php mod/leaderboard/cli/provision_existing.php --categoryid=3 --execute
php mod/leaderboard/cli/provision_existing.php --courseid=42 --execute

# Add it hidden, or at the end of the General section instead of the top.
php mod/leaderboard/cli/provision_existing.php --execute --hidden --position=end
```

On Moodle 5.1 and later use `public/mod/leaderboard/cli/provision_existing.php`.

Running it between terms is best: adding an activity to the top of hundreds of live courses
moves teachers' welcome text down.

## How automatic adding works

| How the course was made | What happens |
|---|---|
| Course form, CSV upload, course request, data generators | Added at the end of course creation |
| Created inside a database transaction (for example the create courses web service) | Added by a background task right after, on the next cron run |
| Restore as a new course, course copy | Added, unless the backup already has a leaderboard, or it is a copy (or a copy of a copy) of a course on this site whose leaderboard was deleted |
| Course restored from the recycle bin | Keeps its own leaderboard, or none if its leaderboard had been deleted |
| Import or restore into an existing course | If that brings a second leaderboard, the one brought in is removed. When the course's own leaderboard was added automatically in the same step (CSV upload with a template course), the one brought in replaces it, at the top |
| Restore that deletes the course contents first | The restored leaderboard is used, or a new one is added |
| Teacher deletes the leaderboard | Remembered, also after the course is deleted; it is never added back. This also applies before the backfill has run |

Leaderboards this plugin removes as duplicates skip the course recycle bin. Automatically added
leaderboards use the course's default group mode and grouping.

Only managers can add a leaderboard by hand (`mod/leaderboard:addinstance`), and a course can
only have one.

## Moodle app

The plugin supports the official Moodle app (and branded apps):

* tapping the activity opens the full leaderboard, with "your points" underneath; pull down to refresh;
* the course page shows the top 3 and the student's own place, with the time the ranking was
  calculated.

The app caches content for about 20 minutes. Pulling down the course page does not refresh the
summary there; opening the full leaderboard does. After installing or upgrading the plugin,
purge Moodle's caches; app users may need to log out and in again to see the new pages.

On Moodle 5.2 and later the website's "previous / next activity" links skip the leaderboard;
the app's own previous / next buttons still include it.

## For developers

* `\mod_leaderboard\api` is the public API for other plugins (for example a course dashboard
  block): `find_course_leaderboard()`, `get_summary()`, `get_viewer_ranking()`,
  `get_breakdown()`. Display it with `\mod_leaderboard\output\board`.
* Points come from sources (`classes/local/source`). Version 1 has the gradebook source. A
  later version can add a Zoom attendance source (for example from `local_zoom_attendance`)
  and exclude `zoom` from the gradebook source so nothing counts twice.
* Rankings are cached (`rankings` cache). Events that can change them only record that the
  course changed (`coursechanged` cache), so bulk grading stays cheap.

### Running the tests

```sh
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit mod/leaderboard/tests/ranking_test.php
vendor/bin/phpunit mod/leaderboard/tests/provisioner_test.php
vendor/bin/phpunit mod/leaderboard/tests/output_test.php
```

## Licence

GNU GPL v3 or later. Copyright 2026 Pedago Academy.
