<?php
// Maintain the site-wide entry point for the Origin plugin.

namespace local_coursemigrator_origin;

defined('MOODLE_INTERNAL') || die();

final class custom_menu {
    private const LABEL = 'Course Archival & Cleanup Manager';
    private const PATH = '/local/coursemigrator_origin/index.php';

    /** Add the course archival entry while preserving the site's other menu items. */
    public static function add(): void {
        global $CFG;

        $url = (new \moodle_url(self::PATH))->out(false);
        $menu = (string)($CFG->custommenuitems ?? '');
        foreach (preg_split('/\R/', $menu) as $line) {
            $parts = explode('|', $line);
            $target = trim($parts[1] ?? '');
            if ($target === $url || $target === self::PATH) {
                return;
            }
        }

        $separator = $menu === '' || substr($menu, -1) === "\n" ? '' : "\n";
        set_config('custommenuitems', $menu . $separator . self::LABEL . '|' . $url);
    }

    /** Remove the generated entry and leave unrelated menu definitions intact. */
    public static function remove(): void {
        global $CFG;

        $url = (new \moodle_url(self::PATH))->out(false);
        $menu = (string)($CFG->custommenuitems ?? '');
        $remaining = '';
        foreach (preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $menu) as $line) {
            $parts = explode('|', $line);
            $target = trim($parts[1] ?? '');
            if (trim($parts[0]) === self::LABEL && ($target === $url || $target === self::PATH)) {
                continue;
            }
            $remaining .= $line;
        }

        if ($remaining !== $menu) {
            set_config('custommenuitems', $remaining);
        }
    }
}
