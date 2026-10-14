<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

/**
 * Pulls Institution (Casdoor "affiliation") and Job title (Casdoor "title")
 * straight from the Casdoor API, because Casdoor's OIDC userinfo endpoint
 * never sends those claims, so Moodle's OAuth2 field mapping cannot get them.
 *
 * Credentials: reused from the OAuth2 service chosen in the plugin settings
 * (client id / secret / base URL), so nothing is entered twice.
 * Users are matched by Moodle username == Casdoor user name (case-insensitive),
 * which is how auth_oauth2 creates them.
 */
class casdoor {

    const TIMEOUT = 10;

    /** @return array|null [baseurl, clientid, clientsecret] */
    protected static function credentials(): ?array {
        $issuerid = (int) get_config('local_vikesa_coursewizard', 'casdoor_issuerid');
        if (!$issuerid) {
            return null;
        }
        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (!$issuer) {
            return null;
        }
        $base = trim((string) get_config('local_vikesa_coursewizard', 'casdoor_baseurl'));
        if ($base === '') {
            $base = (string) $issuer->get('baseurl');
        }
        $base = rtrim($base, '/');
        if ($base === '' || $issuer->get('clientid') === '') {
            return null;
        }
        return [$base, $issuer->get('clientid'), $issuer->get('clientsecret')];
    }

    /** @return string[] Casdoor organisation names to read. */
    public static function orgs(): array {
        $raw = (string) get_config('local_vikesa_coursewizard', 'casdoor_orgs');
        $orgs = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($orgs));
    }

    public static function is_configured(): bool {
        return self::credentials() !== null && !empty(self::orgs());
    }

    /**
     * @return array [string|null error, mixed data]
     */
    protected static function request(string $path, array $params): array {
        $creds = self::credentials();
        if (!$creds) {
            return ['Casdoor is not configured (plugin settings).', null];
        }
        [$base, $clientid, $secret] = $creds;
        $params['clientId'] = $clientid;
        $params['clientSecret'] = $secret;

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        $body = $curl->get($base . $path . '?' . http_build_query($params));

        $info = $curl->get_info();
        if ($curl->get_errno() || empty($info['http_code']) || $info['http_code'] >= 400) {
            return ['HTTP ' . ($info['http_code'] ?? '?') . ' ' . $curl->error, null];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['Unreadable response from Casdoor.', null];
        }
        if (isset($json['status'])) {
            if ($json['status'] !== 'ok') {
                return [(string) ($json['msg'] ?? 'Casdoor returned an error.'), null];
            }
            return [null, $json['data'] ?? null];
        }
        return [null, $json];
    }

    /** @return array [string|null error, array users] */
    public static function fetch_org_users(string $org): array {
        [$error, $data] = self::request('/api/get-users', ['owner' => $org]);
        if ($error !== null) {
            return [$error, []];
        }
        return [null, is_array($data) ? $data : []];
    }

    /** @return array [string|null error, array|null organisation record] */
    public static function fetch_org_info(string $org): array {
        [$error, $data] = self::request('/api/get-organization', ['id' => 'admin/' . $org]);
        return [$error, is_array($data) ? $data : null];
    }

    /** Institution strings written by apply() since the last reset. */
    protected static $seeninstitutions = [];

    /** Find one user by name across the configured orgs. */
    public static function fetch_user(string $username): ?array {
        foreach (self::orgs() as $org) {
            [$error, $data] = self::request('/api/get-user', ['id' => $org . '/' . $username]);
            if ($error === null && is_array($data) && !empty($data['name'])
                    && strtolower($data['name']) === strtolower($username)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Matches a Casdoor user to a Moodle account: first by username
     * (Casdoor name, case-insensitive), then by e-mail address if exactly
     * one OAuth 2 account has it.
     */
    protected static function find_moodle_user(array $cu): ?\stdClass {
        global $DB, $CFG;

        $base = ['deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id];
        $name = strtolower(trim((string) ($cu['name'] ?? '')));
        if ($name !== '') {
            $user = $DB->get_record('user', $base + ['username' => $name, 'auth' => 'oauth2']);
            if ($user) {
                return $user;
            }
        }
        $email = strtolower(trim((string) ($cu['email'] ?? '')));
        if ($email !== '') {
            $users = $DB->get_records_select('user',
                'LOWER(email) = :email AND auth = :auth AND deleted = 0 AND mnethostid = :host',
                ['email' => $email, 'auth' => 'oauth2', 'host' => $CFG->mnet_localhost_id]);
            if (count($users) === 1) {
                return reset($users);
            }
        }
        return null;
    }

    /**
     * Writes one Casdoor user's affiliation/title into the matching Moodle
     * user. Returns 'updated', 'unchanged' or 'nomoodleuser'.
     */
    public static function apply(array $cu): string {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        if (empty($cu['name'])) {
            return 'nomoodleuser';
        }
        $user = self::find_moodle_user($cu);
        if (!$user) {
            return 'nomoodleuser';
        }

        // Affiliation is the institution; if it is empty fall back to the
        // Casdoor organisation the user belongs to.
        $institution = trim((string) ($cu['affiliation'] ?? ''));
        if ($institution === '') {
            $institution = trim((string) ($cu['owner'] ?? ''));
        }
        $title = trim((string) ($cu['title'] ?? ''));
        if ($institution !== '') {
            self::$seeninstitutions[$institution] = true;
        }

        $changed = false;
        if ($institution !== '' && $user->institution !== $institution) {
            $DB->set_field('user', 'institution', $institution, ['id' => $user->id]);
            $changed = true;
        }

        $currenttitle = manager::get_user_jobtitle((int) $user->id) ?? '';
        if ($currenttitle !== $title && $DB->record_exists('user_info_field', ['shortname' => 'jobtitle'])) {
            profile_save_custom_fields($user->id, ['jobtitle' => $title]);
            $changed = true;
        }

        if ($changed) {
            // Lets the cohort/role sync (and scope guard) react immediately.
            \core\event\user_updated::create_from_userid($user->id)->trigger();
            return 'updated';
        }
        return 'unchanged';
    }

    /** Called on OAuth2 login: refresh just that user. Never throws. */
    public static function pull_user(int $userid): void {
        global $DB;
        try {
            if (!self::is_configured()) {
                return;
            }
            $username = $DB->get_field('user', 'username', ['id' => $userid, 'auth' => 'oauth2']);
            if (!$username) {
                return;
            }
            $cu = self::fetch_user($username);
            if ($cu) {
                self::apply($cu);
            }
        } catch (\Throwable $e) {
            debugging('Vikesa Casdoor pull failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Reads every configured organisation.
     * @return array summary: updated, unchanged, nomoodleuser, errors[]
     */
    public static function pull_all(): array {
        $summary = ['updated' => 0, 'unchanged' => 0, 'nomoodleuser' => 0, 'errors' => [], 'orgs' => [], 'unmatched' => []];
        if (!self::is_configured()) {
            $summary['errors'][] = 'Casdoor is not configured.';
            return $summary;
        }
        foreach (self::orgs() as $org) {
            [$error, $users] = self::fetch_org_users($org);
            if ($error !== null) {
                $summary['errors'][] = $org . ': ' . $error;
                $summary['orgs'][$org] = 0;
                continue;
            }
            self::$seeninstitutions = [];
            $summary['orgs'][$org] = count($users);
            foreach ($users as $cu) {
                $result = self::apply($cu);
                $summary[$result]++;
                if ($result === 'nomoodleuser' && count($summary['unmatched']) < 20) {
                    $summary['unmatched'][] = $org . '/' . ($cu['name'] ?? '?') . ' <' . ($cu['email'] ?? 'no email') . '>';
                }
            }
            $error = self::store_branding($org, array_keys(self::$seeninstitutions));
            if ($error !== null) {
                $summary['errors'][] = $org . ' (logo): ' . $error;
            }
        }
        return $summary;
    }

    /**
     * Copies the organisation's logo/favicon URLs onto the institution
     * records of the users just read. Returns an error string or null.
     */
    protected static function store_branding(string $org, array $institutions): ?string {
        global $DB;

        [$error, $info] = self::fetch_org_info($org);
        if ($error !== null || !$info) {
            return $error ?? 'no organisation data';
        }
        $logo = clean_param((string) ($info['logo'] ?? ''), PARAM_URL);
        $favicon = clean_param((string) ($info['favicon'] ?? ''), PARAM_URL);

        foreach ($institutions as $institution) {
            $rec = $DB->get_record('local_vikesa_institution', ['institution' => $institution]);
            if ($rec && ($rec->logourl !== $logo || $rec->faviconurl !== $favicon)) {
                $rec->logourl = $logo;
                $rec->faviconurl = $favicon;
                $rec->timemodified = time();
                $DB->update_record('local_vikesa_institution', $rec);
            }
        }
        return null;
    }
}
