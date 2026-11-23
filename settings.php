<?php
// Administrator navigation and settings for Course Migrator Origin.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursemigrator_origin_settings', get_string('pluginname', 'local_coursemigrator_origin'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_coursemigrator_origin/setupnote',
            get_string('setupnoteheading', 'local_coursemigrator_origin'),
            get_string('setupnote', 'local_coursemigrator_origin')
        ));
        $settings->add(new admin_setting_encryptedpassword(
            'local_coursemigrator_origin/receivetoken',
            get_string('receivetoken', 'local_coursemigrator_origin'),
            get_string('receivetoken_desc', 'local_coursemigrator_origin')
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_coursemigrator_origin/usercleanupdryrun',
            get_string('usercleanupdryrun', 'local_coursemigrator_origin'),
            get_string('usercleanupdryrun_desc', 'local_coursemigrator_origin'),
            1
        ));
    }
    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_coursemigrator_origin_page',
        get_string('pluginname', 'local_coursemigrator_origin'),
        new moodle_url('/local/coursemigrator_origin/index.php')
    ));
}
