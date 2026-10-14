<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

// cohort/lib.php is NOT autoloaded by Moodle core on every request (unlike
// classes). This manager is invoked from paths that never happen to load it
// on their own -- e.g. saving a user's profile -- so without this, calls
// like cohort_add_cohort() below fail with "undefined function" even though
// the function exists, simply because the file defining it was never
// required on that request.
require_once($GLOBALS['CFG']->dirroot . '/cohort/lib.php');

/**
 * All the "institution + jobtitle -> category + cohort + role" plumbing
 * lives here, used by both the event observer (near-instant path) and the
 * nightly resync task (safety net for anything the observer missed, e.g.
 * bulk imports that don't fire per-user events).
 */
class manager {

    const COURSECREATOR_ROLE = 'vikesacoursecreator';

    /**
     * Read a user's jobtitle from the custom profile field.
     * Casdoor's "Title" claim is mapped here via the OAuth2 field mapping.
     */
    public static function get_user_jobtitle(int $userid): ?string {
        global $DB;

        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid AND f.shortname = :shortname";
        $value = $DB->get_field_sql($sql, ['userid' => $userid, 'shortname' => 'jobtitle']);

        return $value !== false && $value !== '' ? $value : null;
    }

    /**
     * A user's institution comes from the core user field (already mapped
     * from Casdoor's "Company" claim via the OAuth2 field mapping).
     */
    public static function get_user_institution(int $userid): ?string {
        global $DB;
        $value = $DB->get_field('user', 'institution', ['id' => $userid]);
        return $value !== false && $value !== '' ? $value : null;
    }

    /**
     * Look up the institution -> category/responsible-jobtitle mapping,
     * auto-provisioning a category (but NOT a responsible jobtitle -- an
     * admin must set that manually) the first time an institution is seen.
     */
    public static function get_or_create_institution_mapping(string $institution): \stdClass {
        global $DB;

        $existing = $DB->get_record('local_vikesa_institution', ['institution' => $institution]);
        if ($existing) {
            return $existing;
        }

        $category = \core_course_category::create([
            'name' => $institution,
            'idnumber' => self::category_idnumber($institution),
        ]);

        $record = new \stdClass();
        $record->institution = $institution;
        $record->categoryid = $category->id;
        $record->responsiblejobtitle = '';
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('local_vikesa_institution', $record);

        return $record;
    }

    protected static function category_idnumber(string $institution): string {
        // Keep it short and collision-resistant; idnumber has a length limit.
        return 'vikesa_' . substr(md5($institution), 0, 20);
    }

    /**
     * Ensure the cohort for (institution, jobtitle) exists in the
     * institution's category context, and return its id.
     */
    public static function ensure_cohort(\stdClass $institutionrec, string $jobtitle): int {
        global $DB;

        $idnumber = self::cohort_idnumber($institutionrec->institution, $jobtitle);
        $existing = $DB->get_record('cohort', ['idnumber' => $idnumber]);
        if ($existing) {
            return $existing->id;
        }

        $context = \context_coursecat::instance($institutionrec->categoryid);

        $cohort = new \stdClass();
        $cohort->contextid = $context->id;
        $cohort->name = $jobtitle . ' (' . $institutionrec->institution . ')';
        $cohort->idnumber = $idnumber;
        $cohort->description = 'Auto-managed by Vikesa course wizard. Do not rename the idnumber.';
        $cohort->descriptionformat = FORMAT_PLAIN;
        $cohort->component = 'local_vikesa_coursewizard';
        $cohort->visible = 0;

        return cohort_add_cohort($cohort);
    }

    public static function cohort_idnumber(string $institution, string $jobtitle): string {
        return 'vikesa:' . substr(md5($institution . '|' . $jobtitle), 0, 24);
    }

    /**
     * Diff a user's current institution/jobtitle against what we last
     * synced, and move cohort membership + the course-creator role
     * accordingly. Idempotent -- safe to call repeatedly.
     */
    public static function sync_user(int $userid): void {
        global $DB;

        $institution = self::get_user_institution($userid);
        $jobtitle = self::get_user_jobtitle($userid);

        $previous = $DB->get_record('local_vikesa_usersync', ['userid' => $userid]);

        $samestate = $previous
            && $previous->institution === (string) $institution
            && $previous->jobtitle === (string) $jobtitle;
        if ($samestate) {
            return;
        }

        // Leave the old cohort/role, if there was one and something changed.
        if ($previous && $previous->institution && $previous->jobtitle) {
            $oldinstitutionrec = $DB->get_record('local_vikesa_institution', ['institution' => $previous->institution]);
            if ($oldinstitutionrec) {
                self::remove_from_jobtitle_cohort($userid, $oldinstitutionrec, $previous->jobtitle);
                self::unassign_coursecreator_if_needed($userid, $oldinstitutionrec, $previous->jobtitle);
            }
        }

        // Join the new one.
        if ($institution && $jobtitle) {
            $institutionrec = self::get_or_create_institution_mapping($institution);
            $cohortid = self::ensure_cohort($institutionrec, $jobtitle);
            cohort_add_member($cohortid, $userid);

            if ($institutionrec->responsiblejobtitle !== ''
                    && $jobtitle === $institutionrec->responsiblejobtitle) {
                self::assign_coursecreator($userid, $institutionrec);
            }
        }

        $record = new \stdClass();
        $record->userid = $userid;
        $record->institution = (string) $institution;
        $record->jobtitle = (string) $jobtitle;
        $record->timemodified = time();

        if ($previous) {
            $record->id = $previous->id;
            $DB->update_record('local_vikesa_usersync', $record);
        } else {
            $DB->insert_record('local_vikesa_usersync', $record);
        }
    }

    /**
     * Call after an admin changes an institution's responsible job title.
     * sync_user() skips users whose institution/jobtitle did not change, so
     * without this, people who were already synced before the responsible
     * title was set (or changed) would never get / lose the course-creator
     * role.
     */
    public static function refresh_creator_roles(\stdClass $institutionrec, string $oldtitle): void {
        global $DB;

        $new = (string) $institutionrec->responsiblejobtitle;
        $rows = $DB->get_records('local_vikesa_usersync', ['institution' => $institutionrec->institution]);
        foreach ($rows as $row) {
            if ($oldtitle !== '' && $oldtitle !== $new && $row->jobtitle === $oldtitle) {
                self::unassign_coursecreator_if_needed((int) $row->userid, (object) [
                    'responsiblejobtitle' => $oldtitle,
                    'categoryid' => $institutionrec->categoryid,
                ], $oldtitle);
            }
            if ($new !== '' && $row->jobtitle === $new) {
                self::ensure_cohort($institutionrec, $new);
                self::assign_coursecreator((int) $row->userid, $institutionrec);
            }
        }
    }

    protected static function remove_from_jobtitle_cohort(int $userid, \stdClass $institutionrec, string $jobtitle): void {
        global $DB;
        $idnumber = self::cohort_idnumber($institutionrec->institution, $jobtitle);
        $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber]);
        if ($cohort) {
            cohort_remove_member($cohort->id, $userid);
        }
    }

    protected static function assign_coursecreator(int $userid, \stdClass $institutionrec): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => self::COURSECREATOR_ROLE], MUST_EXIST);
        $context = \context_coursecat::instance($institutionrec->categoryid);
        role_assign($roleid, $userid, $context->id);
    }

    protected static function unassign_coursecreator_if_needed(int $userid, \stdClass $institutionrec, string $oldjobtitle): void {
        global $DB;
        if ($institutionrec->responsiblejobtitle === '' || $oldjobtitle !== $institutionrec->responsiblejobtitle) {
            return;
        }
        $roleid = $DB->get_field('role', 'id', ['shortname' => self::COURSECREATOR_ROLE], MUST_EXIST);
        $context = \context_coursecat::instance($institutionrec->categoryid);
        role_unassign($roleid, $userid, $context->id);
    }

    /**
     * Full resync of every user -- catches bulk imports / direct DB syncs
     * that never fire a user_updated event.
     */
    public static function resync_all(): void {
        global $DB;
        $userids = $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 1');
        foreach ($userids as $userid) {
            self::sync_user($userid);
        }
    }

    /**
     * Jobtitle audiences currently attached to a course as cohort-sync
     * enrolment instances. Cohort idnumbers are a one-way hash (institution
     * + jobtitle), so rather than reversing them, this forward-computes the
     * expected idnumber for every known jobtitle and checks which ones
     * actually have a matching enrol instance on this course.
     */
    public static function get_attached_jobtitle_cohorts(int $courseid, \stdClass $institutionrec): array {
        global $DB;

        $attached = [];
        foreach (self::get_pillable_jobtitles($institutionrec) as $jobtitle) {
            $idnumber = self::cohort_idnumber($institutionrec->institution, $jobtitle);
            $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber]);
            if (!$cohort) {
                continue;
            }
            if ($DB->record_exists('enrol', ['courseid' => $courseid, 'enrol' => 'cohort', 'customint1' => $cohort->id])) {
                $attached[] = $jobtitle;
            }
        }
        return $attached;
    }

    /**
     * Jobtitles for this institution not yet attached to this course.
     */
    public static function get_unattached_jobtitle_cohorts(int $courseid, \stdClass $institutionrec): array {
        $all = self::get_pillable_jobtitles($institutionrec);
        $attached = self::get_attached_jobtitle_cohorts($courseid, $institutionrec);
        return array_values(array_diff($all, $attached));
    }

    const RENEWAL_UNITS = ['day', 'week', 'month', 'year'];

    /**
     * Sets (or clears, if $value is null) the renewal period for a course.
     */
    public static function set_course_renewal(int $courseid, ?int $value, ?string $unit): void {
        global $DB;

        if ($value !== null && !in_array($unit, self::RENEWAL_UNITS, true)) {
            throw new \coding_exception('Invalid renewal unit: ' . $unit);
        }

        $existing = $DB->get_record('local_vikesa_course_meta', ['courseid' => $courseid]);
        $now = time();

        if ($existing) {
            $existing->renewalvalue = $value;
            $existing->renewalunit = $value !== null ? $unit : null;
            $existing->timemodified = $now;
            $DB->update_record('local_vikesa_course_meta', $existing);
            return;
        }

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->renewalvalue = $value;
        $record->renewalunit = $value !== null ? $unit : null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_vikesa_course_meta', $record);
    }

    /**
     * @return array{value:int, unit:string}|null
     */
    public static function get_course_renewal(int $courseid): ?array {
        global $DB;
        $record = $DB->get_record('local_vikesa_course_meta', ['courseid' => $courseid]);
        if (!$record || $record->renewalvalue === null || $record->renewalunit === null) {
            return null;
        }
        return ['value' => (int) $record->renewalvalue, 'unit' => $record->renewalunit];
    }

    /**
     * Computes a due date from a completion timestamp + a renewal period.
     * strtotime's relative-format units ("day"/"week"/"month"/"year") each
     * handle calendar arithmetic correctly on their own (e.g. months of
     * different lengths, leap years) -- no manual day-count math needed.
     */
    public static function compute_due_date(int $completedtime, int $value, string $unit): int {
        if (!in_array($unit, self::RENEWAL_UNITS, true)) {
            throw new \coding_exception('Invalid renewal unit: ' . $unit);
        }
        return strtotime("+{$value} {$unit}", $completedtime);
    }

    /**
     * Enables course completion and requires every real activity in the
     * course (skipping the hidden qbank container) to be marked complete
     * for the course itself to count as complete. Call this AFTER every
     * activity has been created (including any content-outline import),
     * so it captures everything.
     *
     * This is genuinely new, untested territory for this plugin -- the
     * completion_criteria_activity API pattern below mirrors what Moodle's
     * own course/completion.php save handler does, but hasn't been run
     * against a live install yet.
     */
    public static function setup_completion_criteria(\stdClass $course): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

        if (!$course->enablecompletion) {
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
            $course->enablecompletion = 1;
        }

        $modinfo = get_fast_modinfo($course);
        $cmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'qbank') {
                continue; // Hidden question-bank container, not real content.
            }
            $cmids[$cm->id] = 1;
        }

        if (empty($cmids)) {
            return;
        }

        $criteriadata = new \stdClass();
        $criteriadata->id = $course->id;
        $criteriadata->criteria_activity = $cmids;

        $criterion = new \completion_criteria_activity();
        $criterion->update_config($criteriadata);
    }

    /**
     * Jobtitles available to pick from as audience pills, scoped to one
     * institution: every distinct jobtitle currently seen among that
     * institution's users, excluding the fixed "responsible" jobtitle
     * (that one is automatic, not a pill).
     */
    public static function get_pillable_jobtitles(\stdClass $institutionrec): array {
        global $DB;

        $sql = "SELECT DISTINCT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                  JOIN {user} u ON u.id = d.userid
                 WHERE f.shortname = :shortname
                   AND u.institution = :institution
                   AND u.deleted = 0
                   AND d.data <> ''
              ORDER BY d.data ASC";

        $rows = $DB->get_fieldset_sql($sql, [
            'shortname' => 'jobtitle',
            'institution' => $institutionrec->institution,
        ]);

        return array_values(array_diff($rows, [$institutionrec->responsiblejobtitle]));
    }
}
