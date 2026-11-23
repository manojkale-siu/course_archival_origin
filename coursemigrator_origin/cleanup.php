<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/admin/tool/recyclebin/classes/category_bin.php');


function cleanup_course(int $courseid): array
{
    global $DB;

    $logs = [];

    $course_deleted = false;
    $recyclebin_deleted = false;

    try {

        // ---------------------------------------------------------
        // Validate course
        // ---------------------------------------------------------

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );

        $categoryid = (int)$course->category;

        if ($course->id == SITEID) {
            throw new moodle_exception(
                'Cannot delete the site course.'
            );
        }

        $logs[] = "Cleanup started for course ID: {$courseid}";
        $logs[] = "Course fullname: {$course->fullname}";
        $logs[] = "Course shortname: {$course->shortname}";
        $logs[] = "Original category ID: {$categoryid}";


        // ---------------------------------------------------------
        // Delete course using Moodle API
        // ---------------------------------------------------------

        $logs[] = 'Starting Moodle course deletion.';

        ob_start();

        $deleted = delete_course($courseid);

        $delete_output = ob_get_clean();

        if (!$deleted) {
            throw new moodle_exception(
                'Moodle course deletion failed.'
            );
        }

        $course_deleted = true;

        $logs[] =
            'Course deleted successfully using Moodle delete_course() API.';

        if (!empty(trim($delete_output))) {
            $logs[] =
                'Moodle deletion notifications captured successfully.';
        }


        // ---------------------------------------------------------
        // Permanently delete from Category Recycle Bin
        // ---------------------------------------------------------

        $logs[] = 'Course moved to Category Recycle Bin.';
        $logs[] = 'Starting permanent Recycle Bin deletion.';

        $recyclebin = new \tool_recyclebin\category_bin($categoryid);

        $recyclebinitems = $recyclebin->get_items();

        $targetitem = null;

        foreach ($recyclebinitems as $item) {

            if (
                isset($item->shortname) &&
                isset($item->fullname) &&
                $item->shortname === $course->shortname &&
                $item->fullname === $course->fullname
            ) {

                // If there are multiple matching items,
                // keep the newest one.
                if (
                    $targetitem === null ||
                    $item->timecreated > $targetitem->timecreated
                ) {
                    $targetitem = $item;
                }
            }
        }

        if (!$targetitem) {
            throw new moodle_exception(
                'Course was deleted but could not be found in the Category Recycle Bin.'
            );
        }

        $logs[] =
            "Recycle Bin item found. Item ID: {$targetitem->id}";

        $logs[] =
            "Recycle Bin item created at: {$targetitem->timecreated}";

        $recyclebin->delete_item($targetitem);

        $recyclebin_deleted = true;

        $logs[] = 'Course permanently deleted from Category Recycle Bin.';
            
        $temp_cleanup = cleanup_coursemigrator_temp();

        $logs = array_merge(
            $logs,
            $temp_cleanup['logs']
        );


        // ---------------------------------------------------------
        // Cleanup successful
        // ---------------------------------------------------------

        return [
            'success' => true,

            'courseid' => $courseid,

            'course_deleted' => true,

            'recyclebin_deleted' => true,
            
            'temp_cleanup' => $temp_cleanup,

            'message' => 'Origin course, Recycle Bin item, and temporary migration files cleaned successfully.',

            'logs' => $logs,
        ];

    } catch (Throwable $e) {

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $logs[] =
            'Cleanup failed: ' . $e->getMessage();

        return [
            'success' => false,

            'courseid' => $courseid,

            'course_deleted' => $course_deleted,

            'recyclebin_deleted' => $recyclebin_deleted,

            'message' =>
                'Origin course cleanup failed.',

            'error' =>
                $e->getMessage(),

            'logs' => $logs,
        ];
    }
}

function cleanup_coursemigrator_temp(): array
{
    global $CFG;

    $tempdir = $CFG->dataroot . '/temp/coursemigrator';

    $logs = [];
    $deleted = 0;
    $failed = 0;

    if (!is_dir($tempdir)) {
        return [
            'success' => true,
            'message' => 'Course Migrator temporary folder does not exist. Nothing to clean.',
            'logs' => [
                'Temporary folder not found: ' . $tempdir
            ]
        ];
    }

    $files = glob($tempdir . '/*');

    if ($files === false) {
        return [
            'success' => false,
            'message' => 'Unable to read Course Migrator temporary folder.',
            'logs' => [
                'Unable to read: ' . $tempdir
            ]
        ];
    }

    foreach ($files as $file) {

        // Only delete MBZ and JSON files.
        if (
            is_file($file) &&
            in_array(
                strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                ['mbz', 'json'],
                true
            )
        ) {
            if (@unlink($file)) {
                $deleted++;

                $logs[] =
                    'Deleted temporary file: ' . basename($file);
            } else {
                $failed++;

                $logs[] =
                    'Failed to delete temporary file: ' . basename($file);
            }
        }
    }

    if ($failed > 0) {
        return [
            'success' => false,
            'message' => 'Temporary MBZ/JSON cleanup completed with some failures.',
            'logs' => $logs
        ];
    }

    $logs[] =
        "Temporary cleanup completed. {$deleted} MBZ/JSON file(s) deleted.";

    return [
        'success' => true,
        'message' =>
            "Temporary cleanup completed successfully. {$deleted} MBZ/JSON file(s) deleted.",
        'logs' => $logs
    ];
}