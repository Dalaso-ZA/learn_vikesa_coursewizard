<?php

defined('MOODLE_INTERNAL') || die();

// Verified against the Moodle 5.1 source: secondary_extend is dispatched at
// the end of secondary::load_course_navigation(), AFTER the native
// "participants" node has been cloned into the secondary view -- which is
// the only point where that tab can actually be modified.
$callbacks = [
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => [\local_vikesa_coursewizard\hook_callbacks::class, 'extend_secondary_navigation'],
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => [\local_vikesa_coursewizard\hook_callbacks::class, 'course_form_head'],
    ],
    [
        'hook' => \core_course\hook\after_form_validation::class,
        'callback' => [\local_vikesa_coursewizard\hook_callbacks::class, 'course_form_validation'],
    ],
    [
        'hook' => \core_course\hook\after_form_definition::class,
        'callback' => [\local_vikesa_coursewizard\hook_callbacks::class, 'course_form_definition'],
    ],
];
