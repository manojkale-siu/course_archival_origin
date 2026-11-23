<?php
// get_metadata.php

/**
 * Course Archival & Cleanup Manager - Metadata Handler
 *
 * Responsibilities:
 * - Validate the selected course IDs and Moodle session key.
 * - Collect course, enrolment, file, activity, attendance, and feedback counts.
 * - Build the course category hierarchy and institute information.
 * - Insert or update migration records in the course_migrator table.
 * - Return metadata and migration IDs as JSON to the frontend.
 *
 * Related files: index.php, ajax.php, backup.php.
 */

use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

require_login();
access::require_management(true);

global $DB, $CFG, $USER;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * Return a JSON response.
 */
function metadata_json_response(array $data, int $httpcode = 200): void {
    http_response_code($httpcode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Collect metadata counts for one course.
 * Every returned metadata value is an integer.
 */
function get_course_metadata_counts(int $courseid): array {
    global $DB;

    $context = context_course::instance($courseid);

    // 1. Active enrolled users.
    $enrolledusers = (int)$DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid)
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {user} u ON u.id = ue.userid
          WHERE e.courseid = :courseid
            AND e.status = 0
            AND ue.status = 0
            AND u.deleted = 0
            AND u.id <> :guestid",
        [
            'courseid' => $courseid,
            'guestid' => (int)$DB->get_field(
                'user',
                'id',
                ['username' => 'guest']
            ),
        ]
    );

    // 2. All file records in the course context and descendant contexts.
    // Includes course resources, assignment submissions and activity files.
    // $coursefiles = (int)$DB->count_records_sql(
    //     "SELECT COUNT(f.id)
    //       FROM {files} f
    //       JOIN {context} ctx ON ctx.id = f.contextid
    //       WHERE (
    //                 ctx.id = :contextid
    //                 OR ctx.path LIKE :contextpath
    //             )
    //         AND f.filename <> :directory",
    //     [
    //         'contextid' => $context->id,
    //         'contextpath' => $context->path . '/%',
    //         'directory' => '.',
    //     ]
    // );
    
    $coursefiles = (int)$DB->count_records_sql(
        "SELECT COUNT(f.id)
           FROM {files} f
           JOIN {context} ctx ON ctx.id = f.contextid
          WHERE (
                    ctx.id = :contextid
                    OR ctx.path LIKE :contextpath
                )
            AND f.filename <> :directory
            AND NOT (
                f.component = :editpdfcomponent
                AND f.filearea IN (:combined, :pages)
            )
            AND NOT (
                f.component = :backupcomponent
                AND f.filearea = :backuparea
            )",
        [
            'contextid' => $context->id,
            'contextpath' => $context->path . '/%',
            'directory' => '.',
            'editpdfcomponent' => 'assignfeedback_editpdf',
            'combined' => 'combined',
            'pages' => 'pages',
            'backupcomponent' => 'backup',
            'backuparea' => 'course',
        ]
    );
    

    // 3. Private files belonging to users actively enrolled in this course. We are getting all the users files not just the students.
    $privatefiles = (int)$DB->count_records_sql(
        "SELECT COUNT(f.id)
           FROM {files} f
           JOIN {context} uc ON uc.id = f.contextid
          WHERE uc.contextlevel = :usercontext
            AND f.component = :component
            AND f.filearea = :filearea
            AND f.filename <> :directory
            AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = uc.instanceid
                   AND e.courseid = :courseid
                   AND e.status = 0
                   AND ue.status = 0
            )",
        [
            'usercontext' => CONTEXT_USER,
            'component' => 'user',
            'filearea' => 'private',
            'directory' => '.',
            'courseid' => $courseid,
        ]
    );

    // 4. Course activities excluding modules being deleted.
    $activities = (int)$DB->count_records_sql(
        "SELECT COUNT(id)
           FROM {course_modules}
          WHERE course = :courseid
            AND module <> 0
            AND deletioninprogress = 0",
        ['courseid' => $courseid]
    );

    // 5. Attendance activity and session counts.
    $attendanceactivities = 0;
    $attendancesessions = 0;

    if ($DB->get_manager()->table_exists(new xmldb_table('attendance'))) {
        $attendanceactivities = (int)$DB->count_records(
            'attendance',
            ['course' => $courseid]
        );

        $attendancesessions = (int)$DB->count_records_sql(
            "SELECT COUNT(ats.id)
               FROM {attendance_sessions} ats
               JOIN {attendance} a ON a.id = ats.attendanceid
              WHERE a.course = :courseid",
            ['courseid' => $courseid]
        );
    }

    // 6. Feedback activities and submitted responses.
    $feedbackactivities = 0;
    // $feedbackresponses = 0;

    if ($DB->get_manager()->table_exists(new xmldb_table('feedback'))) {
        $feedbackactivities = (int)$DB->count_records(
            'feedback',
            ['course' => $courseid]
        );

        // $feedbackresponses = (int)$DB->count_records_sql(
        //     "SELECT COUNT(fc.id)
        //       FROM {feedback_completed} fc
        //       JOIN {feedback} f ON f.id = fc.feedback
        //       WHERE f.course = :courseid",
        //     ['courseid' => $courseid]
        // );
    }

    return [
        'enrolled_users' => max(0, $enrolledusers),
        'course_files' => max(0, $coursefiles),
        'students_private_files' => max(0, $privatefiles),
        'activities' => max(0, $activities),
        'attendance_activities' => max(0, $attendanceactivities),
        'attendance_sessions' => max(0, $attendancesessions),
        'feedback_activities' => max(0, $feedbackactivities),
        // 'feedback_responses' => max(0, $feedbackresponses),
    ];
}

// error_log('COLUMNS: ' . implode(',', array_keys($DB->get_columns('course_migrator'))));

try {

    $rawinput = file_get_contents('php://input');
    $input = json_decode($rawinput, true);

    if (
        !is_array($input) ||
        empty($input['courseids']) ||
        !is_array($input['courseids'])
    ) {
        metadata_json_response(['success' => false], 400);
    }

    if (
        empty($input['sesskey']) ||
        !confirm_sesskey($input['sesskey'])
    ) {
        metadata_json_response(['success' => false], 403);
    }

    $courseids = array_values(array_unique(array_filter(
        array_map('intval', $input['courseids']),
        static function ($id) {
            return $id > SITEID;
        }
    )));

    if (!$courseids) {
        metadata_json_response(['success' => false], 400);
    }

    // Use the actual origin table: course_migrator.
    $table = 'course_migrator';
    
    
    if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
        metadata_json_response([
            'success' => false,
            'message' => 'The Course Migrator database table has not been created. Please configure the Course Migrator component before proceeding.'
        ], 500);
    }

    $results = [];

    foreach ($courseids as $courseid) {
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,fullname,shortname,category',
            IGNORE_MISSING
        );

        if (!$course) {
            continue;
        }

        // Calculate counts and encode only the count keys into JSON.
        $counts = get_course_metadata_counts($courseid);

        $metadatajson = json_encode(
            $counts,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($metadatajson === false) {
            throw new coding_exception('Failed to encode course metadata JSON.');
        }
        
        //remove this later
        // {
        // // DEBUG: Confirm the metadata JSON before saving.
        // error_log('[CourseMigrator] Course ID: ' . $courseid);
        // error_log('[CourseMigrator] Encoded metadata JSON: ' . $metadatajson);
        // error_log('[CourseMigrator] Target table: ' . $table);
        // }

        // Build category hierarchy from the course's category ancestors.
        $categorynames = [];
        $categoryid = (int)$course->category;

        while ($categoryid > 0) {
            $category = $DB->get_record(
                'course_categories',
                ['id' => $categoryid],
                'id,name,parent',
                IGNORE_MISSING
            );

            if (!$category) {
                break;
            }

            array_unshift($categorynames, $category->name);
            $categoryid = (int)$category->parent;
        }

        // Institute name is taken from the fixed source course ID 1.
        $institute = '';

        $institutecourse = $DB->get_record(
            'course',
            ['id' => 1],
            'fullname',
            IGNORE_MISSING
        );

        if ($institutecourse) {
            $institute = trim($institutecourse->fullname);
        }

        // Match the existing record by origin course ID.
        $existing = $DB->get_record(
            $table,
            ['source_course_id' => $courseid],
            'id',
            IGNORE_MULTIPLE
        );

        $now = time();

        if ($existing) {
            // Update metadata and course information on the existing record.
            $recorddata = [
                'id' => $existing->id,
                'source_course_id' => $course->id,
                'source_course_fullname' => $course->fullname,
                'source_course_shortname' => $course->shortname,
                'course_metadata_json' => $metadatajson,
                'institute' => $institute,
                'program' => $categorynames[0] ?? '',
                'batch' => $categorynames[1] ?? '',
                'semester' => $categorynames[2] ?? '',
                'specialization' => $categorynames[3] ?? '',
                'updated_at' => $now,
            ];

            // Pass the complete, explicitly defined record directly to Moodle DML.
            // This ensures course_metadata_json is not dropped by column-key filtering.
            $DB->update_record($table, (object)$recorddata);
            $migrationid = (int)$existing->id;
            
            
            //remove this later
            // {
            // // DEBUG: Read back the value after updating.
            // $savedrecord = $DB->get_record(
            //     $table,
            //     ['id' => $migrationid],
            //     'id,source_course_id,course_metadata_json',
            //     MUST_EXIST
            // );
            
            // error_log('[CourseMigrator] Updated migration ID: ' . $migrationid);
            // error_log('[CourseMigrator] Saved metadata JSON: ' . var_export($savedrecord->course_metadata_json, true));
            // }
            

        } else {
            // Insert a new migration record with the metadata JSON.
            $recorddata = [
                'source_course_id' => $course->id,
                'source_course_fullname' => $course->fullname,
                'source_course_shortname' => $course->shortname,
                'course_metadata_json' => $metadatajson,
                'institute' => $institute,
                'program' => $categorynames[0] ?? '',
                'batch' => $categorynames[1] ?? '',
                'semester' => $categorynames[2] ?? '',
                'specialization' => $categorynames[3] ?? '',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Include course_metadata_json explicitly when creating the record.
            $migrationid = (int)$DB->insert_record(
                $table,
                (object)$recorddata
            );
            
            //remove this later.
            // {
            // // DEBUG: Read back the value after inserting.
            // $savedrecord = $DB->get_record(
            //     $table,
            //     ['id' => $migrationid],
            //     'id,source_course_id,course_metadata_json',
            //     MUST_EXIST
            // );
            
            // error_log('[CourseMigrator] Inserted migration ID: ' . $migrationid);
            // error_log('[CourseMigrator] Saved metadata JSON: ' . var_export($savedrecord->course_metadata_json, true));
            // }
            
        }

        $results[] = [
            'courseid' => (int)$courseid,
            'migrationid' => $migrationid,
            'metadata' => $counts,
        ];
    }

    metadata_json_response([
        'success' => true,
        'data' => $results,
    ]);

} catch (Throwable $e) {
    // Error details are not written into course_metadata_json.
    // debugging(
    //     'Course Migrator metadata collection failed: ' . $e->getMessage(),
    //     DEBUG_DEVELOPER
    // );
    
    error_log( '[Course Migrator Metadata] ' . 'User ID: ' . (int)$USER->id . ', Error: ' . $e->getMessage() );

    metadata_json_response(['success' => false], 500);
}