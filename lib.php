<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves whether the current logged-in user is eligible to create a
 * course, and if so, their institution mapping record. Returns null for
 * everyone else (no institution, unconfigured institution, or missing
 * capability) -- the nav link and the FAB below both use this so neither
 * ever shows up for a normal Student.
 */
function local_vikesa_coursewizard_get_eligible_institution(): ?\stdClass {
    global $USER, $DB;

    if (!isloggedin() || isguestuser()) {
        return null;
    }

    $institution = \local_vikesa_coursewizard\manager::get_user_institution($USER->id);
    if (!$institution) {
        return null;
    }

    $institutionrec = $DB->get_record('local_vikesa_institution', ['institution' => $institution]);
    if (!$institutionrec || $institutionrec->responsiblejobtitle === '') {
        return null;
    }

    $context = context_coursecat::instance($institutionrec->categoryid);
    if (!has_capability('local/vikesa_coursewizard:createcourse', $context)) {
        return null;
    }

    return $institutionrec;
}

/**
 * Small, unobtrusive nav entry -- keyboard/accessibility-friendly fallback.
 * The FAB below is the obvious entry point.
 */
function local_vikesa_coursewizard_extend_navigation(global_navigation $navigation) {
    if (local_vikesa_coursewizard_get_eligible_institution()) {
        $navigation->add(
            get_string('createcourse', 'local_vikesa_coursewizard'),
            new moodle_url('/local/vikesa_coursewizard/create_course.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'vikesacoursewizard',
            new pix_icon('i/course', '')
        );
    }
}

/**
 * "Create course" floating action button -- outside any course only.
 *
 * Manage-participants no longer has a FAB: it is reached through the
 * course's own Participants tab (see classes/hook_callbacks.php, which
 * repoints that tab via the secondary_extend hook). Keeping a second FAB
 * for it was redundant and made it unclear which button belonged where.
 */
function local_vikesa_coursewizard_before_footer(): string {
    global $PAGE;

    if (!local_vikesa_coursewizard_get_eligible_institution()) {
        return '';
    }

    if (!empty($PAGE->course) && $PAGE->course->id != SITEID) {
        return '';
    }

    $createurl = new moodle_url('/local/vikesa_coursewizard/create_course.php');
    if ($PAGE->url->compare($createurl, URL_MATCH_BASE)) {
        return '';
    }

    return local_vikesa_coursewizard_render_fab($createurl, get_string('createcourse', 'local_vikesa_coursewizard'));
}

function local_vikesa_coursewizard_render_fab(moodle_url $url, string $label): string {
    return '
    <style>
        .vikesa-create-course-fab {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 1030;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            border-radius: 999px;
            background: var(--vikesa-seal, #2FA860);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .vikesa-create-course-fab:hover,
        .vikesa-create-course-fab:focus {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.3);
            text-decoration: none;
        }
        .vikesa-create-course-fab svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        @media (max-width: 575.98px) {
            .vikesa-create-course-fab span { display: none; }
            .vikesa-create-course-fab { padding: 14px; }
        }
    </style>
    <a href="' . $url->out(false) . '" class="vikesa-create-course-fab">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
             stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/>
             <line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>' . $label . '</span>
    </a>';
}
