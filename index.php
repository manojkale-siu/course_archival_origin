<?php
// Common entry point for the site-wide Course Archival menu item.

use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

$PAGE->set_url(new moodle_url('/local/coursemigrator_origin/index.php'));
$PAGE->set_context(context_system::instance());
require_login();

if (access::is_director()) {
    redirect(new moodle_url('/local/coursemigrator_origin/director_approval.php'));
}
if (access::can_manage()) {
    redirect(new moodle_url('/local/coursemigrator_origin/migration.php'));
}

access::deny('entryaccessdenied');
