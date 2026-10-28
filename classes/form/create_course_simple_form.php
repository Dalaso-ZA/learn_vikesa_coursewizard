<?php

namespace local_vikesa_coursewizard\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * The creator's form: name, audience, and optional content. Everything else
 * (bulk CSV, renewal, ad-hoc teachers, multiple content files) is for
 * administrators and lives in create_course_form.
 */
class create_course_simple_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $institutionrec = $this->_customdata['institutionrec'];

        $mform->addElement('header', 'basics', get_string('simplebasics', 'local_vikesa_coursewizard'));
        $mform->addElement('text', 'fullname', $this->tiplabel('coursename'));
        $mform->setType('fullname', PARAM_TEXT);

        $mform->addElement('header', 'audience', get_string('simpleaudience', 'local_vikesa_coursewizard'));

        $pillablejobtitles = \local_vikesa_coursewizard\manager::get_pillable_jobtitles($institutionrec);
        $jobtitles = array_combine($pillablejobtitles, $pillablejobtitles);
        $mform->addElement('autocomplete', 'jobtitles', $this->tiplabel('audiencepills'), $jobtitles, [
            'multiple' => true,
            'noselectionstring' => get_string('noaudienceselected', 'local_vikesa_coursewizard'),
        ]);
        $mform->addHelpButton('jobtitles', 'audiencepills', 'local_vikesa_coursewizard');

        $mform->addElement('autocomplete', 'individualaccess', $this->tiplabel('individualaccess'), [], [
            'multiple' => true,
            'ajax' => 'local_vikesa_coursewizard/user_search',
            'noselectionstring' => get_string('nouserselected', 'local_vikesa_coursewizard'),
        ]);
        $mform->getElement('individualaccess')->updateAttributes(['data-categoryid' => $institutionrec->categoryid]);
        $mform->addHelpButton('individualaccess', 'individualaccess', 'local_vikesa_coursewizard');

        $mform->addElement('header', 'content', get_string('simplecontent', 'local_vikesa_coursewizard'));
        $mform->addElement('static', 'contenthint', '', get_string('simplecontenthint', 'local_vikesa_coursewizard'));

        // Working examples: the wizard reads these today, no zip needed.
        $examplelinks = '';
        foreach (['example-course.txt' => 'examplebutton', 'section-2-example.png' => 'examplemediabutton'] as $file => $label) {
            $url = new \moodle_url('/local/vikesa_coursewizard/download_example.php', ['file' => $file]);
            $examplelinks .= \html_writer::link($url, get_string($label, 'local_vikesa_coursewizard'),
                ['class' => 'btn btn-secondary btn-sm mr-2 mb-2']);
        }
        $mform->addElement('static', 'examples', '', $examplelinks
            . \html_writer::tag('p', get_string('examplehint', 'local_vikesa_coursewizard'), ['class' => 'small text-muted']));

        $mform->addElement('filepicker', 'contentfile', $this->tiplabel('contentfile'), null,
            ['maxbytes' => 512000, 'accepted_types' => ['.txt']]);
        $mform->addHelpButton('contentfile', 'contentfile', 'local_vikesa_coursewizard');

        $mform->addElement('filemanager', 'mediafiles', $this->tiplabel('mediafiles'), null,
            ['maxbytes' => 52428800, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif', '.webp',
                '.mp4', '.webm', '.pdf'], 'maxfiles' => 100]);
        $mform->addHelpButton('mediafiles', 'mediafiles', 'local_vikesa_coursewizard');

        $mform->addElement('hidden', 'categoryid', $institutionrec->categoryid);
        $mform->setType('categoryid', PARAM_INT);

        $this->add_action_buttons(true, get_string('createcourse', 'local_vikesa_coursewizard'));
    }

    /**
     * Label with a hover tooltip. The click help icon stays next to it for the
     * longer explanation; the tooltip is the short "what is this" on hover.
     */
    protected function tiplabel(string $key): string {
        return \html_writer::span(get_string($key, 'local_vikesa_coursewizard'), '', [
            'title' => get_string('tip_' . $key, 'local_vikesa_coursewizard'),
        ]);
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['fullname'] ?? '')) === '') {
            $errors['fullname'] = get_string('required');
        }

        // No audience is required: a course can be built first and the
        // audience invited once it is ready.

        return $errors;
    }

    /** Same contract as create_course_form, so the shared handler can read the uploads. */
    public function get_uploaded_files(string $elname): array {
        // The shared handler also asks for the bulk fields, which this form does not have.
        if (!$this->_form->elementExists($elname)) {
            return [];
        }
        $result = $this->get_draft_files($elname);
        return is_array($result) ? $result : [];
    }
}
