<?php
// Add Origin settings navigation to the Moodle 4.0 plugins overview.

defined('MOODLE_INTERNAL') || die();

/**
 * Show the Origin settings action and setup note on the plugins overview.
 */
function local_coursemigrator_origin_before_footer(): void {
    global $CFG, $PAGE;

    $overview = new moodle_url('/' . $CFG->admin . '/plugins.php');
    if (!$PAGE->url->compare($overview, URL_MATCH_BASE) || !has_capability('moodle/site:config', context_system::instance())) {
        return;
    }

    // Moodle 4.0 does not supply an overview settings URL for local plugins.
    $settings = new moodle_url('/' . $CFG->admin . '/settings.php', [
        'section' => 'local_coursemigrator_origin_settings',
    ]);
    $data = json_encode([
        'url' => $settings->out(false),
        'label' => get_string('settings', 'core_plugin'),
        'note' => get_string('overviewnote', 'local_coursemigrator_origin'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $script = <<<'JS'
(function(data) {
    var row = document.querySelector('tr.name-local_coursemigrator_origin');
    if (!row) {
        return;
    }

    var actions = row.querySelector('td.settings, td.actions');
    if (actions && !row.querySelector('a.settings')) {
        var link = document.createElement('a');
        link.className = 'settings';
        link.href = data.url;
        link.textContent = data.label;
        actions.appendChild(link);
    }

    var notes = row.querySelector('td.notes');
    if (notes && !notes.querySelector('.local-coursemigrator-origin-note')) {
        var note = document.createElement('div');
        note.className = 'local-coursemigrator-origin-note';
        note.textContent = data.note;
        notes.appendChild(note);
    }
})
JS;
    $PAGE->requires->js_init_code($script . '(' . $data . ');');
}
