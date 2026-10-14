<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_vikesa_coursewizard\form\institution_form;

admin_externalpage_setup('local_vikesa_coursewizard_institutions');

global $DB, $OUTPUT, $PAGE;

$editid = optional_param('edit', 0, PARAM_INT);

if ($editid) {
    $record = $DB->get_record('local_vikesa_institution', ['id' => $editid], '*', MUST_EXIST);
    $form = new institution_form(
        new moodle_url('/local/vikesa_coursewizard/admin/institutions.php', ['edit' => $editid]),
        ['record' => $record]
    );
    $form->set_data($record);

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/vikesa_coursewizard/admin/institutions.php'));
    } else if ($data = $form->get_data()) {
        $oldtitle = (string) $record->responsiblejobtitle;
        $record->responsiblejobtitle = trim($data->responsiblejobtitle);
        $record->timemodified = time();
        $DB->update_record('local_vikesa_institution', $record);
        \local_vikesa_coursewizard\manager::refresh_creator_roles($record, $oldtitle);
        redirect(new moodle_url('/local/vikesa_coursewizard/admin/institutions.php'),
            get_string('institutionupdated', 'local_vikesa_coursewizard'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('editinstitution', 'local_vikesa_coursewizard'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();

if (!$DB->record_exists('user_info_field', ['shortname' => 'jobtitle'])) {
    echo $OUTPUT->notification(
        get_string('missingjobtitlefield', 'local_vikesa_coursewizard'),
        \core\output\notification::NOTIFY_WARNING
    );
}

echo $OUTPUT->heading(get_string('institutions', 'local_vikesa_coursewizard'));
echo html_writer::tag('p', get_string('institutions_desc', 'local_vikesa_coursewizard'));

$records = $DB->get_records('local_vikesa_institution', null, 'institution ASC');

$table = new html_table();
$table->head = [
    get_string('institution', 'local_vikesa_coursewizard'),
    get_string('category', 'local_vikesa_coursewizard'),
    get_string('categoryidnumber', 'local_vikesa_coursewizard'),
    get_string('responsiblejobtitle', 'local_vikesa_coursewizard'),
    '',
];

foreach ($records as $record) {
    $category = \core_course_category::get($record->categoryid, IGNORE_MISSING);
    $categoryname = $category?->get_formatted_name() ?? '-';
    $idnumber = $category?->idnumber ?? '-';
    $status = $record->responsiblejobtitle === ''
        ? html_writer::span(get_string('notconfigured', 'local_vikesa_coursewizard'), 'text-danger')
        : s($record->responsiblejobtitle);

    $editurl = new moodle_url('/local/vikesa_coursewizard/admin/institutions.php', ['edit' => $record->id]);
    $table->data[] = [
        s($record->institution),
        $categoryname,
        html_writer::tag('code', s($idnumber)),
        $status,
        html_writer::link($editurl, get_string('edit')),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
