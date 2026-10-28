<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_vikesa_coursewizard\editor_limits;

admin_externalpage_setup('local_vikesa_coursewizard_editorlimits');

global $OUTPUT;

if (optional_param('apply', 0, PARAM_BOOL)) {
    require_sesskey();
    $count = editor_limits::apply_to_all();
    redirect(new moodle_url('/local/vikesa_coursewizard/admin/editorlimits.php'),
        get_string('editorlimitsapplied', 'local_vikesa_coursewizard', $count), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editorlimits', 'local_vikesa_coursewizard'));
echo html_writer::tag('p', get_string('editorlimits_desc', 'local_vikesa_coursewizard'));
echo html_writer::tag('p', get_string('editorlimits_newcourses', 'local_vikesa_coursewizard'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/vikesa_coursewizard/admin/editorlimits.php'))->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'apply', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', get_string('editorlimits_apply', 'local_vikesa_coursewizard'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
