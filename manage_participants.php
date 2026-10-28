<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_vikesa_coursewizard\manager;
use local_vikesa_coursewizard\form\manage_participants_form;

$courseid = required_param('courseid', PARAM_INT);
$removejobtitle = optional_param('remove', '', PARAM_TEXT);

require_login($courseid);

global $DB, $OUTPUT, $PAGE;

$course = get_course($courseid);
$coursecontext = context_course::instance($courseid);

// Anyone holding editing-teacher-level capability in THIS course can manage
// its participants -- not just the institution's "responsible" jobtitle.
// This is deliberately broader than the wizard/creation capability: any
// teacher on the course (including ad-hoc ones added later) needs to be
// able to fix enrolment without waiting on the one responsible person.
require_capability('moodle/course:manageactivities', $coursecontext);

$institutionrec = $DB->get_record('local_vikesa_institution', ['categoryid' => $course->category]);
if (!$institutionrec) {
    throw new moodle_exception('institutionnotconfigured', 'local_vikesa_coursewizard');
}

$PAGE->set_context($coursecontext);
$PAGE->set_url(new moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('manageparticipants', 'local_vikesa_coursewizard'));
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);

if ($removejobtitle !== '' && confirm_sesskey()) {
    $idnumber = manager::cohort_idnumber($institutionrec->institution, $removejobtitle);
    $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber]);
    if ($cohort) {
        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid, 'enrol' => 'cohort', 'customint1' => $cohort->id,
        ]);
        if ($instance) {
            $cohortenrol = enrol_get_plugin('cohort');
            $cohortenrol->delete_instance($instance);
        }
    }
    redirect(new moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $courseid]),
        get_string('audienceremoved', 'local_vikesa_coursewizard'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Per-person actions for people added by hand (manual enrolment). Access
// through a job title is set for the whole course and is not changed here.
$personaction = optional_param('action', '', PARAM_ALPHA);
$personid = optional_param('userid', 0, PARAM_INT);
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
if ($personaction !== '' && $personid > 0 && confirm_sesskey()) {
    $manualinstance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
    $isbyhand = $manualinstance && $DB->record_exists('user_enrolments', ['enrolid' => $manualinstance->id, 'userid' => $personid]);
    // Nobody removes or demotes themselves here; that would lock them out of the course.
    if ($isbyhand && $personid !== (int) $USER->id) {
        $manualenrol = enrol_get_plugin('manual');
        if ($personaction === 'remove') {
            $manualenrol->unenrol_user($manualinstance, $personid);
            $message = get_string('userremoved', 'local_vikesa_coursewizard');
        } else if ($personaction === 'toteacher' || $personaction === 'tolearner') {
            $from = $personaction === 'toteacher' ? $studentroleid : $teacherroleid;
            $to = $personaction === 'toteacher' ? $teacherroleid : $studentroleid;
            role_unassign($from, $personid, $coursecontext->id, 'enrol_manual', $manualinstance->id);
            role_assign($to, $personid, $coursecontext->id, 'enrol_manual', $manualinstance->id);
            $message = get_string('rolechanged', 'local_vikesa_coursewizard');
        } else {
            $message = null;
        }
        if ($message !== null) {
            redirect(new moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $courseid]),
                $message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

$form = new manage_participants_form(null, ['institutionrec' => $institutionrec, 'course' => $course]);

if ($data = $form->get_data()) {
    $cohortenrol = enrol_get_plugin('cohort');
    $manualenrol = enrol_get_plugin('manual');
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

    if (!empty($data->addjobtitle)) {
        $cohortid = manager::ensure_cohort($institutionrec, $data->addjobtitle);
        $cohortenrol->add_instance($course, ['customint1' => $cohortid, 'roleid' => $studentroleid]);
    }

    if (!empty($data->addteachers)) {
        $manualinstance = corelib_ensure_manual_instance_for($course);
        foreach ((array) $data->addteachers as $userid) {
            $manualenrol->enrol_user($manualinstance, (int) $userid, $teacherroleid);
        }
    }

    if (!empty($data->addindividuals)) {
        $manualinstance = corelib_ensure_manual_instance_for($course);
        foreach ((array) $data->addindividuals as $userid) {
            $manualenrol->enrol_user($manualinstance, (int) $userid, $studentroleid);
        }
    }

    redirect(new moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $courseid]),
        get_string('changesapplied', 'local_vikesa_coursewizard'), null, \core\output\notification::NOTIFY_SUCCESS);
}

function corelib_ensure_manual_instance_for(stdClass $course) {
    global $DB;
    $manualenrol = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instanceid = $manualenrol->add_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
    }
    return $instance;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageparticipants', 'local_vikesa_coursewizard'));

echo html_writer::tag('h4', get_string('currentparticipants', 'local_vikesa_coursewizard'));
$enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
if (empty($enrolledusers)) {
    echo html_writer::tag('p', get_string('noparticipants', 'local_vikesa_coursewizard'));
} else {
    $manualinstance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
    $byhand = [];
    if ($manualinstance) {
        $byhand = $DB->get_fieldset_select('user_enrolments', 'userid', 'enrolid = ?', [$manualinstance->id]);
    }

    $table = new html_table();
    $table->head = [get_string('fullname'), get_string('email'), get_string('roles', 'core_role'),
        get_string('howenrolled', 'local_vikesa_coursewizard'), get_string('actions', 'local_vikesa_coursewizard')];
    foreach ($enrolledusers as $enrolleduser) {
        $roles = get_user_roles($coursecontext, $enrolleduser->id, false);
        $rolenames = array_map(fn($r) => role_get_name($r, $coursecontext), $roles);

        $isbyhand = in_array($enrolleduser->id, $byhand);
        $how = $isbyhand ? get_string('enrolledbyhand', 'local_vikesa_coursewizard')
            : get_string('enrolledbyaudience', 'local_vikesa_coursewizard');

        $actions = '';
        if ($isbyhand && (int) $enrolleduser->id !== (int) $USER->id) {
            $isteacher = in_array('editingteacher', array_map(fn($r) => $r->shortname, $roles));
            $base = ['courseid' => $courseid, 'userid' => $enrolleduser->id, 'sesskey' => sesskey()];
            $actions .= html_writer::link(new moodle_url('/local/vikesa_coursewizard/manage_participants.php',
                $base + ['action' => $isteacher ? 'tolearner' : 'toteacher']),
                get_string($isteacher ? 'makelearner' : 'maketeacher', 'local_vikesa_coursewizard'), ['class' => 'mr-2']);
            $actions .= html_writer::link(new moodle_url('/local/vikesa_coursewizard/manage_participants.php',
                $base + ['action' => 'remove']), get_string('remove'));
        }

        $table->data[] = [fullname($enrolleduser), s($enrolleduser->email), implode(', ', $rolenames), $how, $actions];
    }
    echo html_writer::table($table);
}

$attached = manager::get_attached_jobtitle_cohorts($courseid, $institutionrec);
if (!empty($attached)) {
    echo html_writer::tag('h4', get_string('currentaudiences', 'local_vikesa_coursewizard'));
    $list = [];
    foreach ($attached as $jobtitle) {
        $removeurl = new moodle_url('/local/vikesa_coursewizard/manage_participants.php',
            ['courseid' => $courseid, 'remove' => $jobtitle, 'sesskey' => sesskey()]);
        $list[] = s($jobtitle) . ' (' . html_writer::link($removeurl, get_string('remove')) . ')';
    }
    echo html_writer::alist($list);
} else {
    echo html_writer::tag('p', get_string('noaudiencesattached', 'local_vikesa_coursewizard'));
}

$form->display();
echo $OUTPUT->footer();
