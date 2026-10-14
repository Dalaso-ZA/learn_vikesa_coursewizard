<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_vikesa_coursewizard_search_users' => [
        'classname'   => 'local_vikesa_coursewizard\external\search_users',
        'methodname'  => 'execute',
        'description' => 'Institution-scoped user search for the course wizard pickers',
        'type'        => 'read',
        'ajax'        => true,
    ],
];
