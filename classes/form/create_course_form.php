<?php

namespace local_vikesa_coursewizard\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class create_course_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $institutionrec = $this->_customdata['institutionrec'];

        $mform->addElement('text', 'fullname', get_string('coursename', 'local_vikesa_coursewizard'));
        $mform->setType('fullname', PARAM_TEXT);

        $mform->addElement('filepicker', 'coursesfile',
            get_string('bulkcoursesfile', 'local_vikesa_coursewizard'), null,
            ['maxbytes' => 51200, 'accepted_types' => ['.csv', '.txt']]);
        $mform->addHelpButton('coursesfile', 'bulkcoursesfile', 'local_vikesa_coursewizard');

        $mform->addElement('filepicker', 'contentfile',
            get_string('contentfile', 'local_vikesa_coursewizard'), null,
            ['maxbytes' => 512000, 'accepted_types' => ['.txt']]);
        $mform->addHelpButton('contentfile', 'contentfile', 'local_vikesa_coursewizard');

        $mform->addElement('filemanager', 'contentfiles',
            get_string('bulkcontentfiles', 'local_vikesa_coursewizard'), null,
            ['maxbytes' => 512000, 'accepted_types' => ['.txt'], 'maxfiles' => 100]);
        $mform->addHelpButton('contentfiles', 'bulkcontentfiles', 'local_vikesa_coursewizard');

        $mform->addElement('filemanager', 'mediafiles',
            get_string('mediafiles', 'local_vikesa_coursewizard'), null,
            ['maxbytes' => 52428800, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif', '.webp',
                '.mp4', '.webm', '.pdf'], 'maxfiles' => 100]);
        $mform->addHelpButton('mediafiles', 'mediafiles', 'local_vikesa_coursewizard');

        $pillablejobtitles = \local_vikesa_coursewizard\manager::get_pillable_jobtitles($institutionrec);
        $jobtitles = array_combine($pillablejobtitles, $pillablejobtitles);
        $mform->addElement('autocomplete', 'jobtitles',
            get_string('audiencepills', 'local_vikesa_coursewizard'), $jobtitles, [
                'multiple' => true,
                'noselectionstring' => get_string('noaudienceselected', 'local_vikesa_coursewizard'),
            ]);
        $mform->addHelpButton('jobtitles', 'audiencepills', 'local_vikesa_coursewizard');

        $mform->addElement('autocomplete', 'teachers',
            get_string('adhocteachers', 'local_vikesa_coursewizard'), [], [
                'multiple' => true,
                'ajax' => 'local_vikesa_coursewizard/user_search',
                'noselectionstring' => get_string('nouserselected', 'local_vikesa_coursewizard'),
            ]);
        $mform->getElement('teachers')->updateAttributes(['data-categoryid' => $institutionrec->categoryid]);
        $mform->addHelpButton('teachers', 'adhocteachers', 'local_vikesa_coursewizard');

        $mform->addElement('autocomplete', 'individualaccess',
            get_string('individualaccess', 'local_vikesa_coursewizard'), [], [
                'multiple' => true,
                'ajax' => 'local_vikesa_coursewizard/user_search',
                'noselectionstring' => get_string('nouserselected', 'local_vikesa_coursewizard'),
            ]);
        $mform->getElement('individualaccess')->updateAttributes(['data-categoryid' => $institutionrec->categoryid]);
        $mform->addHelpButton('individualaccess', 'individualaccess', 'local_vikesa_coursewizard');

        $mform->addElement('hidden', 'categoryid', $institutionrec->categoryid);
        $mform->setType('categoryid', PARAM_INT);

        $renewalgroup = [
            $mform->createElement('text', 'renewalvalue', '', ['size' => 4]),
            $mform->createElement('select', 'renewalunit', '', [
                'day' => get_string('renewalunit_day', 'local_vikesa_coursewizard'),
                'week' => get_string('renewalunit_week', 'local_vikesa_coursewizard'),
                'month' => get_string('renewalunit_month', 'local_vikesa_coursewizard'),
                'year' => get_string('renewalunit_year', 'local_vikesa_coursewizard'),
            ]),
        ];
        $mform->addGroup($renewalgroup, 'renewalgroup', get_string('renewalperiod', 'local_vikesa_coursewizard'), ' ', false);
        $mform->setType('renewalvalue', PARAM_INT);
        $mform->setDefault('renewalunit', 'month');
        $mform->addHelpButton('renewalgroup', 'renewalperiod', 'local_vikesa_coursewizard');

        $this->add_action_buttons(true, get_string('createcourse', 'local_vikesa_coursewizard'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $hasfullname = trim((string) ($data['fullname'] ?? '')) !== '';
        $hasfile = $this->get_draft_files('coursesfile');

        if (!$hasfullname && !$hasfile) {
            $errors['fullname'] = get_string('needcoursenameorfile', 'local_vikesa_coursewizard');
        }

        return $errors;
    }

    /**
     * Public wrapper -- moodleform::get_draft_files() is protected, but the
     * controller (create_course.php) needs to read uploaded files after
     * get_data() from outside the class.
     */
    public function get_uploaded_files(string $elname): array {
        $result = $this->get_draft_files($elname);
        return is_array($result) ? $result : [];
    }
}
