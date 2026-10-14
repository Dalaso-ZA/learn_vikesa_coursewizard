<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

class hook_callbacks {

    /**
     * Repoints the course's native "Participants" tab at our institution-
     * scoped manage-participants page, for Vikesa courses only and only for
     * users who can actually manage that course. Everyone else (students,
     * non-Vikesa courses) keeps the untouched native tab.
     *
     * Verified in the 5.1 source (secondary::get_default_course_mapping):
     * the node key is 'participants'. It is a clone of the global-nav node,
     * so we change the action on the clone in the secondary view -- which
     * is also why tab highlighting works on our page (the URL matches).
     */
    public static function extend_secondary_navigation(\core\hook\navigation\secondary_extend $hook): void {
        global $PAGE, $DB;

        $course = $PAGE->course;
        if (empty($course) || $course->id == SITEID) {
            return;
        }

        $coursecontext = \context_course::instance($course->id);
        if (!has_capability('moodle/course:manageactivities', $coursecontext)) {
            return;
        }

        if (!$DB->record_exists('local_vikesa_institution', ['categoryid' => $course->category])) {
            return;
        }

        $view = $hook->get_secondaryview();
        $node = $view->get('participants');
        if (!$node) {
            // Native tab not present (e.g. hidden by a capability or theme
            // setting): add our own entry instead of silently doing nothing.
            $view->add(
                get_string('manageparticipants', 'local_vikesa_coursewizard'),
                new \moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $course->id]),
                \navigation_node::TYPE_CUSTOM,
                null,
                'vikesamanageparticipants'
            );
            return;
        }

        $node->action = new \moodle_url('/local/vikesa_coursewizard/manage_participants.php', ['courseid' => $course->id]);
        $node->text = get_string('participants');
    }
}
