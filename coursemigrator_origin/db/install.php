<?php
// Install the Origin tables or reuse an existing standalone deployment's tables.

defined('MOODLE_INTERNAL') || die();

/**
 * Provision the database tables required by Course Migrator Origin.
 */
function xmldb_local_coursemigrator_origin_install(): void {
    global $DB;

    $manager = $DB->get_manager();
    $schema = $manager->load_xmldb_file(__DIR__ . '/origin_schema.xml')->getStructure();
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
}

/**
 * Resume an interrupted installation. The table and field checks make this repeatable.
 */
function xmldb_local_coursemigrator_origin_install_recovery(): void {
    xmldb_local_coursemigrator_origin_install();
}
