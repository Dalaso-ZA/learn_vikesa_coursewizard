# local_vikesa_coursewizard

Restricts course creation for Vikesa's Moodle instance to a minimal,
opinionated flow: **login → create course → audience/teachers picked →
done.** Built to sit on top of Casdoor-synced `institution` and `jobtitle`
user fields.

## Core idea

- A **company** = a Moodle course **category**, resolved automatically from
  the logged-in user's `institution` field. Users never pick a category.
- A **jobtitle** = an audience tag. Most jobtitles enrol as Student via a
  cohort. Exactly one jobtitle per institution (the "responsible" jobtitle)
  always enrols as Teacher and is the only one allowed to run the wizard.
- Everything reacts to jobtitle/institution changes automatically (cohort
  sync), so a promotion/role change propagates without touching existing
  courses by hand.

## One-time setup (per environment)

1. **Create the `jobtitle` user profile field.**
   Site administration → Users → User profile fields → new **Text input**
   field, short name **exactly** `jobtitle`. Required — the plugin reads
   this shortname directly, nothing configurable here.
2. Make sure Casdoor's OAuth2 field mapping sends `institution` (core user
   field) and `jobtitle` (the field above). Tags/`interests` are separate
   and not used by this plugin.
3. Install the plugin, run the Moodle upgrade, purge caches.
4. Let a real user with the target institution log in (or edit their
   profile manually) — this auto-creates the category and a row on the
   **Vikesa institutions** admin page (Site administration → Courses).
5. On that page, set the **responsible jobtitle** for the institution.
   Until this is set, nobody in that institution can create courses —
   this is the intentional "institution not fully onboarded yet" gate.

## What the wizard actually does on submit

1. Creates the course under the resolved category. Shortname is generated
   as `institution-coursename`; on a collision it appends a short random
   suffix (not a sequential number, to avoid looking like a duplicate
   submission).
2. For each selected **audience pill** (jobtitle): ensures a cohort
   `vikesa:<md5(institution|jobtitle)>` exists in the category context,
   adds a cohort-sync enrolment instance on the course with role Student.
3. Always adds a cohort-sync instance for the institution's **responsible
   jobtitle**, role Teacher — this is automatic, never shown as a pill.
4. **Ad-hoc teachers** picked in the form → manual enrolment, role
   Teacher, this course only.
5. **Individual access** picked in the form → manual enrolment, role
   Student, bypasses jobtitle/pills entirely (the "one-off exception"
   case).

## Sync mechanism

- `classes/observer.php` reacts to `user_updated`/`user_created` and calls
  `manager::sync_user()` immediately — this is what makes a jobtitle
  change take effect close to instantly (bounded by whenever Casdoor
  actually pushes the change through).
- `classes/task/resync_all.php` runs nightly as a safety net for anything
  that doesn't fire a per-user event (bulk DB imports, direct SQL, etc.).
- `local_vikesa_usersync` table stores each user's last-synced
  institution+jobtitle so the sync logic can diff and only move cohort
  membership on an actual change.

## Access control

- Custom role `vikesacoursecreator`, created on install, holding only the
  capability `local/vikesa_coursewizard:createcourse`, assigned
  automatically at the institution's category context — never manually,
  never at system level. It grants no other Moodle admin/course-management
  UI.
- The "Create course" entry points (nav link + floating action button,
  see `lib.php`) only render for users who currently hold that capability
  in their own institution's category.

## Course content from a file (sections, subsections, pages, labels, files, quizzes)

Both the single-course and bulk paths accept an optional **outline file**
(plain text, not real CSV). Format:

```
#SECTION: <name>
free text -> section summary

#SUBSECTION: <name>
free text -> flattened into its own sequential section named "Parent - Sub"

#QUIZ: <name>
...Moodle XML <question> markup (auto-wrapped in <quiz> if missing)...
#ENDQUIZ

#PAGE: <name>
HTML or plain text -> a Page activity
#ENDPAGE

#LABEL:
short HTML/text -> a Label activity
#ENDLABEL

#FILE: <display name> | <exact uploaded filename>
optional description
#ENDFILE
```

Every block type attaches to whichever #SECTION or #SUBSECTION was most
recently opened. See `templates/course_content_outline_template.txt` for a
full example.

**Subsections are flattened, not native.** Moodle 4.4+ has a real nested
"Subsection" activity type, but its API is newer and far less documented
than everything else here; rather than ship an untested guess at it (this
plugin's quiz support alone took over a dozen live-server iterations to
get right), a #SUBSECTION becomes its own ordinary, sequentially-numbered
section named "Parent - Sub". Revisit if native nesting is worth the risk
later.

**#FILE blocks need the actual file uploaded separately**, in the
"Images, videos, and PDFs" filemanager field, matched by exact filename --
the outline text can't carry binary data itself.

**Question format is Moodle XML, not GIFT** (switched to support the
fuller range of Moodle question types GIFT can't express, e.g. numerical
with tolerances, embedded answers). This is the least-tested part of the
plugin; expect to iterate against real import errors, same as the quiz
feature did originally -- the code surfaces the exact failing attribute or
DB error in the results-page warning, which is how every fix so far was
actually found.

Implementation (`classes/content_importer.php`):
- `parse_outline()` builds a tree of sections -> (blocks + subsections) ->
  blocks.
- `apply_content()` flattens that tree into sequential Moodle sections and
  creates each block via `create_module()` (quiz/page/label/resource),
  reusing the same qbank-context question-category setup the quiz path
  established.
- Best-effort per block: one bad block is reported as a warning and
  skipped, the rest of the course still gets created.

## Bulk course creation

The wizard form has an optional file upload (`.csv`/`.txt`, one course name
per line, optionally with a second `contentfile` column -- see above). If
used, every course created gets the *same* audience pills / ad-hoc
teachers / individual access selected in the rest of the form —
this exists specifically so a course creator never needs Moodle's native
"Upload courses" admin tool, which is sitewide and far broader than the
`local/vikesa_coursewizard:createcourse` capability they actually hold.
See `corelib_resolve_course_entries()` / `corelib_create_and_enrol_course()`
in `create_course.php`.

Bulk quiz-question import *without* a full course structure is still
better served by Moodle's native Question bank → Import → GIFT format
directly, if you just want to add questions to an existing quiz.

## Managing participants on an existing course

`manage_participants.php` (reached by clicking the course's own
**"Participants" tab** -- see below, not a separate nav item) lets any
teacher of that specific course:

- Add a jobtitle as a new audience (creates/attaches a cohort-sync
  enrolment the same way the wizard does at creation time) -- the fix for
  "HR renamed a jobtitle and now everyone with it is missing this course":
  re-adding that jobtitle here restores access for everyone who holds it,
  permanently, not just a one-off manual patch for a single person.
- Remove an already-attached jobtitle audience.
- Add ad-hoc teachers or individual access, same as at creation time.

This exists specifically so `local_vikesa_scopeguard`'s removal of
Moodle's native "Enrol users" sitewide search doesn't turn into an
operational dead end. The native search stays blocked (still sitewide,
still the actual leak); this page is the institution-scoped replacement
for legitimate day-to-day fixes. Access control: any user holding
`moodle/course:manageactivities` in that specific course -- deliberately
broader than the wizard's own creation capability, since any teacher
(including one added later, ad-hoc) may need to fix enrolment without
waiting on the one "responsible" person.

**How it's surfaced**: the course's native "Participants" tab is repointed
to this page via the `secondary_extend` hook (`db/hooks.php`,
`classes/hook_callbacks.php`), which Moodle 5.1 fires after the tab is built.
It only applies to Vikesa-institution courses and only for users with
`moodle/course:manageactivities`; everyone else keeps the native tab. There
is no manage-participants button any more. After upgrading, purge caches.

Since this page effectively stands in for the native Participants page
for anyone using it, it also shows a plain enrolled-users list (name,
email, roles) so nothing is lost, not just the audience/teacher
management tools.

## Known limitations / things to revisit

- No UI for renaming/deactivating an institution mapping — edit the
  responsible jobtitle only. Course editing after creation is standard
  Moodle (course settings page), by design — out of scope for this
  plugin.
- Ad-hoc teacher / individual-access search (`classes/external/search_users.php`)
  is scoped server-side to the target institution regardless of what the
  client sends — safe against a tampered request, but only returns the
  first 20 matches per keystroke.
- First resync after a fresh install treats every existing user as "new"
  — expect a burst of cohort/role writes once, this is normal.

## File map

| Path | Purpose |
|---|---|
| `classes/manager.php` | All institution/cohort/role sync logic |
| `classes/observer.php` | Event-driven near-instant sync |
| `classes/task/resync_all.php` | Nightly safety-net resync |
| `classes/form/create_course_form.php` | The wizard form |
| `classes/form/institution_form.php` | Admin: edit responsible jobtitle |
| `classes/external/search_users.php` | Ajax backend for the two user pickers |
| `classes/privacy/provider.php` | GDPR export/delete for `local_vikesa_usersync` |
| `create_course.php` | Wizard controller: guard, form, enrolment wiring |
| `admin/institutions.php` | Admin: list/edit institution → category mapping |
| `db/install.xml` | `local_vikesa_institution`, `local_vikesa_usersync` tables |
| `db/install.php` | Creates the `vikesacoursecreator` role |
| `lib.php` | Nav link + floating action button, both capability-gated |

## Invited-only courses

Every course the wizard creates has guest access and self-enrolment
disabled, so only the chosen audience cohorts, the responsible job title and
the picked teachers can enter. Hiding courses from other institutions in the
catalogue and search is done by `local_vikesa_scopeguard`.

## Casdoor sync

Casdoor's userinfo endpoint does not return affiliation/title, so they are
read through the Casdoor API (`classes/casdoor.php`) using the client
ID/secret of the chosen OAuth 2 service. Configure under Site admin >
Plugins > Local plugins > Vikesa Casdoor sync (service + organisation
names). Affiliation -> Institution (falls back to the Casdoor organisation
when empty); title -> Job title. Runs at each OAuth2 login for that user,
nightly for all, and from "Resync Vikesa users".

Organisation logo/favicon: also read from Casdoor (`get-organization`) during
the nightly run / "Resync Vikesa users" and stored on the institution record;
`theme_vikesa` shows them in the navbar and tab icon for that institution's users.
