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

    /**
     * Course settings for editors in Vikesa courses: only name, summary and
     * course image stay. Administrators keep the full form.
     */
    public static function course_form_definition(\core_course\hook\after_form_definition $hook): void {
        $course = $hook->formwrapper->get_course();
        if (empty($course->id) || !editor_limits::is_vikesa_course((int) $course->id)) {
            return;
        }
        editor_limits::simplify_course_form($hook->mform);
    }

    /**
     * Hides the course settings form before the page is drawn, so the full
     * form never flashes before the browser applies the editor limits. Only
     * the fields editors may change are shown.
     */
    public static function course_form_head(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE;

        if (empty($PAGE->url) || $PAGE->url->out_omit_querystring() !== (new \moodle_url('/course/edit.php'))->out_omit_querystring()) {
            return;
        }
        if (is_siteadmin() || empty($PAGE->course) || $PAGE->course->id == SITEID) {
            return;
        }
        if (!editor_limits::is_vikesa_course((int) $PAGE->course->id)) {
            return;
        }
        $hook->add_html(editor_limits::course_form_css());
    }

    /**
     * Rejects a course settings save from an editor that changes a field they
     * may not change. Works on the server, so it also covers edited pages.
     */
    public static function course_form_validation(\core_course\hook\after_form_validation $hook): void {
        $course = $hook->formwrapper->get_course();
        if (empty($course->id) || is_siteadmin() || !editor_limits::is_vikesa_course((int) $course->id)) {
            return;
        }
        $changed = editor_limits::restricted_changes($course, $hook->get_data());
        if ($changed) {
            $hook->add_errors(['fullname' => get_string('editorlimitchanged', 'local_vikesa_coursewizard',
                implode(', ', $changed))]);
        }
    }
}
