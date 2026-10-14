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
    $table = new html_table();
    $table->head = [get_string('fullname'), get_string('email'), get_string('roles', 'core_role')];
    foreach ($enrolledusers as $enrolleduser) {
        $roles = get_user_roles($coursecontext, $enrolleduser->id, false);
        $rolenames = array_map(fn($r) => $r->shortname, $roles);
        $table->data[] = [fullname($enrolleduser), s($enrolleduser->email), implode(', ', $rolenames)];
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
