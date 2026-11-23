<?php
// Maintain the site-wide entry point for the Origin plugin.

namespace local_coursemigrator_origin;

defined('MOODLE_INTERNAL') || die();

final class custom_menu {
    /** Add the course archival entry while preserving the site's other menu items. */
    public static function add(): void {
        global $CFG;

        $path = '/local/coursemigrator_origin/index.php';
        $url = (new \moodle_url($path))->out(false);
        $menu = (string)($CFG->custommenuitems ?? '');
        foreach (preg_split('/\R/', $menu) as $line) {
            $parts = explode('|', $line);
            $target = trim($parts[1] ?? '');
            if ($target === $url || $target === $path) {
                return;
            }
        }

        $line = 'Course Archival & Cleanup Manager|' . $url;
        $separator = $menu === '' || str_ends_with($menu, "\n") ? '' : "\n";
        set_config('custommenuitems', $menu . $separator . $line);
    }
}
