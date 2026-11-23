<?php
// Register the site-wide menu entry on existing Origin installations.

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the Course Migrator Origin plugin.
 *
 * @param int $oldversion Current installed plugin version
 * @return bool
 */
function xmldb_local_coursemigrator_origin_upgrade(int $oldversion): bool {
    if ($oldversion < 2026112302) {
        \local_coursemigrator_origin\custom_menu::add();
        upgrade_plugin_savepoint(true, 2026112302, 'local', 'coursemigrator_origin');
    }
    if ($oldversion < 2026112303) {
        require_once(__DIR__ . '/install.php');
        xmldb_local_coursemigrator_origin_install();
        upgrade_plugin_savepoint(true, 2026112303, 'local', 'coursemigrator_origin');
    }
    if ($oldversion < 2026112304) {
        // Moodle refreshes the default schedule after an upgrade. Update customized schedules too.
        $task = \core\task\manager::get_scheduled_task(
            \local_coursemigrator_origin\task\user_cleanup_cron::class
        );
        if ($task && $task->is_customised()) {
            $task->set_minute('0');
            $task->set_hour('11');
            $task->set_day('*');
            $task->set_month('*');
            $task->set_day_of_week('*');
            \core\task\manager::configure_scheduled_task($task);
        }
        upgrade_plugin_savepoint(true, 2026112304, 'local', 'coursemigrator_origin');
    }
    return true;
}
