<?php

namespace local_vikesa_coursewizard;

defined('MOODLE_INTERNAL') || die();

/**
 * Monthly limits per institution. An institution's own value wins; an empty
 * value means the site default from the AI and storage settings page.
 */
class quota {

    /** Quota key => [institution field, setting name, built-in default]. */
    const ITEMS = [
        'storage' => ['quotastorage', 'quota_storage_gb', 50],
        'tokens' => ['quotatokens', 'quota_tokens', 1000000],
        'jobs' => ['quotajobs', 'quota_jobs', 30],
        'pages' => ['quotapages', 'quota_pages', 300],
    ];

    /** Storage in GB, AI tokens, AI jobs or pages per month, as one number. */
    public static function effective(\stdClass $institution, string $key): int {
        [$field, $setting, $default] = self::ITEMS[$key];
        if ($institution->$field !== null) {
            return (int) $institution->$field;
        }
        $value = get_config('local_vikesa_coursewizard', $setting);
        return ($value === false || $value === '') ? $default : (int) $value;
    }
}
