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
 * Contains class addconsecwarning
 *
 * @package   mod_attendance
 * @copyright 2026 onwards
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_attendance\form;

use moodleform;
use context_course;

/**
 * Form for adding/editing a consecutive absence warning rule.
 *
 * @package   mod_attendance
 * @copyright 2026 onwards
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addconsecwarning extends moodleform {
    /**
     * Form definition
     */
    public function definition() {
        global $COURSE;
        $mform = $this->_form;

        $options = [];
        for ($i = 1; $i <= 20; $i++) {
            $options[$i] = "$i";
        }
        $mform->addElement('select', 'minabsences', get_string('minabsences', 'mod_attendance'), $options);
        $mform->addHelpButton('minabsences', 'minabsences', 'mod_attendance');
        $mform->setType('minabsences', PARAM_INT);
        $mform->setDefault('minabsences', 2);

        $mform->addElement('checkbox', 'emailuser', get_string('emailuser', 'mod_attendance'));
        $mform->addHelpButton('emailuser', 'emailuser', 'mod_attendance');
        $mform->setDefault('emailuser', 1);

        $mform->addElement('text', 'emailsubject', get_string('emailsubject', 'mod_attendance'), ['size' => '64']);
        $mform->setType('emailsubject', PARAM_TEXT);
        $mform->addRule('emailsubject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('emailsubject', 'emailsubject', 'mod_attendance');
        $mform->setDefault('emailsubject', get_string('consecabsenceemailsubject_default', 'mod_attendance'));

        $mform->addElement('editor', 'emailcontent', get_string('emailcontent', 'mod_attendance'), null, null);
        $mform->setDefault('emailcontent', ['text' => get_string('consecabsenceemailcontent_default', 'mod_attendance')]);
        $mform->setType('emailcontent', PARAM_RAW);
        $mform->addHelpButton('emailcontent', 'emailcontent', 'mod_attendance');

        $users = get_users_by_capability(context_course::instance($COURSE->id), 'mod/attendance:warningemails');
        $options = [];
        foreach ($users as $user) {
            $options[$user->id] = fullname($user);
        }

        $select = $mform->addElement(
            'searchableselector',
            'thirdpartyemails',
            get_string('thirdpartyemails', 'mod_attendance'),
            $options
        );
        $mform->setType('thirdpartyemails', PARAM_TEXT);
        $mform->addHelpButton('thirdpartyemails', 'thirdpartyemails', 'mod_attendance');
        $select->setMultiple(true);

        $mform->addElement('hidden', 'idnumber', 0);
        $mform->setType('idnumber', PARAM_INT);

        $mform->addElement('hidden', 'notid', 0);
        $mform->setType('notid', PARAM_INT);

        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        if (!empty($this->_customdata['notid'])) {
            $btnstring = get_string('update', 'attendance');
        } else {
            $btnstring = get_string('add', 'attendance');
        }
        $this->add_action_buttons(true, $btnstring);
    }
}
