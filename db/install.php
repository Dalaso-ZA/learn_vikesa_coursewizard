<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_vikesa_coursewizard_install() {
    global $DB;

    // Moodle registers a plugin's db/access.php capabilities AFTER running
    // install.php on a fresh install, so the capability we need below
    // doesn't exist yet unless we force the sync ourselves first.
    update_capabilities('local_vikesa_coursewizard');

    if (!$DB->record_exists('role', ['shortname' => 'vikesacoursecreator'])) {
        $roleid = create_role(
            'Vikesa course creator',
            'vikesacoursecreator',
            'Assigned automatically to the one jobtitle per institution allowed to run the Vikesa course wizard for that institution\'s category.',
            'manager'
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        assign_capability('local/vikesa_coursewizard:createcourse', CAP_ALLOW, $roleid, context_system::instance()->id, true);
    }
}
