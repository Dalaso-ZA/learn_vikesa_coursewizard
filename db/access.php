<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/vikesa_coursewizard:createcourse' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [],
        // Not granted to any archetype by default: only assigned programmatically
        // by the sync task/observer to users whose jobtitle matches their
        // institution's "responsible" jobtitle, scoped to that one category.
    ],
];
