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
 * Report showing consecutive absence streak history.
 *
 * Accessible at three levels:
 *   ?id=0             → site-wide (admin)
 *   ?courseid=<id>    → all attendances in a course
 *   ?id=<cmid>        → single attendance activity (teacher)
 *
 * @package   mod_attendance
 * @copyright 2026 onwards
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/attendance/lib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

$id       = optional_param('id', 0, PARAM_INT);       // Course-module id.
$courseid = optional_param('courseid', 0, PARAM_INT); // Course id.

$url = new moodle_url('/mod/attendance/consecabsences.php');

$params = [];

if (!empty($id)) {
    // Attendance-activity level.
    $cm     = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $att    = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);

    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    require_capability('mod/attendance:viewreports', $context);

    $att = new mod_attendance_structure($att, $cm, $course, $PAGE->context);

    $PAGE->set_url($url, ['id' => $id]);
    $PAGE->set_title($course->shortname . ': ' . $att->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add($att->name);

    $output = $PAGE->get_renderer('mod_attendance');
    echo $output->header();

    $params['attendanceid'] = $att->id;

} else if (!empty($courseid)) {
    // Course level.
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('mod/attendance:viewreports', $context);

    $PAGE->set_url($url, ['courseid' => $courseid]);
    $PAGE->set_title($course->shortname . ': ' . get_string('consecabsencereport', 'mod_attendance'));
    $PAGE->set_heading($course->fullname);

    $output = $PAGE->get_renderer('mod_attendance');
    echo $output->header();

    // Restrict to attendances in this course.
    [$insql, $inparams] = $DB->get_in_or_equal(
        $DB->get_fieldset_select('attendance', 'id', 'course = :course', ['course' => $courseid]),
        SQL_PARAMS_NAMED,
        'aid'
    );
    if ($insql) {
        $params['attendanceid_in'] = $insql;
        $params = array_merge($params, $inparams);
    }

} else {
    // Site-wide — admin.
    admin_externalpage_setup('managemodules');

    $output = $PAGE->get_renderer('mod_attendance');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('consecabsencereport', 'mod_attendance'));
    $tabmenu = attendance_print_settings_tabs('consecabsences');
    echo $tabmenu;
}

echo $OUTPUT->heading(get_string('consecabsencereport', 'mod_attendance'), 3);
echo $OUTPUT->box(get_string('consecabsencereportdesc', 'mod_attendance'), 'generalbox attendancedesc', 'notice');

// Build the query.
$userfieldsapi = \core_user\fields::for_name();
$unames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

$whereclauses = ['1=1'];
$sqlparams = [];

if (!empty($params['attendanceid'])) {
    $whereclauses[] = 'ac.attendanceid = :attendanceid';
    $sqlparams['attendanceid'] = $params['attendanceid'];
} else if (!empty($params['attendanceid_in'])) {
    $whereclauses[] = "ac.attendanceid {$params['attendanceid_in']}";
    // Merge named params.
    foreach ($params as $k => $v) {
        if ($k !== 'attendanceid_in') {
            $sqlparams[$k] = $v;
        }
    }
}

$where = implode(' AND ', $whereclauses);

$sql = "SELECT ac.id, ac.attendanceid, ac.userid, ac.streakstart, ac.streakend,
               ac.count, ac.completed, a.name AS attendancename, c.fullname AS coursename,
               {$unames}
          FROM {attendance_consecabs} ac
          JOIN {attendance} a ON a.id = ac.attendanceid
          JOIN {course} c ON c.id = a.course
          JOIN {user} u ON u.id = ac.userid
         WHERE {$where}
         ORDER BY ac.streakstart DESC, u.lastname ASC, u.firstname ASC";

$records = $DB->get_records_sql($sql, $sqlparams);

if (empty($records)) {
    echo $OUTPUT->notification(get_string('noconsecabsences', 'mod_attendance'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('fullname'),
        get_string('course'),
        get_string('name'),
        get_string('streakstart', 'mod_attendance'),
        get_string('streakend', 'mod_attendance'),
        get_string('streakcount', 'mod_attendance'),
        get_string('streakstatus', 'mod_attendance'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($records as $record) {
        $fullname = fullname($record);
        $streakstartstr = userdate($record->streakstart, get_string('strftimedatetimeshort', 'langconfig'));
        $streakendstr = $record->streakend
            ? userdate($record->streakend, get_string('strftimedatetimeshort', 'langconfig'))
            : '—';
        $status = $record->completed
            ? get_string('streakcompleted', 'mod_attendance')
            : get_string('streakactive', 'mod_attendance');

        $table->data[] = [
            $fullname,
            $record->coursename,
            $record->attendancename,
            $streakstartstr,
            $streakendstr,
            $record->count,
            $status,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
