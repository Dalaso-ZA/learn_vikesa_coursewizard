<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_vikesa_coursewizard_diagnose');

use local_vikesa_coursewizard\manager;

$lookup = trim(optional_param('lookup', '', PARAM_RAW));
$fix = optional_param('fix', 0, PARAM_INT);

$self = new moodle_url('/local/vikesa_coursewizard/admin/diagnose.php');

$user = null;
if ($lookup !== '') {
    $like = $DB->sql_like('username', ':a', false) . ' OR ' . $DB->sql_like('email', ':b', false);
    $user = $DB->get_record_select('user', "($like) AND deleted = 0", ['a' => $lookup, 'b' => $lookup], '*', IGNORE_MULTIPLE);
}

if ($user && $fix && confirm_sesskey()) {
    // Forget the last synced state so sync_user() does the full job again.
    $DB->delete_records('local_vikesa_usersync', ['userid' => $user->id]);
    manager::sync_user((int) $user->id);
    redirect(new moodle_url($self, ['lookup' => $lookup]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('diagnose', 'local_vikesa_coursewizard'));
echo html_writer::tag('p', get_string('diagnose_desc', 'local_vikesa_coursewizard'));

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $self->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'lookup', 'value' => $lookup,
    'size' => 40, 'placeholder' => 'username or e-mail', 'class' => 'form-control d-inline-block w-auto']);
echo ' ' . html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Check', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ($lookup !== '' && !$user) {
    echo $OUTPUT->notification('No Moodle user with that username or e-mail.', 'error');
}

if ($user) {
    $rows = [];
    $ok = function (bool $good, string $label, string $detail) use (&$rows) {
        $rows[] = [$good ? '✔' : '✘', $label, s($detail)];
        return $good;
    };

    $ok(true, 'User', "#{$user->id} username=\"{$user->username}\" auth={$user->auth}");

    $institution = (string) $user->institution;
    $ok($institution !== '', 'Institution field filled', '"' . $institution . '"');

    $jobtitle = manager::get_user_jobtitle((int) $user->id);
    $fieldexists = $DB->record_exists('user_info_field', ['shortname' => 'jobtitle']);
    $ok($fieldexists, 'Custom profile field with short name "jobtitle" exists', $fieldexists ? 'yes' : 'no');
    $ok($jobtitle !== null, 'Job title filled for this user', '"' . (string) $jobtitle . '"');

    $rec = $institution !== '' ? $DB->get_record('local_vikesa_institution', ['institution' => $institution]) : null;
    $detail = $rec ? "category #{$rec->categoryid}" : 'no row for exactly this string.';
    if (!$rec && $institution !== '') {
        $near = $DB->get_records_select('local_vikesa_institution', $DB->sql_like('institution', ':n', false), ['n' => '%' . $institution . '%']);
        $detail .= ' Rows on the institutions page: ' . implode(' | ', array_map(fn($r) => '"' . $r->institution . '"',
            $DB->get_records('local_vikesa_institution')));
    }
    $ok((bool) $rec, 'Institution row exists for this exact institution string', $detail);

    $resp = $rec ? (string) $rec->responsiblejobtitle : '';
    $ok($resp !== '', 'Responsible job title is set on that institution', '"' . $resp . '"');
    $match = $resp !== '' && $jobtitle !== null && $jobtitle === $resp;
    $ok($match, 'User job title equals responsible job title exactly (case, spaces)',
        '"' . (string) $jobtitle . '" (' . strlen((string) $jobtitle) . ' chars) vs "' . $resp . '" (' . strlen($resp) . ' chars)');

    $role = $DB->get_record('role', ['shortname' => manager::COURSECREATOR_ROLE]);
    $ok((bool) $role, 'Role "' . manager::COURSECREATOR_ROLE . '" exists', $role ? "role #{$role->id}" : 'missing: re-run the plugin upgrade');

    if ($role) {
        $allowed = $DB->record_exists('role_capabilities', ['roleid' => $role->id,
            'capability' => 'local/vikesa_coursewizard:createcourse', 'permission' => CAP_ALLOW,
            'contextid' => context_system::instance()->id]);
        $ok($allowed, 'Role allows local/vikesa_coursewizard:createcourse', $allowed ? 'yes' : 'no: run the plugin upgrade (version 2026101305)');
    }

    if ($rec && $role) {
        $catctx = context_coursecat::instance($rec->categoryid, IGNORE_MISSING);
        $assigned = $catctx && $DB->record_exists('role_assignments',
            ['roleid' => $role->id, 'userid' => $user->id, 'contextid' => $catctx->id]);
        $ok($assigned, 'Role assigned to this user on the institution category', $assigned ? 'yes' : 'no');
        $cap = $catctx && has_capability('local/vikesa_coursewizard:createcourse', $catctx, $user->id);
        $ok((bool) $cap, 'User has capability local/vikesa_coursewizard:createcourse there', $cap ? 'yes' : 'no');
    }

    $sync = $DB->get_record('local_vikesa_usersync', ['userid' => $user->id]);
    $ok((bool) $sync, 'Last synced state', $sync ? "institution=\"{$sync->institution}\" jobtitle=\"{$sync->jobtitle}\"" : 'never synced');

    $table = new html_table();
    $table->head = ['', 'Check', 'Value'];
    $table->data = $rows;
    echo html_writer::table($table);

    echo $OUTPUT->single_button(new moodle_url($self, ['lookup' => $lookup, 'fix' => 1, 'sesskey' => sesskey()]),
        get_string('diagnose_fix', 'local_vikesa_coursewizard'), 'post');
    echo html_writer::tag('p', get_string('diagnose_hint', 'local_vikesa_coursewizard'), ['class' => 'mt-3 text-muted']);
}

echo $OUTPUT->footer();
