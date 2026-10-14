<?php

namespace local_vikesa_coursewizard\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class manage_participants_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $institutionrec = $this->_customdata['institutionrec'];
        $course = $this->_customdata['course'];

        $unattached = \local_vikesa_coursewizard\manager::get_unattached_jobtitle_cohorts($course->id, $institutionrec);
        if (!empty($unattached)) {
            $choices = array_combine($unattached, $unattached);
            $mform->addElement('select', 'addjobtitle',
                get_string('addaudience', 'local_vikesa_coursewizard'), ['' => ''] + $choices);
            $mform->addHelpButton('addjobtitle', 'addaudience', 'local_vikesa_coursewizard');
        } else {
            $mform->addElement('static', 'noaddjobtitle',
                get_string('addaudience', 'local_vikesa_coursewizard'),
                get_string('allaudiencesattached', 'local_vikesa_coursewizard'));
        }

        $mform->addElement('autocomplete', 'addteachers',
            get_string('adhocteachers', 'local_vikesa_coursewizard'), [], [
                'multiple' => true,
                'ajax' => 'local_vikesa_coursewizard/user_search',
                'noselectionstring' => get_string('nouserselected', 'local_vikesa_coursewizard'),
            ]);
        $mform->getElement('addteachers')->updateAttributes(['data-categoryid' => $institutionrec->categoryid]);

        $mform->addElement('autocomplete', 'addindividuals',
            get_string('individualaccess', 'local_vikesa_coursewizard'), [], [
                'multiple' => true,
                'ajax' => 'local_vikesa_coursewizard/user_search',
                'noselectionstring' => get_string('nouserselected', 'local_vikesa_coursewizard'),
            ]);
        $mform->getElement('addindividuals')->updateAttributes(['data-categoryid' => $institutionrec->categoryid]);

        $mform->addElement('hidden', 'courseid', $course->id);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(false, get_string('applychanges', 'local_vikesa_coursewizard'));
    }
}
