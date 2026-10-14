<?php

namespace local_vikesa_coursewizard\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class institution_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $record = $this->_customdata['record'];

        $mform->addElement('static', 'institutionname', get_string('institution', 'local_vikesa_coursewizard'),
            $record->institution);

        $mform->addElement('static', 'categorylink', get_string('category', 'local_vikesa_coursewizard'),
            \html_writer::link(
                new \moodle_url('/course/index.php', ['categoryid' => $record->categoryid]),
                \core_course_category::get($record->categoryid)->get_formatted_name()
            ));

        $mform->addElement('text', 'responsiblejobtitle',
            get_string('responsiblejobtitle', 'local_vikesa_coursewizard'));
        $mform->setType('responsiblejobtitle', PARAM_TEXT);
        $mform->addRule('responsiblejobtitle', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('responsiblejobtitle', 'responsiblejobtitle', 'local_vikesa_coursewizard');

        $mform->addElement('hidden', 'id', $record->id);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }
}
