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
 * Manage consecutive absence warning rules.
 *
 * ?id=0  → site-level defaults (admin)
 * ?id=<cmid> → attendance-level overrides (teacher)
 *
 * @package   mod_attendance
 * @copyright 2026 onwards
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/attendance/lib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

$action = optional_param('action', '', PARAM_ALPHA);
$notid  = optional_param('notid', 0, PARAM_INT);
$id     = optional_param('id', 0, PARAM_INT);

$url = new moodle_url('/mod/attendance/consecwarnings.php');

if (empty($id)) {
    // Site-level defaults — admin only.
    admin_externalpage_setup('managemodules');

    $output = $PAGE->get_renderer('mod_attendance');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('defaultconsecwarnings', 'mod_attendance'));
    $tabmenu = attendance_print_settings_tabs('defaultconsecwarnings');
    echo $tabmenu;
} else {
    // Attendance-level config.
    $cm     = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $att    = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);

    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    require_capability('mod/attendance:changepreferences', $context);

    $att = new mod_attendance_structure($att, $cm, $course, $PAGE->context);

    $PAGE->set_url($url);
    $PAGE->set_title($course->shortname . ': ' . $att->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add($att->name);

    $output = $PAGE->get_renderer('mod_attendance');
    echo $output->header();
}

$mform = new mod_attendance\form\addconsecwarning($url, ['notid' => $notid, 'id' => $id]);

if ($data = $mform->get_data()) {
    if (empty($data->notid)) {
        // Insert new warning.
        $notify = new stdClass();
        $notify->idnumber        = empty($id) ? 0 : $att->id;
        $notify->minabsences     = $data->minabsences;
        $notify->emailuser       = empty($data->emailuser) ? 0 : 1;
        $notify->emailsubject    = $data->emailsubject;
        $notify->emailcontent    = $data->emailcontent['text'];
        $notify->emailcontentformat = $data->emailcontent['format'];
        $notify->thirdpartyemails = '';
        if (!empty($data->thirdpartyemails)) {
            $notify->thirdpartyemails = implode(',', $data->thirdpartyemails);
        }

        if ($DB->record_exists('attendance_consecwarning',
                ['idnumber' => $notify->idnumber, 'minabsences' => $notify->minabsences])) {
            echo $OUTPUT->notification(get_string('consecwarningfailed', 'mod_attendance'), 'warning');
        } else {
            $DB->insert_record('attendance_consecwarning', $notify);
            echo $OUTPUT->notification(get_string('consecwarningupdated', 'mod_attendance'), 'success');
        }
    } else {
        // Update existing warning.
        if (!empty($id) && $data->idnumber != $att->id) {
            throw new moodle_exception('invalidcoursemodule');
        }
        $notify = new stdClass();
        $notify->id              = $data->notid;
        $notify->idnumber        = $data->idnumber;
        $notify->minabsences     = $data->minabsences;
        $notify->emailuser       = empty($data->emailuser) ? 0 : 1;
        $notify->emailsubject    = $data->emailsubject;
        $notify->emailcontentformat = $data->emailcontent['format'];
        $notify->emailcontent    = $data->emailcontent['text'];
        $notify->thirdpartyemails = '';
        if (!empty($data->thirdpartyemails)) {
            $notify->thirdpartyemails = implode(',', $data->thirdpartyemails);
        }

        $existing = $DB->get_record('attendance_consecwarning',
            ['idnumber' => $notify->idnumber, 'minabsences' => $notify->minabsences]);
        if (empty($existing) || $existing->id == $notify->id) {
            $DB->update_record('attendance_consecwarning', $notify);
            echo $OUTPUT->notification(get_string('consecwarningupdated', 'mod_attendance'), 'success');
        } else {
            echo $OUTPUT->notification(get_string('consecwarningfailed', 'mod_attendance'), 'error');
        }
    }
}

if ($action == 'delete' && !empty($notid)) {
    if (!optional_param('confirm', false, PARAM_BOOL)) {
        $cancelurl = new moodle_url('/mod/attendance/consecwarnings.php', ['id' => $id]);
        $confirmurl = new moodle_url('/mod/attendance/consecwarnings.php',
            ['action' => 'delete', 'notid' => $notid, 'sesskey' => sesskey(), 'confirm' => true, 'id' => $id]);
        echo $OUTPUT->confirm(get_string('deletewarningconfirm_consec', 'mod_attendance'), $confirmurl, $cancelurl);
        echo $OUTPUT->footer();
        exit;
    } else {
        require_sesskey();
        $params = ['id' => $notid];
        if (!empty($att)) {
            $params['idnumber'] = $att->id;
        }
        $DB->delete_records('attendance_consecwarning', $params);
        echo $OUTPUT->notification(get_string('consecwarningdeleted', 'mod_attendance'), 'success');
    }
}

if ($action == 'update' && !empty($notid)) {
    $existing = $DB->get_record('attendance_consecwarning', ['id' => $notid]);
    $content = $existing->emailcontent;
    $existing->emailcontent = [];
    $existing->emailcontent['text'] = $content;
    $existing->emailcontent['format'] = $existing->emailcontentformat;
    $existing->notid = $existing->id;
    $existing->id = $id;
    $mform->set_data($existing);
    $mform->display();
} else if ($action == 'add' && confirm_sesskey()) {
    $mform->display();
} else {
    $idnumber = empty($id) ? 0 : $att->id;
    $desc = empty($id)
        ? get_string('consecwarningsdesc', 'mod_attendance')
        : get_string('consecwarningsdesc_course', 'mod_attendance');
    echo $OUTPUT->box($desc, 'generalbox attendancedesc', 'notice');

    $existingnotifications = $DB->get_records('attendance_consecwarning',
        ['idnumber' => $idnumber], 'minabsences');

    if (!empty($existingnotifications)) {
        $table = new html_table();
        $table->head = [
            get_string('consecwarningthreshold', 'mod_attendance'),
            get_string('emailsubject', 'mod_attendance'),
            '',
        ];
        foreach ($existingnotifications as $notification) {
            $deleteurl = new moodle_url('/mod/attendance/consecwarnings.php',
                ['action' => 'delete', 'notid' => $notification->id, 'id' => $id]);
            $editurl = new moodle_url('/mod/attendance/consecwarnings.php',
                ['action' => 'update', 'notid' => $notification->id, 'id' => $id]);
            $actionbuttons = $OUTPUT->action_icon($deleteurl, new pix_icon('t/delete',
                get_string('delete', 'attendance')));
            $actionbuttons .= $OUTPUT->action_icon($editurl, new pix_icon('t/edit',
                get_string('update', 'attendance')));
            $table->data[] = [
                $notification->minabsences,
                $notification->emailsubject,
                $actionbuttons,
            ];
        }
        echo html_writer::table($table);
    }

    $addurl = new moodle_url('/mod/attendance/consecwarnings.php',
        ['action' => 'add', 'sesskey' => sesskey(), 'id' => $id]);
    echo $OUTPUT->single_button($addurl, get_string('addconsecwarning', 'mod_attendance'));
}

echo $OUTPUT->footer();
