<?php
// Install the Origin tables or reuse an existing standalone deployment's tables.

defined('MOODLE_INTERNAL') || die();

/**
 * Provision the database tables required by Course Migrator Origin.
 */
function xmldb_local_coursemigrator_origin_install(): void {
    global $DB;

    $manager = $DB->get_manager();
    $schemafile = new xmldb_file(__DIR__ . '/origin_schema.xml');
    if (!$schemafile->loadXMLStructure() || !$schemafile->isLoaded()) {
        throw new ddl_exception('ddlxmlfileerror', null, 'Could not load the Course Migrator Origin schema.');
    }
    $schema = $schemafile->getStructure();
    $legacyapproval = new xmldb_table('course_migrator_category_approval');
    $approval = $schema->getTable('course_migrator_approval');
    if (!$manager->table_exists($approval) && $manager->table_exists($legacyapproval)) {
        $manager->rename_table($legacyapproval, $approval->getName());
    }

    foreach ($schema->getTables() as $table) {
        if (!$manager->table_exists($table)) {
            $manager->create_table($table);
            continue;
        }

        // Preserve existing records and add any fields missing from an older deployment.
        foreach ($table->getFields() as $field) {
            if (!$manager->field_exists($table, $field)) {
                $manager->add_field($table, $field);
            }
        }
        foreach ($table->getIndexes() as $index) {
            if (!$manager->index_exists($table, $index)) {
                $manager->add_index($table, $index);
            }
        }
    }

    \local_coursemigrator_origin\custom_menu::add();
}

/**
 * Resume an interrupted installation. The table and field checks make this repeatable.
 */
function xmldb_local_coursemigrator_origin_install_recovery(): void {
    xmldb_local_coursemigrator_origin_install();
}
