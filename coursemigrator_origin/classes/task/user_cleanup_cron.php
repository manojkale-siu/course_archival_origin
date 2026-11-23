<?php
// Scheduled account cleanup for migrated course batches.

namespace local_coursemigrator_origin\task;

defined('MOODLE_INTERNAL') || die();

class user_cleanup_cron extends \core\task\scheduled_task {
    private string $logfile;

    public function get_name(): string {
        return get_string('taskusercleanup', 'local_coursemigrator_origin');
    }

    public function execute(): void {
        $this->logfile = $this->get_log_file();
        $this->log('User cleanup cron run started.');
        try {
            $this->run_cleanup();
        } catch (\Throwable $e) {
            $this->log('User cleanup cron run failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->log('User cleanup cron run ended.');
        }
    }

    /**
     * Use a logs directory beside public_html, or in Moodle data on other layouts.
     */
    private function get_log_file(): string {
        global $CFG;

        $root = realpath($CFG->dirroot);
        if ($root === false) {
            throw new \RuntimeException('Could not resolve the Moodle installation directory.');
        }
        $directory = $CFG->dataroot . '/logs';
        for ($path = $root; dirname($path) !== $path; $path = dirname($path)) {
            if (strcasecmp(basename($path), 'public_html') === 0) {
                $directory = dirname($path) . '/logs';
                $root = $path;
                break;
            }
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the user cleanup log directory: ' . $directory);
        }
        $resolved = realpath($directory);
        if ($resolved === false || str_starts_with(strtolower($resolved . DIRECTORY_SEPARATOR),
                strtolower($root . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('The user cleanup log directory must be outside the web root.');
        }
        $file = $resolved . DIRECTORY_SEPARATOR . 'user_cleanup_cron.log';
        if (is_link($file)) {
            throw new \RuntimeException('The user cleanup log must not be a symbolic link.');
        }
        return $file;
    }

    /**
     * Record each message in Moodle task output and append it to the shared log.
     */
    private function log(string $message): void {
        $message = str_replace(["\r", "\n"], [' ', ' '], $message);
        $line = date('c') . ' ' . $message . PHP_EOL;
        if (@file_put_contents($this->logfile, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new \RuntimeException('Could not append to the user cleanup log: ' . $this->logfile);
        }
        mtrace($message);
    }

    private function run_cleanup(): void {
        global $DB;

        // An unset setting keeps scheduled runs in dry-run mode.
        $dryrun = (string)get_config('local_coursemigrator_origin', 'usercleanupdryrun') !== '0';

        $this->log('Execution mode: ' . ($dryrun ? 'DRY RUN' : 'LIVE'));


        // Step 1: Check the custom table.
        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('course_migrator');

            if (!$dbman->table_exists($table)) {
                throw new \coding_exception('The course_migrator table does not exist.');
            }

            $this->log('Step 1: course_migrator table exists.');
        } catch (\Throwable $e) {
            $this->log('STOP: Table check failed: ' . $e->getMessage());
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
                $this->log('No skipped program/batch combinations found.');
                return;
            }

            $this->log(
                'Step 2: Found ' . count($records) .
                ' distinct program/batch combination(s).'
            );
        } catch (\Throwable $e) {
            $this->log('STOP: Unable to fetch records: ' . $e->getMessage());
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
            $this->log('----------------------------------------');
            $this->log('Processing record ID: ' . $record->id);

            $program = trim((string)($record->program ?? ''));
            $batch = trim((string)($record->batch ?? ''));

            if ($program === '' || $batch === '') {
                $this->log('SKIP: Program or batch is empty.');
                continue;
            }

            $this->log('Program: ' . $program);
            $this->log('Batch: ' . $batch);

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

                        $this->log(
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

                            $this->log(
                                'SKIP: Category idnumber contains no digits: ' .
                                $program
                            );
                        } else {
                            $programIdCache[$program] = $programId;

                            $this->log('Category ID: ' . $category->id);
                            $this->log('Category idnumber: ' . $category->idnumber);
                            $this->log('Extracted program ID: ' . $programId);
                        }
                    }
                } catch (\Throwable $e) {
                    $programIdCache[$program] = null;

                    $this->log(
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
                $this->log('SKIP: Cannot extract batch starting year: ' . $batch);
                continue;
            }

            $this->log('Extracted batch prefix: ' . $batchPrefix);

            // Step 4: Find users without active enrolments.
            $usernamePrefix = $batchPrefix . $programId;

            $this->log('Searching username prefix: ' . $usernamePrefix);

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

                    $this->log(
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
                    $this->log('No matching unenrolled users found.');
                    continue;
                }

                $this->log('Matching unenrolled users: ' . count($users));

                $totalCandidates += count($users);
                $batchDeletionFailed = false;
                $batchDeleted = 0;

                // Step 5: Delete matching users, only in live mode.
                foreach ($users as $matchedUser) {
                    $this->log('Candidate username: ' . $matchedUser->username);

                    if ($dryrun) {
                        $this->log(
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
                            $this->log(
                                'ERROR: User no longer exists or is already deleted: ' .
                                $matchedUser->username
                            );

                            $batchDeletionFailed = true;
                            continue;
                        }

                        // Never delete site administrator accounts.
                        if (is_siteadmin($user)) {
                            $this->log(
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
                            $this->log(
                                'ERROR: Deletion verification failed for user ID ' .
                                $user->id
                            );

                            $batchDeletionFailed = true;
                            continue;
                        }

                        $this->log(
                            'DELETED: ' . $user->username .
                            ' (ID: ' . $user->id . ')'
                        );

                        $batchDeleted++;
                        $totalDeleted++;
                    } catch (\Throwable $e) {
                        $this->log(
                            'ERROR: User deletion failed for ' .
                            $matchedUser->username . ': ' . $e->getMessage()
                        );

                        $batchDeletionFailed = true;
                    }
                }

                // Dry runs must never update cleanup statuses.
                if ($dryrun) {
                    $this->log(
                        'DRY RUN: No users deleted and no statuses updated for this batch.'
                    );
                    continue;
                }

                // Do not mark success if any user deletion failed or was protected.
                if ($batchDeletionFailed) {
                    $totalFailedBatches++;

                    $this->log(
                        'STATUS UNCHANGED: At least one user deletion failed ' .
                        'or was skipped for safety.'
                    );

                    continue;
                }

                if ($batchDeleted === 0) {
                    $this->log(
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

                    $this->log('STATUS UPDATED: user_cleanup_status = success for program "' . $program . '" and batch "' . $batch . '".');

                    $this->log('STATUS UPDATED: ' . $cleanupMessage);

                } catch (\Throwable $e) {
                    $totalFailedBatches++;

                    $this->log(
                        'ERROR: Users were deleted, but status update failed: ' .
                        $e->getMessage()
                    );
                }
            } catch (\Throwable $e) {
                $totalFailedBatches++;

                $this->log('ERROR: User lookup failed: ' . $e->getMessage());
                continue;
            }
        }

        // Step 7: Summary.
        $this->log('========================================');
        $this->log('Execution mode: ' . ($dryrun ? 'DRY RUN' : 'LIVE'));
        $this->log('Matching users found: ' . $totalCandidates);
        $this->log('Users deleted: ' . $totalDeleted);
        $this->log('Successful batch status updates: ' . $totalSuccessfulBatches);
        $this->log('Failed batches: ' . $totalFailedBatches);

        if ($dryrun) {
            $this->log('DRY RUN COMPLETE: No database changes were intentionally made.');
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
