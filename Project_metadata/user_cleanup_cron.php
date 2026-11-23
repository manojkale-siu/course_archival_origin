<?php
/**
 * File: public_html/attendance-report/user_cleanup_cron.php
 *
 * Moodle user cleanup cron.
 *
 * - Processes distinct program/batch combinations.
 * - Finds matching parent categories and extracts numeric program IDs.
 * - Extracts the starting batch year.
 * - Finds users without active enrolments.
 * - Optionally deletes users using Moodle's supported API.
 * - Marks matching course_migrator records as success only after
 *   successful deletion and verification.
 *
 * IMPORTANT:
 * DRY_RUN = true  : No users are deleted and no statuses are updated.
 * DRY_RUN = false : Matching eligible users can be deleted.
 */

// SAFETY: Start in dry-run mode.
define('CLI_SCRIPT', true);
define('DEBUG', true);
define('DRY_RUN', false);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only run from the command line.');
}

require_once(__DIR__ . '/../config.php');

global $DB, $CFG;

require_once($CFG->libdir . '/moodlelib.php');

/**
 * Print a timestamped log message.
 */
function log_step(string $message): void {
    if (DEBUG) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

/**
 * Extract the starting year from a batch name.
 *
 * Supported examples:
 * Batch 2021-22
 * Batch 21-22
 * 2021-22
 * 21-22
 * 21_22
 * Batch 2021_22
 *
 * Returns the two-digit starting year, or null if invalid.
 */
function get_batch_prefix(string $batch): ?string {
    $pattern = '/^\s*(?:batch\s*)?((?:19|20)\d{2}|\d{2})(?=[\s\/_-]|$)/i';

    if (!preg_match($pattern, $batch, $matches)) {
        return null;
    }

    return substr($matches[1], -2);
}

log_step('User cleanup cron started.');
log_step('Execution mode: ' . (DRY_RUN ? 'DRY RUN' : 'LIVE'));
log_step('No user deletion or status update is performed in dry-run mode.');

// Step 1: Check the custom table.
try {
    $dbman = $DB->get_manager();
    $table = new xmldb_table('course_migrator');

    if (!$dbman->table_exists($table)) {
        log_step('STOP: course_migrator table does not exist.');
        exit(1);
    }

    log_step('Step 1: course_migrator table exists.');
} catch (Throwable $e) {
    log_step('STOP: Table check failed: ' . $e->getMessage());
    exit(1);
}

// Step 2: Get distinct program/batch combinations.
// No LIMIT is used.
try {
    $records = $DB->get_records_sql(
        "SELECT MIN(id) AS id, program, batch
           FROM {course_migrator}
          WHERE user_cleanup_status = :status
          GROUP BY program, batch
          ORDER BY MIN(id) ASC",
        ['status' => 'skipped']
    );

    if (empty($records)) {
        log_step('No skipped program/batch combinations found.');
        exit(0);
    }

    log_step(
        'Step 2: Found ' . count($records) .
        ' distinct program/batch combination(s).'
    );
} catch (Throwable $e) {
    log_step('STOP: Unable to fetch records: ' . $e->getMessage());
    exit(1);
}

// Cache category IDs so each program is looked up only once.
$programIdCache = [];

$totalCandidates = 0;
$totalDeleted = 0;
$totalSuccessfulBatches = 0;
$totalFailedBatches = 0;

// Step 3: Process every distinct program/batch combination.
foreach ($records as $record) {
    log_step('----------------------------------------');
    log_step('Processing record ID: ' . $record->id);

    $program = trim((string)($record->program ?? ''));
    $batch = trim((string)($record->batch ?? ''));

    if ($program === '' || $batch === '') {
        log_step('SKIP: Program or batch is empty.');
        continue;
    }

    log_step('Program: ' . $program);
    log_step('Batch: ' . $batch);

    // Step 3A: Find the parent category and its program ID.
    if (!array_key_exists($program, $programIdCache)) {
        try {
            $categories = $DB->get_records(
                'course_categories',
                [
                    'name' => $program,
                    'parent' => 0,
                ],
                'id ASC',
                'id, name, idnumber, parent'
            );

            if (count($categories) !== 1) {
                $programIdCache[$program] = null;

                log_step(
                    'SKIP: Parent category missing or ambiguous: ' .
                    $program
                );
            } else {
                $category = reset($categories);

                // Remove every non-digit character.
                $programId = preg_replace(
                    '/\D+/',
                    '',
                    (string)$category->idnumber
                );

                if ($programId === '') {
                    $programIdCache[$program] = null;

                    log_step(
                        'SKIP: Category idnumber contains no digits: ' .
                        $program
                    );
                } else {
                    $programIdCache[$program] = $programId;

                    log_step('Category ID: ' . $category->id);
                    log_step('Category idnumber: ' . $category->idnumber);
                    log_step('Extracted program ID: ' . $programId);
                }
            }
        } catch (Throwable $e) {
            $programIdCache[$program] = null;

            log_step(
                'ERROR: Category lookup failed: ' . $e->getMessage()
            );
        }
    }

    $programId = $programIdCache[$program];

    if ($programId === null) {
        continue;
    }

    // Step 3B: Extract the batch starting year.
    $batchPrefix = get_batch_prefix($batch);

    if ($batchPrefix === null) {
        log_step('SKIP: Cannot extract batch starting year: ' . $batch);
        continue;
    }

    log_step('Extracted batch prefix: ' . $batchPrefix);

    // Step 4: Find users without active enrolments.
    $usernamePrefix = $batchPrefix . $programId;

    log_step('Searching username prefix: ' . $usernamePrefix);

    $sql = "SELECT u.id, u.username
              FROM {user} u
             WHERE u.deleted = 0
               AND u.username LIKE :pattern
               AND NOT EXISTS (
                   SELECT 1
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE ue.userid = u.id
                      AND ue.status = 0
                      AND e.status = 0
               )
          ORDER BY u.id ASC";

    try {
        $users = $DB->get_records_sql(
            $sql,
            [
                'pattern' => $DB->sql_like_escape($usernamePrefix) . '%',
            ]
        );

        // Step 4B: Retry with a zero if the program ID doesn't start with 0.
        if (empty($users) && substr($programId, 0, 1) !== '0') {
            $fallbackPrefix = $batchPrefix . '0' . $programId;

            log_step(
                'No matching users. Retrying with prefix: ' .
                $fallbackPrefix
            );

            $users = $DB->get_records_sql(
                $sql,
                [
                    'pattern' => $DB->sql_like_escape($fallbackPrefix) . '%',
                ]
            );
        }

        if (empty($users)) {
            log_step('No matching unenrolled users found.');
            continue;
        }

        log_step('Matching unenrolled users: ' . count($users));

        $totalCandidates += count($users);
        $batchDeletionFailed = false;
        $batchDeleted = 0;

        // Step 5: Delete matching users, only in live mode.
        foreach ($users as $matchedUser) {
            log_step('Candidate username: ' . $matchedUser->username);

            if (DRY_RUN) {
                log_step(
                    'DRY RUN: User would be deleted: ' .
                    $matchedUser->username
                );
                continue;
            }

            try {
                // Fetch the complete user record required by Moodle.
                $user = $DB->get_record(
                    'user',
                    ['id' => $matchedUser->id, 'deleted' => 0],
                    '*',
                    IGNORE_MISSING
                );

                if (!$user) {
                    log_step(
                        'ERROR: User no longer exists or is already deleted: ' .
                        $matchedUser->username
                    );

                    $batchDeletionFailed = true;
                    continue;
                }

                // Never delete site administrator accounts.
                if (is_siteadmin($user)) {
                    log_step(
                        'PROTECTED: Skipping site administrator: ' .
                        $user->username
                    );

                    $batchDeletionFailed = true;
                    continue;
                }

                // Moodle-supported user deletion API.
                delete_user($user);

                // Verify that Moodle marked the account as deleted.
                $remainingUser = $DB->get_record(
                    'user',
                    ['id' => $user->id],
                    'id, deleted',
                    IGNORE_MISSING
                );

                if (
                    $remainingUser &&
                    (int)$remainingUser->deleted !== 1
                ) {
                    log_step(
                        'ERROR: Deletion verification failed for user ID ' .
                        $user->id
                    );

                    $batchDeletionFailed = true;
                    continue;
                }

                log_step(
                    'DELETED: ' . $user->username .
                    ' (ID: ' . $user->id . ')'
                );

                $batchDeleted++;
                $totalDeleted++;
            } catch (Throwable $e) {
                log_step(
                    'ERROR: User deletion failed for ' .
                    $matchedUser->username . ': ' . $e->getMessage()
                );

                $batchDeletionFailed = true;
            }
        }

        // Dry runs must never update cleanup statuses.
        if (DRY_RUN) {
            log_step(
                'DRY RUN: No users deleted and no statuses updated for this batch.'
            );
            continue;
        }

        // Do not mark success if any user deletion failed or was protected.
        if ($batchDeletionFailed) {
            $totalFailedBatches++;

            log_step(
                'STATUS UNCHANGED: At least one user deletion failed ' .
                'or was skipped for safety.'
            );

            continue;
        }

        if ($batchDeleted === 0) {
            log_step(
                'STATUS UNCHANGED: No users were deleted in live mode.'
            );
            continue;
        }

        // Step 6: Mark all matching skipped records successful.
        // This occurs only after all candidates were processed successfully.
        try {
            
            $cleanupMessage = sprintf(
                'User cleanup completed at %s. Users deleted: %d. Program: %s. Batch: %s.',
                date('Y-m-d H:i:s'),
                $batchDeleted,
                $program,
                $batch
            );
            
            $DB->set_field_select(
                'course_migrator',
                'user_cleanup_status',
                'success',
                'program = :program
                 AND batch = :batch
                 AND user_cleanup_status = :status',
                [
                    'program' => $program,
                    'batch' => $batch,
                    'status' => 'skipped',
                ]
            );
            
            $DB->set_field_select(
                'course_migrator',
                'user_cleanup_message',
                $cleanupMessage,
                'program = :program
                 AND batch = :batch
                 AND user_cleanup_status = :status',
                [
                    'program' => $program,
                    'batch' => $batch,
                    'status' => 'success',
                ]
            );

            $totalSuccessfulBatches++;

            log_step('STATUS UPDATED: user_cleanup_status = success for program "' . $program . '" and batch "' . $batch . '".');
            
            log_step('STATUS UPDATED: ' . $cleanupMessage);
            
        } catch (Throwable $e) {
            $totalFailedBatches++;

            log_step(
                'ERROR: Users were deleted, but status update failed: ' .
                $e->getMessage()
            );
        }
    } catch (Throwable $e) {
        $totalFailedBatches++;

        log_step('ERROR: User lookup failed: ' . $e->getMessage());
        continue;
    }
}

// Step 7: Summary.
log_step('========================================');
log_step('User cleanup cron completed.');
log_step('Execution mode: ' . (DRY_RUN ? 'DRY RUN' : 'LIVE'));
log_step('Matching users found: ' . $totalCandidates);
log_step('Users deleted: ' . $totalDeleted);
log_step('Successful batch status updates: ' . $totalSuccessfulBatches);
log_step('Failed batches: ' . $totalFailedBatches);

if (DRY_RUN) {
    log_step('DRY RUN COMPLETE: No database changes were intentionally made.');
}