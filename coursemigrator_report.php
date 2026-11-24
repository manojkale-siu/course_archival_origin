<?php
// coursemigrator_report.php

/**
 * Course Archival & Cleanup Manager - Migration Report
 *
 * Responsibilities:
 * - Display course migration records and processing statuses.
 * - Filter records by course name and category hierarchy.
 * - Show source course metadata and destination course details.
 * - Paginate migration records.
 * - Export filtered records to CSV in batches.
 * - Format metadata counts, timestamps, and status badges.
 *
 * Related files: index.php, director_approval.php, get_metadata.php,
 * backup.php, cleanup.php.
 */


use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $OUTPUT, $PAGE;

// ---------------------------------------------------------
// Moodle page setup
// ---------------------------------------------------------

// $PAGE->set_url(new moodle_url('/coursemigrator/report.php'));
$PAGE->set_url(new moodle_url('/local/coursemigrator_origin/coursemigrator_report.php'));
$PAGE->set_context(context_system::instance());
access::require_view();
$canmanage = access::can_manage();
// $PAGE->set_title('Course Migrator');
// $PAGE->set_heading('Course Migration Report');
$PAGE->set_title('Course Archival & Cleanup Manager');
$PAGE->set_heading('Course Archival & Cleanup Report');

/* ---------------------------------------------------------
 * Check whether the migration table exists
 * --------------------------------------------------------- */

$tablemanager = $DB->get_manager();

if (!$tablemanager->table_exists(
    new xmldb_table('course_migrator')
)) {
    echo $OUTPUT->header();

    echo html_writer::div(
        html_writer::tag(
            'h4',
            'Migration Report Unavailable'
        ) .
        html_writer::tag(
            'p',
            'The Course Migrator database table was not found. ' .
            'Please verify that the migration component is configured correctly.'
        ),
        'alert alert-warning'
    );

    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------
// Pagination
// ---------------------------------------------------------

$perpage = 10;

$page = optional_param('page', 0, PARAM_INT);

// $totalrecords = $DB->count_records('course_migrator'); // we are using this later for filtering so that is why it is commented.

// $baseurl = new moodle_url('/coursemigrator/report.php');
$baseurl = new moodle_url('/local/coursemigrator_origin/coursemigrator_report.php');

// ---------------------------------------------------------
// Search + Filters
// ---------------------------------------------------------

$search = optional_param('search', '', PARAM_RAW_TRIMMED);
$institute = optional_param('institute', '', PARAM_RAW_TRIMMED);
$program = optional_param('program', '', PARAM_RAW_TRIMMED);
$batch = optional_param('batch', '', PARAM_RAW_TRIMMED);
$semester = optional_param('semester', '', PARAM_RAW_TRIMMED);
$specialization = optional_param('specialization', '', PARAM_RAW_TRIMMED);


$institutes = $DB->get_records_sql(
    "SELECT DISTINCT institute
       FROM {course_migrator}
      WHERE institute IS NOT NULL
        AND institute <> ''
      ORDER BY institute ASC"
);

$programs = $DB->get_records_sql(
    "SELECT DISTINCT program
       FROM {course_migrator}
      WHERE program IS NOT NULL
        AND program <> ''
      ORDER BY program ASC"
);

$batches = $DB->get_records_sql(
    "SELECT DISTINCT batch
       FROM {course_migrator}
      WHERE batch IS NOT NULL
        AND batch <> ''
      ORDER BY batch ASC"
);

$semesters = $DB->get_records_sql(
    "SELECT DISTINCT semester
       FROM {course_migrator}
      WHERE semester IS NOT NULL
        AND semester <> ''
      ORDER BY semester ASC"
);

$specializations = $DB->get_records_sql(
    "SELECT DISTINCT specialization
       FROM {course_migrator}
      WHERE specialization IS NOT NULL
        AND specialization <> ''
      ORDER BY specialization ASC"
);

// ---------------------------------------------------------
// Get migration records for current page
// ---------------------------------------------------------

$where = [];
$params = [];

if ($search !== '') {
    $where[] = $DB->sql_like('source_course_fullname', ':search', false, false);
    $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
}

if ($institute !== '') {
    $where[] = 'institute = :institute';
    $params['institute'] = $institute;
}

if ($program !== '') {
    $where[] = 'program = :program';
    $params['program'] = $program;
}

if ($batch !== '') {
    $where[] = 'batch = :batch';
    $params['batch'] = $batch;
}

if ($semester !== '') {
    $where[] = 'semester = :semester';
    $params['semester'] = $semester;
}

if ($specialization !== '') {
    $where[] = 'specialization = :specialization';
    $params['specialization'] = $specialization;
}

$where_sql = empty($where)
    ? ''
    : 'WHERE ' . implode(' AND ', $where);

/* ---------------------------------------------------------
 * Export all filtered records as Excel-compatible CSV
 * --------------------------------------------------------- */

$download = optional_param('download', 0, PARAM_BOOL);

if ($download) {
    access::require_management();
    $batchsize = 500;
    $offset = 0;

    // Prevent session locking during a potentially long export.
    \core\session\manager::write_close();

    set_time_limit(0);

    $filename = 'course_archival_report_' .
        date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // UTF-8 BOM helps Microsoft Excel recognise UTF-8 text.
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Export columns used in the report.
    $columns = [
        'id',
        'source_course_id',
        'source_course_fullname',
        'source_course_shortname',
        'course_metadata_json',
        'destination_course_id',
        'destination_course_fullname',
        'destination_course_shortname',
        'institute',
        'program',
        'batch',
        'semester',
        'specialization',
        'mbz_filename',
        'backup_status',
        'backup_message',
        'destination_status',
        'destination_message',
        'verification_status',
        'verification_message',
        'course_cleanup_status',
        'course_cleanup_message',
        'recyclebin_cleanup_status',
        'recyclebin_cleanup_message',
        'user_cleanup_status',
        'user_cleanup_message',
        'error_message',
        'created_at',
        'updated_at'
    ];

    fputcsv($output, $columns);

    // Retrieve and write records in batches.
    do {
        $sql = "SELECT *
                  FROM {course_migrator}
                  $where_sql
              ORDER BY id DESC";

        $recordset = $DB->get_recordset_sql(
            $sql,
            $params,
            $offset,
            $batchsize
        );

        $batchcount = 0;

        foreach ($recordset as $record) {
            $row = [];

            foreach ($columns as $column) {
                // $value = $record->$column ?? '';
                
                $value = $record->$column ?? '';

                if ($column === 'course_metadata_json') {
                    $metadata = json_decode((string)$value, true);
                
                    if (is_array($metadata) && json_last_error() === JSON_ERROR_NONE) {
                        $labels = [
                            'enrolled_users' => 'Enrolled Users',
                            'course_files' => 'Course Files',
                            'students_private_files' => 'Students Private Files',
                            'activities' => 'Activities',
                            'attendance_activities' => 'Attendance Activities',
                            'attendance_sessions' => 'Attendance Sessions',
                            'feedback_activities' => 'Feedback Activities',
                        ];
                
                        $readable = [];
                
                        foreach ($labels as $key => $label) {
                            $count = $metadata[$key] ?? null;
                
                            $readable[] = $label . ': ' .
                                (is_numeric($count) ? (string)(int)$count : 'N/A');
                        }
                
                        $value = implode('; ', $readable);
                    } else {
                        $value = 'Metadata not available';
                    }
                }

                // Convert Moodle timestamps to readable dates.
                if (in_array(
                    $column,
                    ['created_at', 'updated_at'],
                    true
                ) && $value !== '') {
                    $value = userdate(
                        (int)$value,
                        '%d %b %Y %H:%M'
                    );
                }

                $row[] = $value;
            }

            fputcsv($output, $row);
            $batchcount++;
        }

        $recordset->close();

        $offset += $batchcount;

        // Flush each batch instead of accumulating the full export.
        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();

    } while ($batchcount === $batchsize);

    fclose($output);
    exit;
}


$totalrecords = $DB->count_records_sql(
    "SELECT COUNT(*)
       FROM {course_migrator}
       $where_sql",
    $params
);

$records = $DB->get_records_sql(
    "SELECT *
       FROM {course_migrator}
       $where_sql
      ORDER BY id DESC",
    $params,
    $page * $perpage,
    $perpage
);

// ---------------------------------------------------------
// Status badge helper
// ---------------------------------------------------------

function migration_status_badge(?string $status): string
{
    $status = strtolower(trim((string)$status));

    switch ($status) {
        case 'success':
            $class = 'badge bg-success';
            break;

        case 'failed':
            $class = 'badge bg-danger';
            break;

        case 'pending':
            $class = 'badge bg-warning text-dark';
            break;

        case 'skipped':
            $class = 'badge bg-secondary';
            break;

        default:
            $class = 'badge bg-light text-dark';
            $status = $status ?: '-';
            break;
    }

    return html_writer::tag(
        'span',
        ucfirst($status),
        ['class' => $class]
    );
}


/**
 * Format course metadata JSON into readable statistics.
 *
 * @param string|null $metadatajson
 * @return string
 */
function format_course_metadata(?string $metadatajson): string {
    if (empty($metadatajson)) {
        return html_writer::tag(
            'span',
            'Metadata not available',
            ['class' => 'text-muted']
        );
    }

    $metadata = json_decode($metadatajson, true);

    if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
        return html_writer::tag(
            'span',
            'Metadata not available',
            ['class' => 'text-muted']
        );
    }

    $labels = [
        'enrolled_users' => 'Enrolled Users',
        'course_files' => 'Course Files',
        'students_private_files' => 'Students’ Private Files',
        'activities' => 'Activities',
        'attendance_activities' => 'Attendance Activities',
        'attendance_sessions' => 'Attendance Sessions',
        'feedback_activities' => 'Feedback Activities',
    ];

    $html = html_writer::start_tag('div', [
        'class' => 'small',
    ]);

    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $metadata) ||
                !is_numeric($metadata[$key])) {
            $value = 'N/A';
        } else {
            $value = (string)(int)$metadata[$key];
        }

        $html .= html_writer::tag(
            'div',
            html_writer::tag('strong', $label . ': ') .
                s($value),
            ['class' => 'mb-1']
        );
    }

    $html .= html_writer::end_tag('div');

    return $html;
}

// ---------------------------------------------------------
// Output
// ---------------------------------------------------------

$PAGE->requires->css( new moodle_url('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css') );

echo $OUTPUT->header();

?>

<div class="container-fluid">

    <div class="d-flex justify-content-right align-items-center mb-3">
        <!--<h3>Course Migration Report</h3>-->

        <a href="<?php echo new moodle_url('/local/coursemigrator_origin/migration.php'); ?>"
           class="btn btn-secondary">
            Back
        </a>
    </div>
    
    <!-- =====================================================
     Search + Filters
    ===================================================== -->
    
    <form method="get"
          action="<?php echo $baseurl; ?>"
          class="mb-4">
    
        <div class="card">
            <div class="card-body">
    
                <div class="row g-3">
    
                    <!-- Course Full Name -->
                    <div class="col-md-4">
                        <label for="search" class="form-label">
                            Course Full Name
                        </label>
    
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?php echo s($search); ?>"
                            class="form-control"
                            placeholder="Search course fullname..."
                        >
                    </div>
    
    
                    <!-- Institute -->
                    <div class="col-md-4">
                        <label for="institute" class="form-label">
                            Institute
                        </label>
    
                        <select
                            id="institute"
                            name="institute"
                            class="form-select">
    
                            <option value="">All Institutes</option>
    
                            <?php foreach ($institutes as $item): ?>
                                <option
                                    value="<?php echo s($item->institute); ?>"
                                    <?php echo ($institute === $item->institute) ? 'selected' : ''; ?>
                                >
                                    <?php echo s($item->institute); ?>
                                </option>
                            <?php endforeach; ?>
    
                        </select>
                    </div>
    
    
                    <!-- Program -->
                    <div class="col-md-4">
                        <label for="program" class="form-label">
                            Program
                        </label>
    
                        <select
                            id="program"
                            name="program"
                            class="form-select">
    
                            <option value="">All Programs</option>
    
                            <?php foreach ($programs as $item): ?>
                                <option
                                    value="<?php echo s($item->program); ?>"
                                    <?php echo ($program === $item->program) ? 'selected' : ''; ?>
                                >
                                    <?php echo s($item->program); ?>
                                </option>
                            <?php endforeach; ?>
    
                        </select>
                    </div>
    
    
                    <!-- Batch -->
                    <div class="col-md-4">
                        <label for="batch" class="form-label">
                            Batch
                        </label>
    
                        <select
                            id="batch"
                            name="batch"
                            class="form-select">
    
                            <option value="">All Batches</option>
    
                            <?php foreach ($batches as $item): ?>
                                <option
                                    value="<?php echo s($item->batch); ?>"
                                    <?php echo ($batch === $item->batch) ? 'selected' : ''; ?>
                                >
                                    <?php echo s($item->batch); ?>
                                </option>
                            <?php endforeach; ?>
    
                        </select>
                    </div>
    
    
                    <!-- Semester -->
                    <div class="col-md-4">
                        <label for="semester" class="form-label">
                            Semester
                        </label>
    
                        <select
                            id="semester"
                            name="semester"
                            class="form-select">
    
                            <option value="">All Semesters</option>
    
                            <?php foreach ($semesters as $item): ?>
                                <option
                                    value="<?php echo s($item->semester); ?>"
                                    <?php echo ($semester === $item->semester) ? 'selected' : ''; ?>
                                >
                                    <?php echo s($item->semester); ?>
                                </option>
                            <?php endforeach; ?>
    
                        </select>
                    </div>
    
    
                    <!-- Specialization -->
                    <div class="col-md-4">
                        <label for="specialization" class="form-label">
                            Specialization
                        </label>
    
                        <select
                            id="specialization"
                            name="specialization"
                            class="form-select">
    
                            <option value="">All Specializations</option>
    
                            <?php foreach ($specializations as $item): ?>
                                <option
                                    value="<?php echo s($item->specialization); ?>"
                                    <?php echo ($specialization === $item->specialization) ? 'selected' : ''; ?>
                                >
                                    <?php echo s($item->specialization); ?>
                                </option>
                            <?php endforeach; ?>
    
                        </select>
                    </div>
    
                </div>
    
    
                <!-- Buttons -->
                <div class="d-flex justify-content-end gap-2 mt-3">
    
                    <button
                        type="submit"
                        class="btn btn-primary">
                        Search / Filter
                    </button>
    
                    <a
                        href="<?php echo $baseurl; ?>"
                        class="btn btn-secondary">
                        Reset
                    </a>
                    
                    <?php if ($canmanage): ?>
                        <button type="submit" name="download" value="1" class="btn btn-success">
                            Download Excel (CSV)
                        </button>
                    <?php endif; ?>
    
                </div>
    
            </div>
        </div>
    
    </form>

    <?php if (empty($records)): ?>

        <div class="alert alert-info">
            No course migration records found.
        </div>

    <?php else: ?>

        <p>Total Records : <?php echo "$totalrecords"; ?></p>

        <div class="table-responsive">

            <table class="table table-bordered table-striped table-hover align-middle">

                <thead class="table-light">
                    <tr>
                        <th>SR. NO.</th>

                        <th>Source Course</th>
                        
                        <th>Source Course Statistics</th>

                        <th>Destination Course</th>

                        <th>Category Hierarchy</th>

                        <th>Backup</th>

                        <th>Destination</th>

                        <th>Verification</th>

                        <th>Course Cleanup</th>

                        <th>Recycle Bin</th>

                        <th>User Cleanup</th>

                        <th>Created</th>
                    </tr>
                </thead>

                <tbody>

                <?php 
                    
                    $srno = ($page * $perpage) + 1;
                    
                    foreach ($records as $record): 
                    
                ?>

                    <tr>

                        <!-- SR. NO -->
                        <td>
                            <?php echo $srno++; ?>
                        </td>


                        <!-- Source Course -->
                        <td>

                            <strong>
                                <?php echo s($record->source_course_fullname); ?>
                            </strong>

                            <br>

                            <small class="text-muted">
                                <?php echo s($record->source_course_shortname); ?>
                                <br>
                                ID: <?php echo (int)$record->source_course_id; ?>
                            </small>

                        </td>
                        
                        <!-- Source Course Statistics -->
                        <td>
                            <?php echo format_course_metadata($record->course_metadata_json ?? null); ?>
                        </td>

                        <!-- Destination Course -->
                        <td>

                            <?php if (!empty($record->destination_course_fullname)): ?>

                                <strong>
                                    <?php echo s($record->destination_course_fullname); ?>
                                </strong>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->destination_course_shortname); ?>
                                    <br>
                                    ID: <?php echo (int)$record->destination_course_id; ?>
                                </small>

                            <?php else: ?>

                                <span class="text-muted">-</span>

                            <?php endif; ?>

                        </td>


                        <!-- Hierarchy -->
                        <td>

                            <small>

                                <strong>Institute:</strong>
                                <?php echo s($record->institute); ?>

                                <br>

                                <strong>Program:</strong>
                                <?php echo s($record->program); ?>

                                <br>

                                <strong>Batch:</strong>
                                <?php echo s($record->batch); ?>

                                <br>

                                <strong>Semester:</strong>
                                <?php echo s($record->semester); ?>

                                <br>

                                <strong>Specialization:</strong>
                                <?php echo s($record->specialization); ?>

                            </small>

                        </td>


                        <!-- Backup -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->backup_status
                            );
                            ?>

                            <?php if (!empty($record->backup_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->backup_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Destination -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->destination_status
                            );
                            ?>

                            <?php if (!empty($record->destination_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->destination_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Verification -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->verification_status
                            );
                            ?>

                            <?php if (!empty($record->verification_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->verification_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Course Cleanup -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->course_cleanup_status
                            );
                            ?>

                            <?php if (!empty($record->course_cleanup_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->course_cleanup_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Recycle Bin -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->recyclebin_cleanup_status
                            );
                            ?>

                            <?php if (!empty($record->recyclebin_cleanup_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->recyclebin_cleanup_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- User Cleanup -->
                        <td>

                            <?php
                            echo migration_status_badge(
                                $record->user_cleanup_status
                            );
                            ?>

                            <?php if (!empty($record->user_cleanup_message)): ?>

                                <br>

                                <small class="text-muted">
                                    <?php echo s($record->user_cleanup_message); ?>
                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- Created -->
                        <td>
                            <small>
                                <?php
                                echo userdate(
                                    $record->created_at,
                                    '%d %b %Y %H:%M'
                                );
                                ?>
                            </small>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>
            
            <?php

                if ($totalrecords > $perpage) {
                
                    $pagingurl = new moodle_url($baseurl, [
                        'search' => $search,
                        'institute' => $institute,
                        'program' => $program,
                        'batch' => $batch,
                        'semester' => $semester,
                        'specialization' => $specialization,
                    ]);
                    
                    echo $OUTPUT->paging_bar(
                        $totalrecords,
                        $page,
                        $perpage,
                        $pagingurl
                    );
                }
                
            ?>

        </div>

    <?php endif; ?>

</div>

<?php

echo $OUTPUT->footer();