<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\user_updated',
        'callback'    => '\local_vikesa_coursewizard\observer::user_updated',
    ],
    [
        'eventname'   => '\core\event\user_created',
        'callback'    => '\local_vikesa_coursewizard\observer::user_updated',
    ],
    [
        'eventname'   => '\core\event\user_loggedin',
        'callback'    => '\local_vikesa_coursewizard\observer::user_loggedin',
    ],
];
