<?php

namespace local_vikesa_coursewizard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_vikesa_usersync', [
            'userid' => 'privacy:metadata:local_vikesa_usersync:userid',
            'institution' => 'privacy:metadata:local_vikesa_usersync:institution',
            'jobtitle' => 'privacy:metadata:local_vikesa_usersync:jobtitle',
        ], 'privacy:metadata:local_vikesa_usersync');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        // The sync record is keyed on the user directly with no course/module
        // context of its own -- export it against the system context.
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        $record = $DB->get_record('local_vikesa_usersync', ['userid' => $user->id]);
        if (!$record) {
            return;
        }

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_vikesa_coursewizard')],
            (object) [
                'institution' => $record->institution,
                'jobtitle' => $record->jobtitle,
                'timemodified' => \core_privacy\local\request\transform::datetime($record->timemodified),
            ]
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        // No course/module-scoped data to delete here.
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $DB->delete_records('local_vikesa_usersync', ['userid' => $contextlist->get_user()->id]);
    }
}
