<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('courses', new admin_externalpage(
        'local_vikesa_coursewizard_institutions',
        get_string('institutions', 'local_vikesa_coursewizard'),
        new moodle_url('/local/vikesa_coursewizard/admin/institutions.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('courses', new admin_externalpage(
        'local_vikesa_coursewizard_resync',
        get_string('resync', 'local_vikesa_coursewizard'),
        new moodle_url('/local/vikesa_coursewizard/admin/resync.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('courses', new admin_externalpage(
        'local_vikesa_coursewizard_editorlimits',
        get_string('editorlimits', 'local_vikesa_coursewizard'),
        new moodle_url('/local/vikesa_coursewizard/admin/editorlimits.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('courses', new admin_externalpage(
        'local_vikesa_coursewizard_diagnose',
        get_string('diagnose', 'local_vikesa_coursewizard'),
        new moodle_url('/local/vikesa_coursewizard/admin/diagnose.php'),
        'moodle/site:config'
    ));

    $settings = new admin_settingpage('local_vikesa_coursewizard_casdoor',
        get_string('casdoor_settings', 'local_vikesa_coursewizard'));
    $issuers = [0 => get_string('casdoor_none', 'local_vikesa_coursewizard')];
    foreach (\core\oauth2\api::get_all_issuers() as $issuer) {
        $issuers[$issuer->get('id')] = $issuer->get('name');
    }
    $settings->add(new admin_setting_configselect('local_vikesa_coursewizard/casdoor_issuerid',
        get_string('casdoor_issuer', 'local_vikesa_coursewizard'),
        get_string('casdoor_issuer_desc', 'local_vikesa_coursewizard'), 0, $issuers));
    $settings->add(new admin_setting_configtext('local_vikesa_coursewizard/casdoor_baseurl',
        get_string('casdoor_baseurl', 'local_vikesa_coursewizard'),
        get_string('casdoor_baseurl_desc', 'local_vikesa_coursewizard'), '', PARAM_URL));
    $settings->add(new admin_setting_configtextarea('local_vikesa_coursewizard/casdoor_orgs',
        get_string('casdoor_orgs', 'local_vikesa_coursewizard'),
        get_string('casdoor_orgs_desc', 'local_vikesa_coursewizard'), '', PARAM_RAW_TRIMMED));
    $ADMIN->add('localplugins', $settings);

    // --- AI and storage -------------------------------------------------------------
    $ai = new admin_settingpage('local_vikesa_coursewizard_ai',
        get_string('ai_settings', 'local_vikesa_coursewizard'));
    $ai->add(new admin_setting_heading('vikesa_ai_head', get_string('ai_head', 'local_vikesa_coursewizard'),
        get_string('ai_head_desc', 'local_vikesa_coursewizard')));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/ai_baseurl',
        get_string('ai_baseurl', 'local_vikesa_coursewizard'),
        get_string('ai_baseurl_desc', 'local_vikesa_coursewizard'), '', PARAM_URL));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/ai_model',
        get_string('ai_model', 'local_vikesa_coursewizard'),
        get_string('ai_model_desc', 'local_vikesa_coursewizard'), '', PARAM_TEXT));
    $ai->add(new admin_setting_configpasswordunmask('local_vikesa_coursewizard/ai_apikey',
        get_string('ai_apikey', 'local_vikesa_coursewizard'),
        get_string('ai_apikey_desc', 'local_vikesa_coursewizard'), ''));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/ai_maxpages',
        get_string('ai_maxpages', 'local_vikesa_coursewizard'),
        get_string('ai_maxpages_desc', 'local_vikesa_coursewizard'), 200, PARAM_INT));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/ai_maxtokens',
        get_string('ai_maxtokens', 'local_vikesa_coursewizard'),
        get_string('ai_maxtokens_desc', 'local_vikesa_coursewizard'), 150000, PARAM_INT));

    $ai->add(new admin_setting_heading('vikesa_quota_head', get_string('quota_head', 'local_vikesa_coursewizard'),
        get_string('quota_head_desc', 'local_vikesa_coursewizard')));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/quota_storage_gb',
        get_string('quota_storage_gb', 'local_vikesa_coursewizard'), '', 50, PARAM_INT));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/quota_tokens',
        get_string('quota_tokens', 'local_vikesa_coursewizard'), '', 1000000, PARAM_INT));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/quota_jobs',
        get_string('quota_jobs', 'local_vikesa_coursewizard'), '', 30, PARAM_INT));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/quota_pages',
        get_string('quota_pages', 'local_vikesa_coursewizard'), '', 300, PARAM_INT));

    $ai->add(new admin_setting_heading('vikesa_private_head', get_string('tier_private_head', 'local_vikesa_coursewizard'),
        get_string('tier_private_head_desc', 'local_vikesa_coursewizard')));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_private_endpoint',
        get_string('tier_endpoint', 'local_vikesa_coursewizard'), '', '', PARAM_URL));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_private_bucket',
        get_string('tier_bucket', 'local_vikesa_coursewizard'), '', '', PARAM_TEXT));
    $ai->add(new admin_setting_configpasswordunmask('local_vikesa_coursewizard/tier_private_accesskey',
        get_string('tier_accesskey', 'local_vikesa_coursewizard'), '', ''));
    $ai->add(new admin_setting_configpasswordunmask('local_vikesa_coursewizard/tier_private_secret',
        get_string('tier_secret', 'local_vikesa_coursewizard'), '', ''));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_private_expiryhours',
        get_string('tier_expiryhours', 'local_vikesa_coursewizard'),
        get_string('tier_expiryhours_desc', 'local_vikesa_coursewizard'), 6, PARAM_INT));

    $ai->add(new admin_setting_heading('vikesa_cdn_head', get_string('tier_cdn_head', 'local_vikesa_coursewizard'),
        get_string('tier_cdn_head_desc', 'local_vikesa_coursewizard')));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_cdn_endpoint',
        get_string('tier_endpoint', 'local_vikesa_coursewizard'), '', '', PARAM_URL));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_cdn_bucket',
        get_string('tier_zone', 'local_vikesa_coursewizard'), '', '', PARAM_TEXT));
    $ai->add(new admin_setting_configpasswordunmask('local_vikesa_coursewizard/tier_cdn_accesskey',
        get_string('tier_zonekey', 'local_vikesa_coursewizard'), '', ''));
    $ai->add(new admin_setting_configpasswordunmask('local_vikesa_coursewizard/tier_cdn_secret',
        get_string('tier_tokenkey', 'local_vikesa_coursewizard'), '', ''));
    $ai->add(new admin_setting_configtext('local_vikesa_coursewizard/tier_cdn_expiryhours',
        get_string('tier_expiryhours', 'local_vikesa_coursewizard'),
        get_string('tier_expiryhours_desc', 'local_vikesa_coursewizard'), 0, PARAM_INT));
    $ADMIN->add('localplugins', $ai);
}
