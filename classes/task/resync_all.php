<?php

namespace local_vikesa_coursewizard\task;

defined('MOODLE_INTERNAL') || die();

class resync_all extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_resync_all', 'local_vikesa_coursewizard');
    }

    public function execute(): void {
        \local_vikesa_coursewizard\casdoor::pull_all();
        \local_vikesa_coursewizard\manager::resync_all();
    }
}
