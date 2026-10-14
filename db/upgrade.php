<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_vikesa_coursewizard_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092600) {
        $table = new xmldb_table('local_vikesa_course_meta');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('renewalmonths', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092600, 'local', 'vikesa_coursewizard');
    }

    if ($oldversion < 2026092700) {
        $table = new xmldb_table('local_vikesa_course_meta');

        $valuefield = new xmldb_field('renewalvalue', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'renewalmonths');
        if (!$dbman->field_exists($table, $valuefield)) {
            $dbman->add_field($table, $valuefield);
        }

        $unitfield = new xmldb_field('renewalunit', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'renewalvalue');
        if (!$dbman->field_exists($table, $unitfield)) {
            $dbman->add_field($table, $unitfield);
        }

        // Migrate any existing renewalmonths values so nothing already set
        // silently disappears.
        $existing = $DB->get_records_select('local_vikesa_course_meta', 'renewalmonths IS NOT NULL AND renewalvalue IS NULL');
        foreach ($existing as $record) {
            $record->renewalvalue = $record->renewalmonths;
            $record->renewalunit = 'month';
            $DB->update_record('local_vikesa_course_meta', $record);
        }

        upgrade_plugin_savepoint(true, 2026092700, 'local', 'vikesa_coursewizard');
    }

    if ($oldversion < 2026101300) {
        $table = new xmldb_table('local_vikesa_institution');
        foreach (['logourl' => 'responsiblejobtitle', 'faviconurl' => 'logourl'] as $name => $previous) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, '1333', null, null, null, null, $previous);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026101300, 'local', 'vikesa_coursewizard');
    }

    if ($oldversion < 2026101305) {
        // The role may have been created before the capability existed, in
        // which case install.php's grant was skipped. Make sure the role
        // exists, can be assigned on categories, and allows the capability.
        update_capabilities('local_vikesa_coursewizard');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'vikesacoursecreator']);
        if (!$roleid) {
            $roleid = create_role(
                'Vikesa course creator',
                'vikesacoursecreator',
                'Assigned automatically to the one jobtitle per institution allowed to run the Vikesa course wizard for that institution\'s category.',
                'manager'
            );
        }
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        assign_capability('local/vikesa_coursewizard:createcourse', CAP_ALLOW, $roleid,
            context_system::instance()->id, true);

        upgrade_plugin_savepoint(true, 2026101305, 'local', 'vikesa_coursewizard');
    }

    return true;
}
