<?php
// Administrator navigation and settings for Course Migrator Origin.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursemigrator_settings', get_string('pluginname', 'local_coursemigrator'));
    $settings->add(new admin_setting_configcheckbox(
        'local_coursemigrator/usercleanupdryrun',
        get_string('usercleanupdryrun', 'local_coursemigrator'),
        get_string('usercleanupdryrun_desc', 'local_coursemigrator'),
        1
    ));
    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_coursemigrator_origin',
        get_string('pluginname', 'local_coursemigrator'),
        new moodle_url('/local/coursemigrator/index.php')
    ));
}
