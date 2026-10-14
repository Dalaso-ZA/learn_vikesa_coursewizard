<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_vikesa_coursewizard\manager;
use local_vikesa_coursewizard\form\create_course_form;

require_login();

global $DB, $USER, $OUTPUT, $PAGE;

$institution = manager::get_user_institution($USER->id);
if (!$institution) {
    throw new \moodle_exception('nouninstitution', 'local_vikesa_coursewizard');
}

$institutionrec = $DB->get_record('local_vikesa_institution', ['institution' => $institution]);
if (!$institutionrec || $institutionrec->responsiblejobtitle === '') {
    throw new \moodle_exception('institutionnotconfigured', 'local_vikesa_coursewizard');
}

$context = context_coursecat::instance($institutionrec->categoryid);
require_capability('local/vikesa_coursewizard:createcourse', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/vikesa_coursewizard/create_course.php'));
$PAGE->set_title(get_string('createcourse', 'local_vikesa_coursewizard'));
$PAGE->set_pagelayout('standard');

$form = new create_course_form(null, ['institutionrec' => $institutionrec]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/index.php', ['categoryid' => $institutionrec->categoryid]));
}

if ($data = $form->get_data()) {

    $courseentries = corelib_resolve_course_entries($form, $data);

    if (empty($courseentries)) {
        \core\notification::error(get_string('bulkfileempty', 'local_vikesa_coursewizard'));
    } else {
        $contentfilesmap = corelib_resolve_content_files($form);
        $mediafilesmap = corelib_resolve_media_files($form);
        $singlecontent = corelib_resolve_single_content($form);

        $createdids = [];
        $contentwarnings = [];

        foreach ($courseentries as $entry) {
            $courseid = corelib_create_and_enrol_course($institutionrec, $entry['name'], $data);
            $createdids[] = $courseid;

            $outlinetext = null;
            if (!empty($entry['contentfile']) && isset($contentfilesmap[$entry['contentfile']])) {
                $outlinetext = $contentfilesmap[$entry['contentfile']];
            } else if (count($courseentries) === 1 && $singlecontent !== null) {
                $outlinetext = $singlecontent;
            }

            if ($outlinetext !== null) {
                $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
                $sections = \local_vikesa_coursewizard\content_importer::parse_outline($outlinetext);
                $warnings = \local_vikesa_coursewizard\content_importer::apply_content($course, $sections, $mediafilesmap);
                foreach ($warnings as $w) {
                    $contentwarnings[] = "{$entry['name']}: {$w}";
                }
            }

            try {
                $finalcourse = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
                manager::setup_completion_criteria($finalcourse);
            } catch (\Throwable $e) {
                $contentwarnings[] = "{$entry['name']}: completion criteria setup failed ({$e->getMessage()}).";
            }
        }

        foreach ($contentwarnings as $w) {
            \core\notification::warning($w);
        }

        if (count($createdids) === 1) {
            redirect(new moodle_url('/course/view.php', ['id' => $createdids[0]]),
                get_string('coursecreated', 'local_vikesa_coursewizard'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }

        redirect(new moodle_url('/course/index.php', ['categoryid' => $institutionrec->categoryid]),
            get_string('bulkcoursescreated', 'local_vikesa_coursewizard', count($createdids)), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();

/**
 * One course entry (from the text field) or many (from the uploaded
 * courses file). Each bulk line may optionally have a second CSV column:
 * coursename,contentfile -- the filename must exactly match one of the
 * files uploaded in "contentfiles" below. A lone header line ("coursename"
 * or "coursename,contentfile") is skipped so a spreadsheet export can be
 * used as-is.
 *
 * @return array of ['name' => string, 'contentfile' => ?string]
 */
function corelib_resolve_course_entries(create_course_form $form, stdClass $data): array {
    $files = $form->get_uploaded_files('coursesfile');

    if (empty($files)) {
        $name = trim((string) $data->fullname);
        return $name === '' ? [] : [['name' => $name, 'contentfile' => null]];
    }

    $content = '';
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            $content = $file->get_content();
            break;
        }
    }

    $entries = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $columns = str_getcsv($line);
        $name = trim($columns[0] ?? '');
        if ($name === '' || strcasecmp($name, 'coursename') === 0) {
            continue;
        }
        $contentfile = trim($columns[1] ?? '');
        $entries[] = ['name' => $name, 'contentfile' => $contentfile !== '' ? $contentfile : null];
    }
    return $entries;
}

/**
 * Filename -> file content, for every file uploaded via the bulk
 * "contentfiles" filemanager.
 */
function corelib_resolve_content_files(create_course_form $form): array {
    $map = [];
    foreach ($form->get_uploaded_files('contentfiles') as $file) {
        if (!$file->is_directory()) {
            $map[$file->get_filename()] = $file->get_content();
        }
    }
    return $map;
}

/**
 * Filename -> stored_file object, for every file uploaded via "mediafiles".
 * Kept as stored_file (not read into a string) since #FILE blocks need to
 * copy the actual binary file into a new draft area, not just its content.
 */
function corelib_resolve_media_files(create_course_form $form): array {
    $map = [];
    foreach ($form->get_uploaded_files('mediafiles') as $file) {
        if (!$file->is_directory()) {
            $map[$file->get_filename()] = $file;
        }
    }
    return $map;
}

/**
 * Content for the single-course path's own "contentfile" filepicker.
 */
function corelib_resolve_single_content(create_course_form $form): ?string {
    foreach ($form->get_uploaded_files('contentfile') as $file) {
        if (!$file->is_directory()) {
            return $file->get_content();
        }
    }
    return null;
}

/**
 * Creates one course under the institution's category and applies exactly
 * the same enrolment rules the single-course path always used: audience
 * pills, the automatic responsible-jobtitle Teacher cohort, ad-hoc
 * teachers, and individual access. Used for both the single-course and
 * bulk-upload paths so behaviour never diverges between them.
 */
function corelib_create_and_enrol_course(stdClass $institutionrec, string $coursename, stdClass $data): int {
    global $DB;

    $newcourse = new stdClass();
    $newcourse->fullname = $coursename;
    $newcourse->shortname = corelib_generate_unique_shortname($institutionrec->institution, $coursename);
    $newcourse->category = $institutionrec->categoryid;
    $newcourse->visible = 1;
    $newcourse->enablecompletion = 1;
    $course = create_course($newcourse);

    // Invited-only: guest access and self-enrolment would let anyone in.
    foreach (enrol_get_instances($course->id, false) as $instance) {
        if (in_array($instance->enrol, ['guest', 'self'], true)) {
            enrol_get_plugin($instance->enrol)->update_status($instance, ENROL_INSTANCE_DISABLED);
        }
    }

    $renewalvalue = !empty($data->renewalvalue) ? (int) $data->renewalvalue : null;
    $renewalunit = $renewalvalue !== null ? ($data->renewalunit ?? 'month') : null;
    manager::set_course_renewal($course->id, $renewalvalue, $renewalunit);

    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

    $cohortenrol = enrol_get_plugin('cohort');
    $manualenrol = enrol_get_plugin('manual');

    // Audience pills -> cohort-sync enrolment as Student.
    foreach ((array) $data->jobtitles as $jobtitle) {
        $cohortid = manager::ensure_cohort($institutionrec, $jobtitle);
        $cohortenrol->add_instance($course, [
            'customint1' => $cohortid,
            'roleid' => $studentroleid,
        ]);
    }

    // Fixed "responsible" jobtitle -> always Teacher, automatic, not a pill.
    $responsiblecohortid = manager::ensure_cohort($institutionrec, $institutionrec->responsiblejobtitle);
    $cohortenrol->add_instance($course, [
        'customint1' => $responsiblecohortid,
        'roleid' => $teacherroleid,
    ]);

    // Ad-hoc teachers -> manual enrolment as Teacher, this course only.
    if (!empty($data->teachers)) {
        $manualinstance = corelib_ensure_manual_instance($course);
        foreach ((array) $data->teachers as $userid) {
            $manualenrol->enrol_user($manualinstance, (int) $userid, $teacherroleid);
        }
    }

    // Individual access -> manual enrolment as Student, bypasses pills.
    if (!empty($data->individualaccess)) {
        $manualinstance = corelib_ensure_manual_instance($course);
        foreach ((array) $data->individualaccess as $userid) {
            $manualenrol->enrol_user($manualinstance, (int) $userid, $studentroleid);
        }
    }

    return $course->id;
}

/**
 * Turns a course name into a unique shortname; Moodle requires shortname
 * uniqueness sitewide, but the wizard only ever asks for fullname.
 * Format: institution-coursename. On a collision (same institution, same
 * course name) we append a short opaque id rather than -2/-3, so it reads
 * as "a course with an id" rather than "did I submit this twice?".
 */
function corelib_generate_unique_shortname(string $institution, string $fullname): string {
    global $DB;

    $slug = function(string $text): string {
        $text = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $text));
        return substr(preg_replace('/\s+/', '-', $text), 0, 60) ?: 'x';
    };

    $base = $slug($institution) . '-' . $slug($fullname);
    if (!$DB->record_exists('course', ['shortname' => $base])) {
        return $base;
    }

    do {
        $candidate = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    } while ($DB->record_exists('course', ['shortname' => $candidate]));

    return $candidate;
}

function corelib_ensure_manual_instance(stdClass $course) {
    global $DB;
    $manualenrol = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instanceid = $manualenrol->add_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
    }
    return $instance;
}
