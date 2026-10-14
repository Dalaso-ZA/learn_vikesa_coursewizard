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
}
