<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_vikesa_coursewizard_resync');

$summary = null;
if (optional_param('run', 0, PARAM_BOOL) && confirm_sesskey()) {
    core_php_time_limit::raise(600);
    $summary = \local_vikesa_coursewizard\casdoor::pull_all();
    \local_vikesa_coursewizard\manager::resync_all();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resync', 'local_vikesa_coursewizard'));

if ($summary !== null) {
    // Shown inline (no redirect): a redirect would drop the message.
    echo $OUTPUT->notification(
        get_string('resync_done', 'local_vikesa_coursewizard') . ' '
        . get_string('casdoor_summary', 'local_vikesa_coursewizard', (object) $summary),
        $summary['errors'] ? 'warning' : 'success');
    foreach ($summary['errors'] as $error) {
        echo $OUTPUT->notification(s($error), 'error');
    }
    $lines = [];
    foreach ($summary['orgs'] as $org => $count) {
        $lines[] = html_writer::tag('li', s($org) . ': ' . (int) $count . ' users returned by Casdoor');
    }
    if ($lines) {
        echo html_writer::tag('ul', implode('', $lines));
    }
    if ($summary['unmatched']) {
        echo html_writer::tag('p', 'Casdoor users with no matching Moodle account (matched by username = Casdoor name, or by e-mail; the Moodle account must use OAuth 2 login): '
            . s(implode(', ', $summary['unmatched'])));
    }
}

echo html_writer::tag('p', get_string('resync_desc', 'local_vikesa_coursewizard'));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'local_vikesa_coursewizard_casdoor']),
    get_string('casdoor_settings', 'local_vikesa_coursewizard')));
echo $OUTPUT->single_button(
    new moodle_url('/local/vikesa_coursewizard/admin/resync.php', ['run' => 1, 'sesskey' => sesskey()]),
    get_string('resync', 'local_vikesa_coursewizard'), 'post');
echo $OUTPUT->footer();
