<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function user_updated(\core\event\base $event): void {
        $userid = $event->relateduserid ?: $event->userid;
        if (!$userid) {
            return;
        }
        // Runs inline with the request that triggered it (e.g. the OAuth2
        // login claim sync), which is what makes this feel "instant" rather
        // than waiting for the nightly resync task.
        manager::sync_user((int) $userid);
    }

    /** OAuth2 login: fetch that user's institution / job title from Casdoor. */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        casdoor::pull_user((int) $event->objectid);
    }

    /** New courses get the editor limits from the moment they exist. */
    public static function course_created(\core\event\course_created $event): void {
        \local_vikesa_coursewizard\editor_limits::apply_to_course((int) $event->courseid);
    }
}
