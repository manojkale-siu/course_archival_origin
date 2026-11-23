<?php
// Remove the site-wide menu entry when this plugin is uninstalled.

defined('MOODLE_INTERNAL') || die();

function xmldb_local_coursemigrator_origin_uninstall(): void {
    \local_coursemigrator_origin\custom_menu::remove();
}
