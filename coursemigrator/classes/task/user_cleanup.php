<?php
// Scheduled account cleanup for migrated course batches.

namespace local_coursemigrator\task;

defined('MOODLE_INTERNAL') || die();

class user_cleanup extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('taskusercleanup', 'local_coursemigrator');
    }

    public function execute(): void {
        global $DB;

        // An unset setting keeps scheduled runs in dry-run mode.
        $dryrun = (string)get_config('local_coursemigrator', 'usercleanupdryrun') !== '0';

        mtrace('User cleanup cron started.');
        mtrace('Execution mode: ' . ($dryrun ? 'DRY RUN' : 'LIVE'));


        // Step 1: Check the custom table.
        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('course_migrator');

            if (!$dbman->table_exists($table)) {
                throw new \coding_exception('The course_migrator table does not exist.');
            }

            mtrace('Step 1: course_migrator table exists.');
        } catch (\Throwable $e) {
            mtrace('STOP: Table check failed: ' . $e->getMessage());
            throw $e;
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
                mtrace('No skipped program/batch combinations found.');
                return;
            }

            mtrace(
                'Step 2: Found ' . count($records) .
                ' distinct program/batch combination(s).'
            );
        } catch (\Throwable $e) {
            mtrace('STOP: Unable to fetch records: ' . $e->getMessage());
            throw $e;
        }

        // Cache category IDs so each program is looked up only once.
        $programIdCache = [];

        $totalCandidates = 0;
        $totalDeleted = 0;
        $totalSuccessfulBatches = 0;
        $totalFailedBatches = 0;

        // Step 3: Process every distinct program/batch combination.
        foreach ($records as $record) {
            mtrace('----------------------------------------');
            mtrace('Processing record ID: ' . $record->id);

            $program = trim((string)($record->program ?? ''));
            $batch = trim((string)($record->batch ?? ''));

            if ($program === '' || $batch === '') {
                mtrace('SKIP: Program or batch is empty.');
                continue;
            }

            mtrace('Program: ' . $program);
            mtrace('Batch: ' . $batch);

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

                        mtrace(
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

                            mtrace(
                                'SKIP: Category idnumber contains no digits: ' .
                                $program
                            );
                        } else {
                            $programIdCache[$program] = $programId;

                            mtrace('Category ID: ' . $category->id);
                            mtrace('Category idnumber: ' . $category->idnumber);
                            mtrace('Extracted program ID: ' . $programId);
                        }
                    }
                } catch (\Throwable $e) {
                    $programIdCache[$program] = null;

                    mtrace(
                        'ERROR: Category lookup failed: ' . $e->getMessage()
                    );
                }
            }

            $programId = $programIdCache[$program];

            if ($programId === null) {
                continue;
            }

            // Step 3B: Extract the batch starting year.
            $batchPrefix = $this->get_batch_prefix($batch);

            if ($batchPrefix === null) {
                mtrace('SKIP: Cannot extract batch starting year: ' . $batch);
                continue;
            }

            mtrace('Extracted batch prefix: ' . $batchPrefix);

            // Step 4: Find users without active enrolments.
            $usernamePrefix = $batchPrefix . $programId;

            mtrace('Searching username prefix: ' . $usernamePrefix);

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

                    mtrace(
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
                    mtrace('No matching unenrolled users found.');
                    continue;
                }

                mtrace('Matching unenrolled users: ' . count($users));

                $totalCandidates += count($users);
                $batchDeletionFailed = false;
                $batchDeleted = 0;

                // Step 5: Delete matching users, only in live mode.
                foreach ($users as $matchedUser) {
                    mtrace('Candidate username: ' . $matchedUser->username);

                    if ($dryrun) {
                        mtrace(
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
                            mtrace(
                                'ERROR: User no longer exists or is already deleted: ' .
                                $matchedUser->username
                            );

                            $batchDeletionFailed = true;
                            continue;
                        }

                        // Never delete site administrator accounts.
                        if (is_siteadmin($user)) {
                            mtrace(
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
                            mtrace(
                                'ERROR: Deletion verification failed for user ID ' .
                                $user->id
                            );

                            $batchDeletionFailed = true;
                            continue;
                        }

                        mtrace(
                            'DELETED: ' . $user->username .
                            ' (ID: ' . $user->id . ')'
                        );

                        $batchDeleted++;
                        $totalDeleted++;
                    } catch (\Throwable $e) {
                        mtrace(
                            'ERROR: User deletion failed for ' .
                            $matchedUser->username . ': ' . $e->getMessage()
                        );

                        $batchDeletionFailed = true;
                    }
                }

                // Dry runs must never update cleanup statuses.
                if ($dryrun) {
                    mtrace(
                        'DRY RUN: No users deleted and no statuses updated for this batch.'
                    );
                    continue;
                }

                // Do not mark success if any user deletion failed or was protected.
                if ($batchDeletionFailed) {
                    $totalFailedBatches++;

                    mtrace(
                        'STATUS UNCHANGED: At least one user deletion failed ' .
                        'or was skipped for safety.'
                    );

                    continue;
                }

                if ($batchDeleted === 0) {
                    mtrace(
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

                    mtrace('STATUS UPDATED: user_cleanup_status = success for program "' . $program . '" and batch "' . $batch . '".');

                    mtrace('STATUS UPDATED: ' . $cleanupMessage);

                } catch (\Throwable $e) {
                    $totalFailedBatches++;

                    mtrace(
                        'ERROR: Users were deleted, but status update failed: ' .
                        $e->getMessage()
                    );
                }
            } catch (\Throwable $e) {
                $totalFailedBatches++;

                mtrace('ERROR: User lookup failed: ' . $e->getMessage());
                continue;
            }
        }

        // Step 7: Summary.
        mtrace('========================================');
        mtrace('User cleanup cron completed.');
        mtrace('Execution mode: ' . ($dryrun ? 'DRY RUN' : 'LIVE'));
        mtrace('Matching users found: ' . $totalCandidates);
        mtrace('Users deleted: ' . $totalDeleted);
        mtrace('Successful batch status updates: ' . $totalSuccessfulBatches);
        mtrace('Failed batches: ' . $totalFailedBatches);

        if ($dryrun) {
            mtrace('DRY RUN COMPLETE: No database changes were intentionally made.');
        }
    }

    private function get_batch_prefix(string $batch): ?string {
        $pattern = '/^\s*(?:batch\s*)?((?:19|20)\d{2}|\d{2})(?=[\s\/_-]|$)/i';

        if (!preg_match($pattern, $batch, $matches)) {
            return null;
        }

        return substr($matches[1], -2);
    }
}
