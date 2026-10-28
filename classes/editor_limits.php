<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

/**
 * What editing teachers in Vikesa courses can change. Administrators are not
 * affected: they keep the full Moodle editing experience.
 *
 * - Course settings: only the name, the summary and the course image are shown.
 * - Course administration menu: reduced to the entries editors need (see
 *   RESTRICTED_CAPABILITIES).
 * - Activities: only Page, Label, File and Quiz can be added. Other activity
 *   types are switched off for the editing-teacher role in that course only.
 *   Existing activities of any type can still be edited.
 */
class editor_limits {

    /** Activity modules editors may still add. */
    const ALLOWED_ACTIVITIES = ['page', 'label', 'resource', 'quiz'];

    /**
     * Course administration menu entries editors lose. Each one maps to a
     * capability that drives the menu (course_get_user_administration_options),
     * so removing the capability removes the entry and the action itself.
     * Course settings, grades and outcomes stay available.
     */
    const RESTRICTED_CAPABILITIES = [
        'moodle/filter:manage',          // Filters
        'moodle/site:viewreports',       // Reports
        'moodle/backup:backupcourse',    // Backup
        'moodle/restore:restorecourse',  // Restore
        'moodle/restore:restoretargetimport', // Import
        'moodle/course:tag',             // Tags
        'moodle/course:reset',           // Reset
        'moodle/role:switchroles',       // Switch role
    ];

    /** Course settings fields that stay visible to editors (plus buttons and hidden fields). */
    const KEEP_FORM_ELEMENTS = ['fullname', 'summary_editor', 'overviewfiles_filemanager', 'buttonar'];

    public static function is_vikesa_course(int $courseid): bool {
        global $DB;
        $categoryid = (int) $DB->get_field('course', 'category', ['id' => $courseid]);
        return $categoryid > 0 && $DB->record_exists('local_vikesa_institution', ['categoryid' => $categoryid]);
    }

    /** Applies the activity restriction to one course. Safe to call again. */
    public static function apply_to_course(int $courseid): void {
        global $DB;

        if (!self::is_vikesa_course($courseid)) {
            return;
        }
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        if (!$roleid) {
            return;
        }

        $context = \context_course::instance($courseid);
        foreach (self::RESTRICTED_CAPABILITIES as $capability) {
            if (get_capability_info($capability)) {
                assign_capability($capability, CAP_PREVENT, $roleid, $context->id, true);
            }
        }
        foreach (array_keys(\core_component::get_plugin_list('mod')) as $modname) {
            if (in_array($modname, self::ALLOWED_ACTIVITIES, true)) {
                continue;
            }
            $capability = "mod/{$modname}:addinstance";
            if (get_capability_info($capability)) {
                assign_capability($capability, CAP_PREVENT, $roleid, $context->id, true);
            }
        }
        $context->mark_dirty();
    }

    /**
     * Applies the restriction to every course in every institution.
     *
     * @return int number of courses processed
     */
    public static function apply_to_all(): int {
        global $DB;

        $count = 0;
        foreach ($DB->get_fieldset_select('local_vikesa_institution', 'categoryid', '1=1') as $categoryid) {
            foreach ($DB->get_fieldset_select('course', 'id', 'category = ? AND id <> ?', [$categoryid, SITEID]) as $courseid) {
                self::apply_to_course((int) $courseid);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Hides everything from the course settings form except what editors may
     * change. Fields are hidden in the browser rather than removed from the
     * form, so Moodle still finds them on save and keeps their current values.
     * Removing elements made Moodle's form code fail with a PEAR error.
     */
    public static function simplify_course_form(\MoodleQuickForm $mform): void {
        if (is_siteadmin() || !$mform->elementExists('fullname')) {
            return;
        }

        // Group elements under their header. A header hides everything under it
        // in the browser, so a header is only hidden when none of its fields is kept.
        $sections = [];
        $current = '';
        foreach ($mform->_elements as $element) {
            $name = $element->getName();
            if ($element->getType() === 'header') {
                $current = $name;
                $sections[$current] = false;
                continue;
            }
            if ($name !== null && in_array($name, self::KEEP_FORM_ELEMENTS, true) && $current !== '') {
                $sections[$current] = true;
            }
        }
        foreach ($sections as $header => $keep) {
            if (!$keep) {
                $mform->hideIf($header, 'fullname', 'neq', '__vikesa_never__');
            }
        }

        foreach (array_keys($mform->_elementIndex) as $name) {
            if (in_array($name, self::KEEP_FORM_ELEMENTS, true)) {
                continue;
            }
            // Hidden fields, buttons and headers are handled separately.
            if (in_array($mform->getElementType($name), ['hidden', 'submit', 'cancel', 'header'], true)) {
                continue;
            }
            // Condition that is never true, so the element is always hidden.
            $mform->hideIf($name, 'fullname', 'neq', '__vikesa_never__');
        }
    }

    /**
     * CSS for the course settings form: hides every row and section, then
     * shows only the fields editors may change. Added in the page head so it
     * applies before the form is drawn.
     */
    public static function course_form_css(): string {
        $rows = [];
        $sections = [];
        foreach (self::KEEP_FORM_ELEMENTS as $name) {
            if ($name === 'buttonar') {
                $rows[] = '#mform1 [id^="fgroup_id_buttonar"]';
                $sections[] = '#mform1 fieldset:has([id^="fgroup_id_buttonar"])';
                continue;
            }
            $rows[] = '#mform1 #fitem_id_' . $name;
            $sections[] = '#mform1 fieldset:has(#fitem_id_' . $name . ')';
        }
        return '<style>'
            . '#mform1 .fitem, #mform1 fieldset { display: none !important; }'
            . implode(', ', $sections) . ' { display: block !important; }'
            . implode(', ', $rows) . ' { display: block !important; }'
            . '</style>';
    }

    /**
     * Names of course settings fields an editor tried to change that they are
     * not allowed to change. Compared against the stored course, so a change
     * made in the browser (for example by editing a hidden field) is caught on save.
     *
     * @param \stdClass $course the course being edited (stored values)
     * @param array $data the submitted form data
     * @return string[] field names
     */
    public static function restricted_changes(\stdClass $course, array $data): array {
        $changed = [];
        foreach ($data as $name => $value) {
            if (in_array($name, self::KEEP_FORM_ELEMENTS, true)) {
                continue;
            }
            // Submit buttons, hidden technical fields and anything the course does not store.
            if (str_starts_with($name, 'submitbutton') || in_array($name, ['cancel', 'returnto', 'sesskey', 'id'], true)) {
                continue;
            }
            if (!property_exists($course, $name) || is_array($value) || is_object($value)) {
                continue;
            }
            if ((string) $value !== (string) $course->$name) {
                $changed[] = $name;
            }
        }
        return $changed;
    }
}
