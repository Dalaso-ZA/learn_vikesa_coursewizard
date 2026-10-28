<?php

require_once(__DIR__ . '/../../config.php');

require_once($CFG->libdir . '/filelib.php');

use local_vikesa_coursewizard\manager;

require_login();

global $DB, $USER;

// Only files listed here can be downloaded, so the parameter is never used as a path.
$examples = [
    'example-course.txt' => ['path' => 'examples/example-course.txt', 'mime' => 'text/plain'],
    'section-2-example.png' => ['path' => 'examples/section-2-example.png', 'mime' => 'image/png'],
];

$file = required_param('file', PARAM_FILE);
if (!isset($examples[$file])) {
    throw new \moodle_exception('filenotfound', 'error');
}

// Same people as the course wizard: whoever can create courses for their institution.
$institution = manager::get_user_institution($USER->id);
$institutionrec = $institution ? $DB->get_record('local_vikesa_institution', ['institution' => $institution]) : null;
if (!$institutionrec) {
    throw new \moodle_exception('nouninstitution', 'local_vikesa_coursewizard');
}
require_capability('local/vikesa_coursewizard:createcourse', context_coursecat::instance($institutionrec->categoryid));

send_file(__DIR__ . '/' . $examples[$file]['path'], $file, 0, 0, true, false, $examples[$file]['mime']);
