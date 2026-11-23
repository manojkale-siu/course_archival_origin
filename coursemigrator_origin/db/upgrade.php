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
    return true;
}
