<?php
// Origin backup.php file 

/**
 * Course Archival & Cleanup Manager - Origin Backup Handler
 *
 * Responsibilities:
 * - Validate the requested course and migration record.
 * - Create a Moodle course backup with the required settings.
 * - Retrieve stored source metadata and course hierarchy.
 * - Transfer the MBZ backup and metadata to the destination LMS.
 * - Validate the destination response and restored course.
 * - Record backup, transfer, and verification statuses.
 * - Trigger origin cleanup only after successful verification.
 * - Return processing results and diagnostic information as JSON.
 *
 * Related files: index.php, get_metadata.php, receive.php, cleanup.php.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'POST request is required.'
    ]);
    exit;
}

require_sesskey();

$destination_url = 'https://lmsarchive.siu.edu.in/local/coursemigrator/receive.php';
$receive_token = 'REPLACE_LATER';
if ($receive_token === 'REPLACE_LATER' || trim($receive_token) === '' || preg_match('/[\r\n]/', $receive_token)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => get_string('missingreceivetoken', 'local_coursemigrator_origin'),
    ]);
    exit;
}

$logs = [];
$migrationid = 0;
$backupcreated = false;

$courseid = required_param('courseid', PARAM_INT);
$migrationid = required_param('migrationid', PARAM_INT);

$course = $DB->get_record(
    'course',
    ['id' => $courseid],
    '*',
    MUST_EXIST
);

$context = context_course::instance($courseid);

require_capability(
    'moodle/backup:backupcourse',
    $context
);

$bc = null;

try {

    /*
     * Create a normal/general Moodle course backup.
     */
    $bc = new backup_controller(
        backup::TYPE_1COURSE,
        $courseid,
        backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $USER->id
    );

    $plan = $bc->get_plan();

    /*
     * These are the settings required for our migration backup.
     */
    $settings = [
        'users' => true,
        'anonymize' => false,
        'role_assignments' => true,
        'activities' => true,
        'blocks' => true,
        'files' => true,
        'filters' => true,
        'comments' => true,
        'badges' => true,
        'calendarevents' => true,
        'userscompletion' => true,
        'logs' => true,
        'grade_histories' => true,
        'questionbank' => true,
        'groups' => true,
        'competencies' => true,
        'customfield' => true,
        'contentbankcontent' => true,
        'xapistate' => true,
        'legacyfiles' => true,
    ];
    
    
    $institute_course = $DB->get_record(
        'course',
        ['id' => 1],
        'fullname',
        MUST_EXIST
    );

    /*
     * Force the required settings.
     */
    foreach ($settings as $name => $value) {

        if (!$plan->setting_exists($name)) {
            continue;
        }

        $setting = $plan->get_setting($name);

        /*
         * Make sure a site-level backup lock does not
         * prevent Course Migrator from applying its
         * migration-specific setting.
         */
        if ($setting->get_status() !== base_setting::NOT_LOCKED) {
            $setting->set_status(base_setting::NOT_LOCKED);
        }

        $setting->set_value($value);

        /*
         * Lock it for the remainder of this backup operation.
         */
        $setting->set_status(base_setting::LOCKED_BY_CONFIG);
    }

    /*
     * Build a readable backup filename.
     */
    // $filename = clean_filename(
    //     $course->shortname . '-' . date('Ymd-His') . '.mbz'
    // );
    
    
    
    // ---------------------------------------------------------
    // Build source category hierarchy for metadata
    // ---------------------------------------------------------
    
    $categorypath = [];
    $categoryid = (int)$course->category;
    
    while ($categoryid > 0) {
        $category = $DB->get_record(
            'course_categories',
            ['id' => $categoryid],
            'id,name,parent',
            MUST_EXIST
        );
    
        array_unshift($categorypath, $category);
        $categoryid = (int)$category->parent;
    }
    
    $categorynames = array_map(
        function($category) {
            return trim($category->name);
        },
        $categorypath
    );
    
    // Expected hierarchy:
    // Institute → Program → Batch → Semester → Specialization
    
    $institute      = trim($institute_course->fullname); // Recent change here .....
    // $institute      = $categorynames[0] ?? '';
    $program        = $categorynames[0] ?? '';
    $batch          = $categorynames[1] ?? '';
    $semester       = $categorynames[2] ?? '';
    $specialization = $categorynames[3] ?? '';
    
// commenting this for now to check the update... 
{    
    // ---------------------------------------------------------
    // Create Course Migrator tracking record
    // ---------------------------------------------------------
    
    // $migrationid = $DB->insert_record('course_migrator', [
    //     'source_course_id' => (int)$course->id,
    //     'source_course_fullname' => $course->fullname,
    //     'source_course_shortname' => $course->shortname,
    
    //     'institute' => $institute,
    //     'program' => $program,
    //     'batch' => $batch,
    //     'semester' => $semester,
    //     'specialization' => $specialization,
    
    //     'backup_status' => 'pending',
    //     'destination_status' => 'pending',
    //     'verification_status' => 'pending',
    //     'course_cleanup_status' => 'pending',
    //     'recyclebin_cleanup_status' => 'pending',
    //     'user_cleanup_status' => 'pending',
    
    //     'created_at' => time(),
    //     'updated_at' => time(),
    // ]);
    
}


    // ---------------------------------------------------------
    // Reuse the metadata record created by get_metadata.php
    // ---------------------------------------------------------

    // $migration = $DB->get_record(
    //     'course_migrator',
    //     ['source_course_id' => (int)$course->id],
    //     '*',
    //     IGNORE_MISSING
    // );
    
    $migration = $DB->get_record(
        'course_migrator',
        [
            'id' => $migrationid,
            'source_course_id' => $courseid,
        ],
        '*',
        MUST_EXIST
    );
    
    // Retrieve the source metadata collected by get_metadata.php.
    $sourcemetadatajson = $migration->course_metadata_json;
    
    if (empty($sourcemetadatajson)) {
        throw new moodle_exception('Source course metadata is missing. Please collect metadata before starting the backup.');
    }
    
    // Decode and validate the stored JSON before sending it.
    $sourcemetadatacounts = json_decode($sourcemetadatajson, true);
    
    if (!is_array($sourcemetadatacounts) || json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('Source course metadata contains invalid JSON.');
    }

    if ($migration) {
        $migrationid = (int)$migration->id;

        // Update course hierarchy without overwriting metadata JSON.
        $migration->source_course_fullname = $course->fullname;
        $migration->source_course_shortname = $course->shortname;
        $migration->institute = $institute;
        $migration->program = $program;
        $migration->batch = $batch;
        $migration->semester = $semester;
        $migration->specialization = $specialization;

        $migration->backup_status = 'pending';
        $migration->backup_message = 'Backup started.';
        $migration->destination_status = 'pending';
        $migration->destination_message = null;
        $migration->verification_status = 'pending';
        $migration->verification_message = null;
        $migration->course_cleanup_status = 'pending';
        $migration->recyclebin_cleanup_status = 'pending';
        $migration->user_cleanup_status = 'pending';
        $migration->error_message = null;
        $migration->updated_at = time();

        // course_metadata_json is deliberately left unchanged.
        $DB->update_record('course_migrator', $migration);

    } else {
        // Fallback if backup.php is run directly without get_metadata.php.
        $migrationid = (int)$DB->insert_record('course_migrator', [
            'source_course_id' => (int)$course->id,
            'source_course_fullname' => $course->fullname,
            'source_course_shortname' => $course->shortname,
            'institute' => $institute,
            'program' => $program,
            'batch' => $batch,
            'semester' => $semester,
            'specialization' => $specialization,
            'backup_status' => 'pending',
            'destination_status' => 'pending',
            'verification_status' => 'pending',
            'course_cleanup_status' => 'pending',
            'recyclebin_cleanup_status' => 'pending',
            'user_cleanup_status' => 'pending',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    
    // ---------------------------------------------------------
    // Human-readable MBZ filename
    // ---------------------------------------------------------
    
    $filenameparts = array_filter([
        $institute,
        $program,
        $batch,
        $semester,
        $specialization,
        $course->shortname ?: $course->fullname,
        date('Ymd-His'),
    ]);
    
    $filenamebase = implode('-', array_map(
        static function($value) {
            $value = trim($value);
    
            // Convert spaces/special characters to hyphens.
            $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    
            return trim($value, '-_.');
        },
        $filenameparts
    ));
    
    // Keep filename at a reasonable length.
    $filenamebase = substr($filenamebase, 0, 180);
    
    $filename = clean_filename($filenamebase . '.mbz');
    
    // ENDS HERE NEW CODE 
    // ===================================================================================
    

    $plan->get_setting('filename')->set_value($filename);

    /*
     * Make sure the backup includes physical files.
     *
     * For MODE_GENERAL this should normally already be true,
     * but we verify it before executing the plan.
     */
    if (!$bc->get_include_files()) {
        throw new moodle_exception(
            'Course Migrator could not enable file inclusion for this backup.'
        );
    }

    /*
     * Execute the backup.
     */
    $bc->execute_plan();

    /*
     * Get generated MBZ.
     */
    $results = $bc->get_results();

    if (
        !$results ||
        !isset($results['backup_destination'])
    ) {
        throw new moodle_exception(
            'Backup did not produce a result.'
        );
    }

    $file = $results['backup_destination'];

    if (!$file instanceof stored_file) {
        throw new moodle_exception(
            'Backup file was not created.'
        );
    }
    
    $backupcreated = true;

    /*
     * Save MBZ to Moodle temporary directory.
     */
    $tempdir = make_temp_directory('coursemigrator');

    $filepath = $tempdir . DIRECTORY_SEPARATOR . $filename;

    if (!$file->copy_content_to($filepath)) {
        throw new moodle_exception('Failed to copy backup file to temporary storage.');
    }
    
    $backupcreated = true;
    
    $DB->update_record('course_migrator', [
        'id' => $migrationid,
        'mbz_filename' => $filename,
        'backup_status' => 'success',
        'backup_message' => 'Backup created successfully.',
        'updated_at' => time(),
    ]);
    
    // ---------------------------------------------------------
    // Prepare migration metadata
    // ---------------------------------------------------------
    
    $metadata = [
        'source' => [
            'moodle_url' => $CFG->wwwroot,
            'course_id' => (int)$course->id,
            'category_id' => (int)$course->category,
        ],
    
        'course' => [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
        ],
        
        'metadata_counts' => $sourcemetadatacounts,
    
        'hierarchy' => [
            'institute' => $institute,
            'program' => $program,
            'batch' => $batch,
            'semester' => $semester,
            'specialization' => $specialization,
            'path' => $categorynames,
        ],
    
        'backup' => [
            'filename' => $filename,
            'created_at' => date('c'),
            'files_included' => (bool)$bc->get_include_files(),
        ],
    ];
    
    
    // ---------------------------------------------------------
    // Send MBZ + metadata to destination server
    // ---------------------------------------------------------
    
    $ch = curl_init($destination_url);
    
    $postfields = [
        'mbz' => new CURLFile(
            $filepath,
            'application/octet-stream',
            $filename
        ),
    
        'metadata' => json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES
        ),
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
    
        CURLOPT_POSTFIELDS => $postfields,
    
        CURLOPT_HTTPHEADER => [
            'X-Course-Migrator-Token: ' . $receive_token
        ],
    
        CURLOPT_RETURNTRANSFER => true,
    
        CURLOPT_TIMEOUT => 1800,
    
        CURLOPT_CONNECTTIMEOUT => 60,
    ]);
    
    $destination_response = curl_exec($ch);
    
    $curl_error = curl_error($ch);
    
    $http_code = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );
    
    curl_close($ch);
    
    
    // ---------------------------------------------------------
    // Check transfer result
    // ---------------------------------------------------------
    
    if ($destination_response === false || $curl_error) {
        throw new moodle_exception(
            'Failed to connect to destination server: ' . $curl_error
        );
    }
    
    $destination_data = json_decode(
        $destination_response,
        true
    );
    
    if (!is_array($destination_data)) {
        throw new moodle_exception(
            'Destination returned an invalid response. ' .
            'HTTP code: ' . $http_code .
            ' Response: ' . substr($destination_response, 0, 2000)
        );
    }
    
    if (
        $http_code < 200 ||
        $http_code >= 300 ||
        empty($destination_data['success'])
    ) {
        // $message = $destination_data['message'] ?? 'Destination rejected the backup.';
        $message = $destination_data['message'] ?? $destination_data['data']['error'] ?? 'Destination rejected the backup.';
        
        $destinationlogs = [];
    
        if (!empty($destination_data['data']['logs'])) {
            $destinationlogs = $destination_data['data']['logs'];
        }
    
        throw new moodle_exception( 'Destination error: ' . $message . (!empty($destinationlogs) ? ' | Logs: ' . implode(' -> ', $destinationlogs) : '') );
    }
    
    // ---------------------------------------------------------
    // Verify destination course before origin cleanup
    // ---------------------------------------------------------
    
    $destination_result = $destination_data['data'] ?? [];
    
    $verification = $destination_result['verification'] ?? [];
    
    $destination_course_id =
        (int)($verification['courseid'] ?? 0);
    
    $verification_success =
        !empty($verification['success']) &&
        !empty($verification['restore_completed']) &&
        !empty($verification['course_exists']) &&
        $destination_course_id > 0;
    
    if (!$verification_success) {
    
        $DB->update_record('course_migrator', [
            'id' => $migrationid,
            'verification_status' => 'failed',
            'verification_message' =>
                'Destination course verification failed. Origin cleanup was not performed.',
            'updated_at' => time(),
        ]);
    
        throw new moodle_exception(
            'Destination verification failed. Origin course cleanup was not performed.'
        );
    }
    
    // Destination has confirmed that the course exists.
    $DB->update_record('course_migrator', [
        'id' => $migrationid,
    
        'destination_course_id' =>
            $destination_course_id,
    
        'destination_course_fullname' =>
            $destination_result['course']['fullname'] ?? null,
    
        'destination_course_shortname' =>
            $destination_result['course']['shortname'] ?? null,
    
        'destination_status' => 'success',
    
        'destination_message' =>
            $destination_data['message'] ?? 'Destination accepted the backup.',
    
        'verification_status' => 'success',
    
        'verification_message' =>
            'Destination confirmed that the course was restored successfully and exists.',
    
        'updated_at' => time(),
    ]);
    
    $logs[] = 'Destination verification successful.';
    $logs[] = 'Destination course ID: ' . $destination_course_id;
    $logs[] = 'Origin cleanup is now permitted.';
    
    
    // Calling cleaning file here...
    {
        // ---------------------------------------------------------
        // Destination restore and verification successful.
        // Run origin cleanup.
        // ---------------------------------------------------------
        
        require_once(__DIR__ . '/cleanup.php');
        
        $cleanup_result = cleanup_course($courseid);
        
        $origin_cleanup_logs = $cleanup_result['logs'] ?? [];
        
        
        // ---------------------------------------------------------
        // Cleanup statuses
        // ---------------------------------------------------------
        
        $course_cleanup_status =
            !empty($cleanup_result['course_deleted'])
                ? 'success'
                : 'failed';
        
        $recyclebin_cleanup_status =
            !empty($cleanup_result['recyclebin_deleted'])
                ? 'success'
                : 'failed';
                
        $temp_cleanup_status =
            !empty($cleanup_result['temp_cleanup']['success'])
                ? 'success'
                : 'failed';
        
        // User deletion is intentionally not performed.
        $user_cleanup_status = 'skipped';
        
        
        // Overall cleanup status.
        if (
            $course_cleanup_status === 'success' &&
            $recyclebin_cleanup_status === 'success' &&
            $temp_cleanup_status === 'success'
        ) {
            $origin_cleanup_status = 'success';
        } else {
            $origin_cleanup_status = 'failed';
        }
        
        
        // ---------------------------------------------------------
        // Store cleanup result in migration table
        // ---------------------------------------------------------
        
        $DB->update_record('course_migrator', [
        
            'id' => $migrationid,
        
            'course_cleanup_status' =>
                $course_cleanup_status,
        
            'course_cleanup_message' =>
                !empty($cleanup_result['course_deleted'])
                    ? 'Origin course deleted successfully.'
                    : 'Origin course deletion failed.',
        
            'recyclebin_cleanup_status' =>
                $recyclebin_cleanup_status,
        
            'recyclebin_cleanup_message' =>
                !empty($cleanup_result['recyclebin_deleted'])
                    ? 'Course permanently deleted from the Recycle Bin.'
                    : 'Course could not be permanently deleted from the Recycle Bin.',
        
            'user_cleanup_status' =>
                $user_cleanup_status,
        
            'user_cleanup_message' =>
                'User cleanup skipped. Users are not deleted by Course Migrator.',
        
            'updated_at' => time(),
        ]);
        
        
        // ---------------------------------------------------------
        // Add cleanup logs to main migration logs
        // ---------------------------------------------------------
        
        $logs[] = 'Origin cleanup started.';
        
        foreach ($origin_cleanup_logs as $cleanup_log) {
            $logs[] = $cleanup_log;
        }
        
        $logs[] =
            'Origin course cleanup status: ' . $origin_cleanup_status;
    }
    
    // ENDS HERE
    

    /*
     * Return useful information for testing.
     */
    echo json_encode([
        'success' => true,
        
        'message' => 'Backup created, transferred, restored, verified, and origin cleanup completed successfully.',

        'courseid' => $courseid,

        'coursename' => $course->fullname,

        'shortname' => $course->shortname,

        'filename' => $filename,

        'filepath' => $filepath,

        'files_included' => (bool)$bc->get_include_files(),

        'metadata' => $metadata,
        
        'transfer' => [
            'success' => true,
            'destination_url' => $destination_url,
            'http_code' => $http_code,
            'response' => $destination_data,
        ],
        
        'logs' => [
            'Backup created successfully.',
            'MBZ file saved to temporary storage.',
            'Metadata generated successfully.',
            'MBZ uploaded to destination server.',
            'Destination accepted the backup.',
        ],
        
        'cleanup' => [
            'success' => $origin_cleanup_status === 'success',
        
            'message' => $cleanup_result['message'] ?? '',
        
            'course_cleanup_status' => $course_cleanup_status ?? 'pending',
        
            'recyclebin_cleanup_status' => $recyclebin_cleanup_status ?? 'pending',
        
            'user_cleanup_status' => $user_cleanup_status ?? 'pending',
        
            'logs' => $origin_cleanup_logs,
        ],
        
        'backup_settings' => [
            'users' => true,
            'anonymize' => false,
            'role_assignments' => true,
            'activities' => true,
            'blocks' => true,
            'files' => true,
            'filters' => true,
            'comments' => true,
            'badges' => true,
            'calendarevents' => true,
            'userscompletion' => true,
            'logs' => true,
            'grade_histories' => true,
            'questionbank' => true,
            'groups' => true,
            'competencies' => true,
            'customfield' => true,
            'contentbankcontent' => true,
            'xapistate' => true,
            'legacyfiles' => true,
        ],
        
    ]);

} catch (Throwable $e) {

    // http_response_code(500);
    // echo json_encode([
    //     'success' => false,
    //     'courseid' => $courseid,
    //     'message' => $e->getMessage(),
    // ]);
    
    http_response_code(500);

    $message = $e->getMessage();

    if (!empty($migrationid)) {
        try {
            $updates = [
                'id' => $migrationid,
                'updated_at' => time(),
            ];

            if ($backupcreated) {
                // MBZ creation succeeded; a later step failed.
                $updates['backup_status'] = 'success';
                $updates['backup_message'] = 'Backup created successfully.';
                $updates['destination_status'] = 'failed';
                $updates['destination_message'] = $message;
            } else {
                $updates['backup_status'] = 'failed';
                $updates['backup_message'] = $message;
            }

            $updates['error_message'] = $message;

            $DB->update_record('course_migrator', (object)$updates);

        } catch (Throwable $dbexception) {
            
            // debugging(
            //     'Course Migrator could not update failure status: ' .
            //     $dbexception->getMessage(),
            //     DEBUG_DEVELOPER
            // );
            
            error_log(
                '[Course Migrator] Failed to update migration error status. ' .
                'Migration ID: ' . (int)$migrationid .
                '. Error: ' . $dbexception->getMessage()
            );
            
        }
    }

    echo json_encode([
        'success' => false,
        'courseid' => $courseid,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} finally {

    if ($bc) {
        $bc->destroy();
    }
}