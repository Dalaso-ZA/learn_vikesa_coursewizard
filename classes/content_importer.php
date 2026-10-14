<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');
require_once($GLOBALS['CFG']->dirroot . '/course/modlib.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/locallib.php');
require_once($GLOBALS['CFG']->dirroot . '/question/format.php');
require_once($GLOBALS['CFG']->dirroot . '/question/format/xml/format.php');
require_once($GLOBALS['CFG']->dirroot . '/question/engine/bank.php');
require_once($GLOBALS['CFG']->dirroot . '/question/editlib.php');

/**
 * Content outline format (plain text). Sections can contain subsections and
 * content blocks (quiz / page / label / file). NOTE: subsections are
 * FLATTENED into ordinary, sequentially-numbered Moodle sections with a
 * combined name ("Parent - Sub") -- true nested Subsection activities
 * (a newer, less-documented Moodle 4.4+ feature) are not implemented here,
 * this was a deliberate scope decision to avoid shipping untested guesses
 * at that specific API.
 *
 *   #SECTION: <name>
 *   free text -> section summary
 *
 *   #SUBSECTION: <name>
 *   free text -> becomes its own flattened section, nested under the most
 *   recent #SECTION until the next #SECTION resets it
 *
 *   #QUIZ: <name>
 *   ...Moodle XML <question> markup (a wrapping <quiz> tag is added
 *   automatically if missing)...
 *   #ENDQUIZ
 *
 *   #PAGE: <name>
 *   HTML or plain text -> becomes a Page activity
 *   #ENDPAGE
 *
 *   #LABEL:
 *   short HTML/text -> becomes a Label activity (no separate name needed)
 *   #ENDLABEL
 *
 *   #FILE: <display name> | <exact uploaded filename>
 *   optional description text
 *   #ENDFILE
 *
 * All four block types attach to whichever #SECTION or #SUBSECTION was most
 * recently opened.
 */
class content_importer {

    /**
     * @return array of top-level sections: ['name', 'summary', 'blocks' => [...],
     *   'subsections' => [ ['name', 'summary', 'blocks' => [...]], ... ] ]
     */
    public static function parse_outline(string $text): array {
        $lines = preg_split('/\r\n|\r|\n/', $text);

        $sections = [];
        $sectionidx = -1;
        $subsectionidx = null;
        $openblock = null;

        $patterns = [
            'section' => '/^#SECTION:\s*(.+)$/i',
            'subsection' => '/^#SUBSECTION:\s*(.+)$/i',
            'quiz' => '/^#QUIZ:\s*([^|]+?)\s*(?:\|(.*))?$/i',
            'page' => '/^#PAGE:\s*(.+)$/i',
            'label' => '/^#LABEL:?\s*(.*)$/i',
            'file' => '/^#FILE:\s*([^|]+)\|(.+)$/i',
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Auto-close: any recognized marker line -- whether it's the
            // block's own exact #ENDxxx or the start of a completely new
            // block -- closes whatever was open first. This makes the
            // parser tolerant of a missing closer (a common LLM mistake);
            // without this, one forgotten #ENDLABEL would otherwise
            // silently swallow every line for the rest of the file.
            if ($openblock !== null) {
                $isexplicitend = strcasecmp($trimmed, '#END' . strtoupper($openblock['type'])) === 0;
                $startsnewblock = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $trimmed)) {
                        $startsnewblock = true;
                        break;
                    }
                }
                if ($isexplicitend || $startsnewblock) {
                    self::attach_block($sections, $sectionidx, $subsectionidx, $openblock);
                    $openblock = null;
                    if ($isexplicitend) {
                        continue;
                    }
                    // Fall through to process this same line as the new marker below.
                }
            }

            if ($openblock !== null) {
                $openblock['lines'][] = $line;
                continue;
            }

            if (preg_match($patterns['section'], $trimmed, $m)) {
                $sections[] = ['name' => trim($m[1]), 'summary' => '', 'blocks' => [], 'subsections' => []];
                $sectionidx = count($sections) - 1;
                $subsectionidx = null;
                continue;
            }

            if ($sectionidx === -1) {
                continue; // Ignore anything before the first #SECTION.
            }

            if (preg_match($patterns['subsection'], $trimmed, $m)) {
                $sections[$sectionidx]['subsections'][] = ['name' => trim($m[1]), 'summary' => '', 'blocks' => []];
                $subsectionidx = count($sections[$sectionidx]['subsections']) - 1;
                continue;
            }

            if (preg_match($patterns['quiz'], $trimmed, $m)) {
                $openblock = ['type' => 'quiz', 'name' => trim($m[1]), 'settings' => trim($m[2] ?? ''), 'lines' => []];
                continue;
            }

            if (preg_match($patterns['page'], $trimmed, $m)) {
                $openblock = ['type' => 'page', 'name' => trim($m[1]), 'lines' => []];
                continue;
            }

            if (preg_match($patterns['label'], $trimmed, $m)) {
                $openblock = ['type' => 'label', 'name' => trim($m[1]), 'lines' => []];
                continue;
            }

            if (preg_match($patterns['file'], $trimmed, $m)) {
                $openblock = ['type' => 'file', 'name' => trim($m[1]), 'filename' => trim($m[2]), 'lines' => []];
                continue;
            }

            if ($subsectionidx !== null) {
                $sections[$sectionidx]['subsections'][$subsectionidx]['summary'] .=
                    ($sections[$sectionidx]['subsections'][$subsectionidx]['summary'] === '' ? '' : "\n") . $line;
            } else {
                $sections[$sectionidx]['summary'] .= ($sections[$sectionidx]['summary'] === '' ? '' : "\n") . $line;
            }
        }

        if ($openblock !== null) {
            self::attach_block($sections, $sectionidx, $subsectionidx, $openblock);
        }

        return $sections;
    }

    protected static function attach_block(array &$sections, int $sectionidx, ?int $subsectionidx, array $block): void {
        $data = match ($block['type']) {
            'quiz' => ['type' => 'quiz', 'name' => $block['name'], 'settings' => $block['settings'] ?? '',
                'xml' => implode("\n", $block['lines'])],
            'page' => ['type' => 'page', 'name' => $block['name'], 'content' => implode("\n", $block['lines'])],
            'label' => ['type' => 'label', 'name' => $block['name'], 'content' => implode("\n", $block['lines'])],
            'file' => ['type' => 'file', 'name' => $block['name'], 'filename' => $block['filename'],
                'description' => implode("\n", $block['lines'])],
            default => null,
        };
        if ($data === null) {
            return;
        }
        if ($subsectionidx !== null) {
            $sections[$sectionidx]['subsections'][$subsectionidx]['blocks'][] = $data;
        } else {
            $sections[$sectionidx]['blocks'][] = $data;
        }
    }

    /**
     * Applies parsed sections onto an already-created, empty course.
     * Best-effort per block: one bad block doesn't abort the whole course.
     *
     * @param array $mediafiles filename => stored_file, for #FILE blocks.
     * @return string[] warnings, empty if everything applied cleanly.
     */
    public static function apply_content(\stdClass $course, array $sections, array $mediafiles = []): array {
        $warnings = [];
        $sectionnum = 0;

        foreach ($sections as $sectiondata) {
            $sectionnum++;
            self::create_or_update_section($course, $sectionnum, $sectiondata['name'], $sectiondata['summary']);
            $warnings = array_merge($warnings, self::apply_blocks($course, $sectionnum, $sectiondata['blocks'], $mediafiles));

            foreach ($sectiondata['subsections'] as $subdata) {
                $sectionnum++;
                $flatname = $sectiondata['name'] . ' - ' . $subdata['name'];
                self::create_or_update_section($course, $sectionnum, $flatname, $subdata['summary']);
                $warnings = array_merge($warnings, self::apply_blocks($course, $sectionnum, $subdata['blocks'], $mediafiles));
            }
        }

        return $warnings;
    }

    protected static function create_or_update_section(\stdClass $course, int $sectionnum, string $name, string $summary): void {
        course_create_sections_if_missing($course, [$sectionnum]);
        $dbsection = get_fast_modinfo($course)->get_section_info($sectionnum);
        course_update_section($course, $dbsection, [
            'name' => $name,
            'summary' => trim($summary),
            'summaryformat' => FORMAT_PLAIN,
        ]);
    }

    protected static function apply_blocks(\stdClass $course, int $sectionnum, array $blocks, array $mediafiles): array {
        $warnings = [];
        foreach ($blocks as $block) {
            try {
                switch ($block['type']) {
                    case 'quiz':
                        self::create_quiz_with_questions($course, $sectionnum, $block);
                        break;
                    case 'page':
                        self::create_page($course, $sectionnum, $block);
                        break;
                    case 'label':
                        self::create_label($course, $sectionnum, $block);
                        break;
                    case 'file':
                        self::create_file_resource($course, $sectionnum, $block, $mediafiles);
                        break;
                }
            } catch (\Throwable $e) {
                $warnings[] = self::describe_exception($block, $e);
            }
        }
        return $warnings;
    }

    protected static function describe_exception(array $block, \Throwable $e): string {
        $detail = $e->getMessage();
        if ($e instanceof \moodle_exception) {
            if (isset($e->a) && $e->a !== null) {
                $detail .= ' [missing/bad attribute: ' . var_export($e->a, true) . ']';
            }
            if (!empty($e->debuginfo)) {
                $detail .= ' [debuginfo: ' . $e->debuginfo . ']';
            }
        }
        $label = $block['name'] ?: $block['type'];
        return "Block '{$label}' ({$block['type']}) failed to import ({$detail}).";
    }

    /**
     * Moodle 4.3+ requires question categories to belong to a CONTEXT_MODULE
     * of a "Question bank" (mod_qbank) activity, not a bare course context.
     * Gets the course's existing hidden qbank instance, or creates one.
     */
    protected static function get_or_create_qbank_context(\stdClass $course): \context_module {
        global $DB;

        $existingcmid = $DB->get_field_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE m.name = 'qbank' AND cm.course = :courseid",
            ['courseid' => $course->id]
        );

        if ($existingcmid) {
            return \context_module::instance($existingcmid);
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'qbank';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = 0;
        $moduleinfo->name = 'Question bank';
        $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_PLAIN, 'itemid' => 0];
        $moduleinfo->visible = 0;
        $moduleinfo->visibleoncoursepage = 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->showdescription = 0;
        $moduleinfo->availability = null;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo = create_module($moduleinfo);

        return \context_module::instance($moduleinfo->coursemodule);
    }

    protected static function get_or_create_question_category(\context_module $qbankcontext): \stdClass {
        global $DB;

        $category = $DB->get_record('question_categories', [
            'contextid' => $qbankcontext->id,
            'parent' => 0,
        ]);

        if (!$category) {
            $category = new \stdClass();
            $category->name = 'Vikesa outline questions';
            $category->info = '';
            $category->contextid = $qbankcontext->id;
            $category->parent = 0;
            $category->sortorder = 999;
            $category->stamp = make_unique_id_code();
            $category->id = $DB->insert_record('question_categories', $category);
        }

        return $category;
    }

    protected static function parse_quiz_settings(string $settingsstring): array {
        $settings = ['shuffle' => false, 'maxgrade' => 100.0, 'passgrade' => null, 'random' => null];
        foreach (explode('|', $settingsstring) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || !str_contains($piece, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $piece, 2));
            switch (strtolower($key)) {
                case 'shuffle':
                    $settings['shuffle'] = in_array(strtolower($value), ['1', 'true', 'yes'], true);
                    break;
                case 'maxgrade':
                case 'maxpoints':
                    $settings['maxgrade'] = (float) $value;
                    break;
                case 'passgrade':
                case 'passmark':
                    $settings['passgrade'] = (float) $value;
                    break;
                case 'random':
                    $settings['random'] = max(1, (int) $value);
                    break;
            }
        }
        return $settings;
    }

    protected static function create_quiz_with_questions(\stdClass $course, int $sectionnum, array $quizdata): void {
        global $DB;

        $settings = self::parse_quiz_settings($quizdata['settings'] ?? '');

        // Anything written before the first <question> tag is the quiz's
        // own description -- what the student sees before starting it. No
        // separate marker needed: just write it as plain text/HTML at the
        // top of the #QUIZ block, before the XML.
        $raw = trim($quizdata['xml']);
        $splitpos = stripos($raw, '<question');
        $introraw = $splitpos !== false ? trim(substr($raw, 0, $splitpos)) : '';
        $questionsxml = $splitpos !== false ? substr($raw, $splitpos) : $raw;
        $introhtml = $introraw !== '' ? nl2br(s($introraw)) : '';

        $qbankcontext = self::get_or_create_qbank_context($course);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $quizdata['name'];
        $moduleinfo->introeditor = ['text' => $introhtml, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->showdescription = $introhtml !== '' ? 1 : 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->availability = null;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->grade = $settings['maxgrade'];
        if ($settings['passgrade'] !== null) {
            $moduleinfo->gradepass = $settings['passgrade'];
        }
        $moduleinfo->preferredbehaviour = 'deferredfeedback';
        $moduleinfo->questionsperpage = 1;
        $moduleinfo->shufflequestions = $settings['shuffle'] ? 1 : 0;
        $moduleinfo->navmethod = 'free';
        $moduleinfo->attempts = 0;
        $moduleinfo->grademethod = 1; // QUIZ_GRADEHIGHEST.
        $moduleinfo->completion = 2; // COMPLETION_TRACKING_AUTOMATIC.
        $moduleinfo->completionview = 0;
        $moduleinfo->completionusegrade = 1;
        $moduleinfo->completionpassgrade = $settings['passgrade'] !== null ? 1 : 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->quizpassword = '';
        $moduleinfo->subnet = '';
        $moduleinfo->delay1 = 0;
        $moduleinfo->delay2 = 0;
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->timelimit = 0;
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 0;
        $moduleinfo->attemptonlast = 0;
        $moduleinfo->decimalpoints = 2;
        $moduleinfo->questiondecimalpoints = -1;
        $moduleinfo->shuffleanswers = 1;
        $moduleinfo = create_module($moduleinfo);

        $quizrecord = $DB->get_record('quiz', ['id' => $moduleinfo->instance], '*', MUST_EXIST);
        $category = self::get_or_create_question_category($qbankcontext);

        // Auto-wrap in <quiz>...</quiz> if the block is just bare <question>
        // elements, since that's the easiest mistake for an LLM to make.
        $content = trim($questionsxml);
        if (stripos($content, '<quiz') === false) {
            $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n{$content}\n</quiz>";
        }

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setCourse($course);
        $qformat->setContexts([$qbankcontext]);
        $qformat->setStoponerror(false);

        $tmpfile = tempnam(sys_get_temp_dir(), 'vikesaxml');
        file_put_contents($tmpfile, $content);
        $qformat->setFilename($tmpfile);
        $qformat->setRealfilename('outline.xml');

        try {
            $ok = $qformat->importprocess();
        } finally {
            @unlink($tmpfile);
        }

        if (!$ok) {
            throw new \moodle_exception('xml_import_failed', 'local_vikesa_coursewizard');
        }

        $questionids = $DB->get_fieldset_sql(
            'SELECT qv.questionid
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              WHERE qbe.questioncategoryid = :catid',
            ['catid' => $category->id]
        );

        if ($settings['random'] !== null) {
            try {
                quiz_add_random_questions($quizrecord, 0, $category->id, $settings['random'], false);
            } catch (\Throwable $e) {
                debugging('Vikesa course wizard: random slot failed, falling back to fixed slots: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                foreach ($questionids as $questionid) {
                    quiz_add_quiz_question($questionid, $quizrecord);
                }
            }
        } else {
            foreach ($questionids as $questionid) {
                quiz_add_quiz_question($questionid, $quizrecord);
            }
        }

        try {
            if (class_exists('\mod_quiz\quiz_settings')) {
                $quizobj = \mod_quiz\quiz_settings::create($quizrecord->id);
                $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
            } else {
                quiz_update_sumgrades($quizrecord);
            }
        } catch (\Throwable $e) {
            quiz_update_sumgrades($quizrecord);
        }
    }

    protected static function create_page(\stdClass $course, int $sectionnum, array $block): void {
        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'page';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $block['name'];
        $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_PLAIN, 'itemid' => 0];
        $moduleinfo->page = ['text' => trim($block['content']), 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->showdescription = 0;
        $moduleinfo->availability = null;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 2; // COMPLETION_TRACKING_AUTOMATIC.
        $moduleinfo->completionview = 1;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->printheading = 1;
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        $moduleinfo->legacyfiles = 0;
        create_module($moduleinfo);
    }

    protected static function create_label(\stdClass $course, int $sectionnum, array $block): void {
        $content = trim($block['content']);
        $plaintext = trim(strip_tags($content));
        $name = $plaintext !== '' ? shorten_text($plaintext, 60) : ($block['name'] ?: 'Label');

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'label';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $name;
        $moduleinfo->introeditor = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->showdescription = 0;
        $moduleinfo->availability = null;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 1; // COMPLETION_TRACKING_MANUAL.
        $moduleinfo->completionexpected = 0;
        create_module($moduleinfo);
    }

    protected static function create_file_resource(\stdClass $course, int $sectionnum, array $block, array $mediafiles): void {
        global $USER;

        if (empty($block['filename']) || !isset($mediafiles[$block['filename']])) {
            throw new \moodle_exception('mediafilenotfound', 'local_vikesa_coursewizard', '', $block['filename'] ?? '');
        }

        $sourcefile = $mediafiles[$block['filename']];

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $fs->create_file_from_storedfile([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $sourcefile->get_filename(),
        ], $sourcefile);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'resource';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $block['name'] !== '' ? $block['name'] : $sourcefile->get_filename();
        $moduleinfo->introeditor = ['text' => trim($block['description'] ?? ''), 'format' => FORMAT_PLAIN, 'itemid' => 0];
        $moduleinfo->files = $draftitemid;
        $moduleinfo->display = 0; // RESOURCELIB_DISPLAY_AUTO.
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->showdescription = 1;
        $moduleinfo->availability = null;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 2; // COMPLETION_TRACKING_AUTOMATIC.
        $moduleinfo->completionview = 1;
        $moduleinfo->completionexpected = 0;
        create_module($moduleinfo);
    }
}
