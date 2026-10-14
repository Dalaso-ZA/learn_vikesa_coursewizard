<?php

namespace local_vikesa_coursewizard\external;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

/**
 * Backs both the "ad-hoc teacher" and "individual access" autocomplete
 * pickers. Always scoped server-side to the institution tied to the given
 * category -- the query string is only ever a filter within that set, it
 * can never widen the pool of returned users.
 */
class search_users extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'categoryid' => new \external_value(PARAM_INT, 'Category the course is being created in'),
            'query' => new \external_value(PARAM_RAW, 'Search text (name or email)'),
        ]);
    }

    public static function execute($categoryid, $query): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'query' => $query,
        ]);

        $context = \context_coursecat::instance($params['categoryid']);
        self::validate_context($context);
        require_capability('local/vikesa_coursewizard:createcourse', $context);

        $institutionrec = $DB->get_record('local_vikesa_institution', ['categoryid' => $params['categoryid']]);
        if (!$institutionrec) {
            return [];
        }

        $like = $DB->sql_like('u.' . 'firstname', ':q1', false)
            . ' OR ' . $DB->sql_like('u.' . 'lastname', ':q2', false)
            . ' OR ' . $DB->sql_like('u.' . 'email', ':q3', false);

        $searchterm = '%' . $DB->sql_like_escape($params['query']) . '%';

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                 WHERE u.institution = :institution
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND ($like)
              ORDER BY u.lastname, u.firstname";

        $records = $DB->get_records_sql($sql, [
            'institution' => $institutionrec->institution,
            'q1' => $searchterm,
            'q2' => $searchterm,
            'q3' => $searchterm,
        ], 0, 20);

        $results = [];
        foreach ($records as $record) {
            $results[] = [
                'id' => $record->id,
                'fullname' => fullname($record) . ' <' . $record->email . '>',
            ];
        }
        return $results;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'User id'),
            'fullname' => new \external_value(PARAM_RAW, 'Display label'),
        ]));
    }
}
