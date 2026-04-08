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
 * Attendance task - check for consecutive absences and send warnings.
 *
 * @package    mod_attendance
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_attendance\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/attendance/lib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

/**
 * Scheduled task: detect consecutive absence streaks and notify relevant parties.
 *
 * Algorithm:
 *  1. Find attendance instances with session activity in the last day.
 *  2. For each instance, load all configured consecutive-absence warnings.
 *  3. For each enrolled student with at least one log entry, walk their
 *     full session history (ordered by date) and compute the sequence of
 *     consecutive-absence streaks.
 *  4. Persist new/updated streaks in attendance_consecabs.
 *  5. For each active (incomplete) streak that meets a warning threshold,
 *     send email if not already notified for this streak+warning pair.
 *
 * "Absent" is defined as a status whose grade = 0 (the default Absent status).
 * Sessions with no log entry for the student are ignored (not counted either way).
 *
 * @package    mod_attendance
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_consecutive_absences extends \core\task\scheduled_task {

    /**
     * Returns the localised task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('consecabsencechecktask', 'mod_attendance');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        if (empty(get_config('attendance', 'enableconsecwarnings'))) {
            return; // Feature not enabled.
        }

        $now = time();

        // Find attendance instances that have had sessions updated recently.
        $sql = "SELECT DISTINCT a.id AS attendanceid, cm.id AS cmid, c.id AS courseid, c.fullname AS coursename, a.name AS aname
                  FROM {attendance} a
                  JOIN {attendance_sessions} s ON s.attendanceid = a.id
                  JOIN {course_modules} cm ON cm.instance = a.id
                  JOIN {modules} md ON md.id = cm.module AND md.name = 'attendance'
                  JOIN {course} c ON c.id = cm.course
                 WHERE s.absenteereport = 1
                   AND s.attendanceid IN (
                       SELECT DISTINCT attendanceid
                         FROM {attendance_sessions}
                        WHERE sessdate > :yesterday
                   )";
        $attendances = $DB->get_records_sql($sql, ['yesterday' => $now - DAYSECS]);

        $numsentusers = 0;
        $numsentthird = 0;

        foreach ($attendances as $attendance) {
            // Load warnings configured for this attendance instance.
            $warnings = $DB->get_records('attendance_consecwarning',
                ['idnumber' => $attendance->attendanceid], 'minabsences ASC');
            if (empty($warnings)) {
                continue;
            }

            // Get all students who have at least one log entry for this attendance.
            $sql = "SELECT DISTINCT atl.studentid
                      FROM {attendance_log} atl
                      JOIN {attendance_sessions} s ON s.id = atl.sessionid
                     WHERE s.attendanceid = :attendanceid";
            $students = $DB->get_records_sql($sql, ['attendanceid' => $attendance->attendanceid]);

            foreach ($students as $student) {
                $userid = $student->studentid;

                // Fetch the student's complete ordered session log for this attendance.
                $sql = "SELECT ats.id AS sessionid, ats.sessdate, stg.grade
                          FROM {attendance_sessions} ats
                          JOIN {attendance_log} atl ON atl.sessionid = ats.id AND atl.studentid = :userid
                          JOIN {attendance_statuses} stg ON stg.id = atl.statusid
                               AND stg.deleted = 0 AND stg.visible = 1
                         WHERE ats.attendanceid = :attendanceid AND ats.absenteereport = 1
                         ORDER BY ats.sessdate ASC";
                $sessions = $DB->get_records_sql($sql, [
                    'userid' => $userid,
                    'attendanceid' => $attendance->attendanceid,
                ]);

                if (empty($sessions)) {
                    continue;
                }

                // Compute all streak segments from the ordered session log.
                $streaks = $this->compute_streaks($sessions);

                if (empty($streaks)) {
                    continue;
                }

                // Load existing streak records for this student+attendance.
                $existingstreaks = $DB->get_records('attendance_consecabs', [
                    'attendanceid' => $attendance->attendanceid,
                    'userid' => $userid,
                ]);

                // Index existing streaks by streakstart for quick lookup.
                $existingbystart = [];
                foreach ($existingstreaks as $existing) {
                    $existingbystart[$existing->streakstart] = $existing;
                }

                // Persist computed streaks and collect IDs for notification checks.
                $persistedstreaks = [];
                foreach ($streaks as $streak) {
                    if (isset($existingbystart[$streak->streakstart])) {
                        $record = $existingbystart[$streak->streakstart];
                        $record->streakend = $streak->streakend;
                        $record->count = $streak->count;
                        $record->completed = $streak->completed;
                        $record->timemodified = $now;
                        $DB->update_record('attendance_consecabs', $record);
                        $persistedstreaks[$streak->streakstart] = $record;
                    } else {
                        $record = new \stdClass();
                        $record->attendanceid = $attendance->attendanceid;
                        $record->userid = $userid;
                        $record->streakstart = $streak->streakstart;
                        $record->streakend = $streak->streakend;
                        $record->count = $streak->count;
                        $record->completed = $streak->completed;
                        $record->timecreated = $now;
                        $record->timemodified = $now;
                        $record->id = $DB->insert_record('attendance_consecabs', $record);
                        $persistedstreaks[$streak->streakstart] = $record;
                    }
                }

                // Mark any stored streaks that are no longer active as completed.
                foreach ($existingstreaks as $existing) {
                    if ($existing->completed == 0 && !isset($persistedstreaks[$existing->streakstart])) {
                        $existing->completed = 1;
                        $existing->timemodified = $now;
                        $DB->update_record('attendance_consecabs', $existing);
                    }
                }

                // Find the active (ongoing) streak, if any.
                $activestreak = null;
                foreach ($streaks as $streak) {
                    if (!$streak->completed) {
                        $activestreak = $streak;
                        break;
                    }
                }

                if ($activestreak === null) {
                    continue; // No current streak — nothing to notify about.
                }

                $activerecord = $persistedstreaks[$activestreak->streakstart];

                // Check each warning and send if threshold met and not already notified.
                $thirdpartynotifications = [];
                foreach ($warnings as $warning) {
                    if ($warning->minabsences > $activestreak->count) {
                        continue; // Threshold not yet reached.
                    }

                    // Check if this warning has already been sent for this streak.
                    if ($DB->record_exists('attendance_consecabs_done', [
                        'streakid' => $activerecord->id,
                        'warningid' => $warning->id,
                    ])) {
                        continue; // Already notified.
                    }

                    // Build template data record.
                    $tplrecord = clone $attendance;
                    $tplrecord->userid = $userid;
                    $tplrecord->streakcount = $activestreak->count;
                    $tplrecord->warningid = $warning->id;
                    $tplrecord->emailsubject = $warning->emailsubject;
                    $tplrecord->emailcontent = $warning->emailcontent;
                    $tplrecord->emailcontentformat = $warning->emailcontentformat;
                    $tplrecord->emailuser = $warning->emailuser;
                    $tplrecord->thirdpartyemails = $warning->thirdpartyemails;

                    // Fetch full user details for template substitution.
                    $user = $DB->get_record('user', ['id' => $userid]);
                    if (empty($user)) {
                        continue;
                    }
                    $tplrecord->firstname = $user->firstname;
                    $tplrecord->lastname = $user->lastname;
                    // Populate all name fields so attendance_template_variables works correctly.
                    $namefields = \core_user\fields::get_name_fields();
                    foreach ($namefields as $field) {
                        $tplrecord->$field = $user->$field;
                    }

                    // Apply template variable substitution.
                    $tplrecord = $this->template_variables($tplrecord);

                    // Email the student.
                    if (!empty($warning->emailuser)) {
                        $from = \core_user::get_noreply_user();
                        $oldforcelang = force_current_language($user->lang);
                        $emailcontent = format_text($tplrecord->emailcontent, $tplrecord->emailcontentformat);
                        $emailsubject = format_text($tplrecord->emailsubject, FORMAT_HTML);
                        email_to_user($user, $from, $emailsubject, $emailcontent, $emailcontent);
                        force_current_language($oldforcelang);
                        $numsentusers++;
                    }

                    // Collect third-party notifications.
                    if (!empty($warning->thirdpartyemails)) {
                        $context = \context_module::instance($attendance->cmid);
                        $sendto = explode(',', $warning->thirdpartyemails);
                        foreach ($sendto as $senduser) {
                            $senduser = trim($senduser);
                            if (empty($senduser)) {
                                continue;
                            }
                            if (!has_capability('mod/attendance:warningemails', $context, $senduser)) {
                                mtrace("user {$senduser} does not have capability in cm {$attendance->cmid}");
                                continue;
                            }
                            if (empty($thirdpartynotifications[$senduser])) {
                                $thirdpartynotifications[$senduser] = [];
                            }
                            $key = $attendance->attendanceid . '_' . $userid;
                            if (!isset($thirdpartynotifications[$senduser][$key])) {
                                $thirdpartynotifications[$senduser][$key] = get_string(
                                    'consecabsencethirdpartyemailtext',
                                    'attendance',
                                    $tplrecord
                                );
                            }
                        }
                    }

                    // Record that this warning was sent.
                    $done = new \stdClass();
                    $done->streakid = $activerecord->id;
                    $done->warningid = $warning->id;
                    $done->userid = $userid;
                    $done->timesent = $now;
                    $DB->insert_record('attendance_consecabs_done', $done);
                }

                // Send collected third-party notifications.
                foreach ($thirdpartynotifications as $sendid => $notifications) {
                    $senduser = $DB->get_record('user', ['id' => $sendid]);
                    if (empty($senduser) || !empty($senduser->deleted)) {
                        continue;
                    }
                    $from = \core_user::get_noreply_user();
                    $oldforcelang = force_current_language($senduser->lang);
                    $emailcontent = implode("\n", $notifications);
                    $emailcontent .= "\n\n" . get_string('thirdpartyemailtextfooter', 'attendance');
                    $emailcontent = format_text($emailcontent);
                    $emailsubject = get_string('consecwarnings', 'attendance');
                    email_to_user($senduser, $from, $emailsubject, $emailcontent, $emailcontent);
                    force_current_language($oldforcelang);
                    $numsentthird++;
                }
            }
        }

        if ($numsentusers > 0) {
            mtrace("{$numsentusers} consecutive absence user email(s) sent");
        }
        if ($numsentthird > 0) {
            mtrace("{$numsentthird} consecutive absence third-party email(s) sent");
        }
    }

    /**
     * Walk a chronologically ordered session log and return all consecutive-absence streaks.
     *
     * A session is considered "absent" when the recorded status has grade = 0.
     * Sessions with no log entry are excluded from the computation (the caller's
     * query already filters to sessions where a log entry exists).
     *
     * Returns an array of objects with properties:
     *   - streakstart (int)  sessdate of the first absent session
     *   - streakend   (int)  sessdate of the most recent absent session
     *   - count       (int)  number of consecutive absent sessions
     *   - completed   (bool) true when the streak was followed by a present session
     *
     * @param array $sessions Ordered array of session records (sessdate, grade).
     * @return array
     */
    protected function compute_streaks(array $sessions): array {
        $streaks = [];
        $currentstreakstart = null;
        $currentstreakend = null;
        $currentcount = 0;

        foreach ($sessions as $session) {
            if ((float)$session->grade == 0.0) {
                // Absent.
                if ($currentstreakstart === null) {
                    $currentstreakstart = (int)$session->sessdate;
                }
                $currentstreakend = (int)$session->sessdate;
                $currentcount++;
            } else {
                // Present / partial credit — closes any open streak.
                if ($currentstreakstart !== null) {
                    $streak = new \stdClass();
                    $streak->streakstart = $currentstreakstart;
                    $streak->streakend = $currentstreakend;
                    $streak->count = $currentcount;
                    $streak->completed = 1;
                    $streaks[] = $streak;
                    $currentstreakstart = null;
                    $currentstreakend = null;
                    $currentcount = 0;
                }
            }
        }

        // Any open streak at the end of the sequence is still active.
        if ($currentstreakstart !== null) {
            $streak = new \stdClass();
            $streak->streakstart = $currentstreakstart;
            $streak->streakend = $currentstreakend;
            $streak->count = $currentcount;
            $streak->completed = 0;
            $streaks[] = $streak;
        }

        return $streaks;
    }

    /**
     * Replace template variables in the emailsubject and emailcontent fields.
     *
     * Supported variables: %userfirstname%, %userlastname%, %coursename%,
     * %courseid%, %attendancename%, %cmid%, %streakcount%, plus all
     * core_user name fields (%firstname%, %lastname%, etc.).
     *
     * @param object $record Must have the fields used in the template map.
     * @return object The same record with substitutions applied.
     */
    protected function template_variables(\stdClass $record): \stdClass {
        $templatevars = [
            '/%userfirstname%/' => $record->firstname,
            '/%userlastname%/' => $record->lastname,
            '/%coursename%/' => $record->coursename,
            '/%courseid%/' => $record->courseid,
            '/%attendancename%/' => $record->aname,
            '/%cmid%/' => $record->cmid,
            '/%streakcount%/' => $record->streakcount,
        ];
        $namefields = \core_user\fields::get_name_fields();
        foreach ($namefields as $field) {
            $templatevars['/%' . $field . '%/'] = $record->$field ?? '';
        }

        $patterns = array_keys($templatevars);
        $replacements = array_values($templatevars);

        foreach (['emailsubject', 'emailcontent'] as $field) {
            if (!empty($record->$field)) {
                $record->$field = preg_replace($patterns, $replacements, $record->$field);
            }
        }

        return $record;
    }
}
