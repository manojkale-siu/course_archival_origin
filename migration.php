<?php


/**
 * Course Archival & Cleanup Manager - Origin LMS
 *
 * Responsibilities:
 * - Display Programs with active Director approval.
 * - Provide Program → Batch → Semester → Specialization → Course selection.
 * - Collect metadata for selected courses before backup.
 * - Process course backups sequentially through backup.php.
 * - Display migration results and log diagnostic details.
 *
 * Related files: ajax.php, get_metadata.php, backup.php.
 */


use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursemigrator_origin/migration.php'));
access::require_view();
$canmanage = access::can_manage();
// $PAGE->set_title('Course Migrator');
$PAGE->set_title('Course Archival & Cleanup Manager');
// $PAGE->set_heading('Course Migrator');

// $programs = $DB->get_records_sql(
//     "SELECT id, name
//       FROM {course_categories}
//       WHERE parent = 0
//         AND name NOT IN ('Course Templates', 'VAC-HPM', 'Category 1')
//   ORDER BY name ASC"
// );

//reaplacing above query

$programs = $DB->get_records_sql(
    "SELECT DISTINCT cc.id, cc.name
       FROM {course_categories} cc
      WHERE cc.parent = 0
        AND cc.name NOT IN ('Course Templates', 'VAC-HPM', 'Category 1')
        AND (
            /* Program itself or a descendant category is approved */
            EXISTS (
                SELECT 1
                  FROM {course_migrator_approval} a
                  JOIN {course_categories} ac
                    ON ac.id = a.category_id
                 WHERE a.approval_status = 'approved'
                   AND (
                       ac.id = cc.id
                       OR ac.path LIKE CONCAT(cc.path, '/%')
                   )
            )

            OR

            /* An individual course under this Program is approved */
            EXISTS (
                SELECT 1
                  FROM {course_migrator_approval} a
                  JOIN {course} c
                    ON c.id = a.approved_course_id
                  JOIN {course_categories} coursecat
                    ON coursecat.id = c.category
                 WHERE a.approval_status = 'approved'
                   AND c.id <> :siteid
                   AND (
                       coursecat.id = cc.id
                       OR coursecat.path LIKE CONCAT(cc.path, '/%')
                   )
            )
        )
      ORDER BY cc.name ASC",
    ['siteid' => SITEID]
);

echo $OUTPUT->header();
?>

<style>
.approval-notice {
    overflow: hidden;
    border-radius: 8px;
    border-left: 5px solid #e0a800;
}

.approval-marquee {
    overflow: hidden;
    white-space: nowrap;
}

.approval-marquee span {
    display: inline-block;
    padding-left: 100%;
    animation: approval-scroll 18s linear infinite;
}

.approval-marquee:hover span,
.approval-notice:hover .approval-marquee span {
    animation-play-state: paused;
}

@keyframes approval-scroll {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-100%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .approval-marquee span {
        animation: none;
        padding-left: 0;
        white-space: normal;
    }
}
</style>

<h2> Course Archival & Cleanup Manager </h2>

<!-- Note added here -->
<div class="mb-3">
    <a class="btn btn-secondary" href="<?= new moodle_url('/local/coursemigrator_origin/coursemigrator_report.php') ?>">
        <?= get_string('migrationreport', 'local_coursemigrator_origin') ?>
    </a>
    <?php if (access::is_director()): ?>
        <a class="btn btn-secondary" href="<?= new moodle_url('/local/coursemigrator_origin/director_approval.php') ?>">
            <?= get_string('directorapproval', 'local_coursemigrator_origin') ?>
        </a>
    <?php endif; ?>
</div>

<div class="alert alert-warning approval-notice mb-3" role="status">
    <div class="approval-marquee">
        <span>
            <strong>Note:</strong> You can select only courses and categories pre-approved by the Director.
        </span>
    </div>
</div>

<div class="mb-3">
    <label for="program">
        Program
    </label>

    <select id="program" class="form-control">
        <option value="">-- Select Program --</option>

        <?php foreach ($programs as $program): ?>
            <option value="<?= $program->id; ?>">
                <?= s($program->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="mb-3">
    <label for="batch">
        Batch
    </label>

    <select id="batch" class="form-control" disabled>
        <option value="">-- Select Batch --</option>
    </select>
</div>

<div class="mb-3">
    <label for="semester">
        Semester
    </label>

    <select id="semester" class="form-control" disabled>
        <option value="">-- Select Semester --</option>
    </select>
</div>

<div class="mb-3">
    <label for="specialization">
        Specialization
    </label>

    <select id="specialization" class="form-control" disabled>
        <option value="">-- Select Specialization --</option>
    </select>
</div>

<div class="mb-3">
    <label for="courses">
        Courses
    </label>

    <select id="courses" class="form-control" multiple size="10" disabled>
    </select>
</div>

<div class="mt-3">
    <strong>Selected Courses:</strong>
    <div id="selected-courses">None</div>
</div>

<?php if ($canmanage): ?>
    <div class="mt-3">
        <button type="button" id="backup-course" class="btn btn-primary">
            Backup Selected Courses
        </button>
    </div>
<?php else: ?>
    <p class="mt-3"><?= get_string('migrationreadonly', 'local_coursemigrator_origin') ?></p>
<?php endif; ?>


<?php

// $PAGE->requires->js_call_amd('core/first', 'init');

$PAGE->requires->js_amd_inline(
    <<<JS
require(['jquery'], function($) {

    function resetSelect(selector, placeholder) {
        $(selector)
            .empty()
            .append($('<option>', {
                value: '',
                text: placeholder
            }))
            .prop('disabled', true);
    }

    function loadOptions(url, params, selector, placeholder, callback) {

        $.ajax({
            url: url,
            type: 'GET',
            data: params,
            dataType: 'json',

            success: function(response) {

                $(selector).empty();

                $(selector).append(
                    $('<option>', {
                        value: '',
                        text: placeholder
                    })
                );

                $.each(response, function(index, item) {

                    $(selector).append(
                        $('<option>', {
                            value: item.id,
                            text: item.name
                        })
                    );

                });

                $(selector).prop('disabled', false);

                if (callback) {
                    callback();
                }
            },

            error: function(xhr) {

                console.error('Course Migrator AJAX error:', xhr.responseText);

                $(selector)
                    .empty()
                    .append(
                        $('<option>', {
                            value: '',
                            text: 'Unable to load data'
                        })
                    )
                    .prop('disabled', true);
            }
        });
    }

    function loadCourses(categoryId) {

        $('#courses')
            .empty()
            .prop('disabled', true);

        if (!categoryId) {
            return;
        }

        $.ajax({
            url: 'ajax.php',
            type: 'GET',
            data: {
                action: 'courses',
                parentid: categoryId
            },
            dataType: 'json',

            success: function(response) {

                $('#courses').empty();

                if (!response || response.length === 0) {

                    $('#courses')
                        .append(
                            $('<option>', {
                                value: '',
                                text: 'No courses found under this category'
                            })
                        )
                        .prop('disabled', true);

                    return;
                }

                $.each(response, function(index, course) {

                    $('#courses').append(
                        $('<option>', {
                            value: course.id,
                            text: course.name
                        })
                    );

                });

                $('#courses').prop('disabled', false);
            },

            error: function(xhr) {

                console.error(
                    'Course loading error:',
                    xhr.responseText
                );

                $('#courses')
                    .empty()
                    .append(
                        $('<option>', {
                            value: '',
                            text: 'Unable to load courses'
                        })
                    )
                    .prop('disabled', true);
            }
        });
    }

    $('#program').on('change', function() {

        const programId = $(this).val();

        resetSelect('#batch', '-- Select Batch --');
        resetSelect('#semester', '-- Select Semester --');
        resetSelect('#specialization', '-- Select Specialization --');

        $('#courses').empty().prop('disabled', true);

        if (!programId) {
            return;
        }

        loadCourses(programId);

        loadOptions(
            'ajax.php',
            {
                action: 'batches',
                parentid: programId
            },
            '#batch',
            '-- Select Batch --'
        );
    });

    $('#batch').on('change', function() {

        const batchId = $(this).val();

        resetSelect('#semester', '-- Select Semester --');
        resetSelect('#specialization', '-- Select Specialization --');

        $('#courses').empty().prop('disabled', true);

        if (!batchId) {
            return;
        }

        loadCourses(batchId);

        loadOptions(
            'ajax.php',
            {
                action: 'semesters',
                parentid: batchId
            },
            '#semester',
            '-- Select Semester --'
        );
    });

    $('#semester').on('change', function() {

        const semesterId = $(this).val();

        resetSelect('#specialization', '-- Select Specialization --');

        $('#courses').empty().prop('disabled', true);

        if (!semesterId) {
            return;
        }

        loadCourses(semesterId);

        loadOptions(
            'ajax.php',
            {
                action: 'specializations',
                parentid: semesterId
            },
            '#specialization',
            '-- Select Specialization --'
        );
    });

    $('#specialization').on('change', function() {

        const specializationId = $(this).val();

        $('#courses').empty().prop('disabled', true);

        if (!specializationId) {
            return;
        }

        loadCourses(specializationId);

    });

    $('#courses').on('change', function() {

        const selectedCourses = $(this).val() || [];
        const container = $('#selected-courses');

        container.empty();

        if (selectedCourses.length === 0) {
            container.text('None');
            return;
        }

        const courseNames = [];

        $('#courses option:selected').each(function() {
            courseNames.push(
                $(this).text() + ' (ID: ' + $(this).val() + ')'
            );
        });


        courseNames.forEach(function(name) {
            $('<div>')
                .text(name)
                .appendTo(container);
        });

    });

    function showMigrationAlert(message) {

        require(['core/modal_factory'], function(ModalFactory) {

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: 'Course Migration',
                body: message
            }).then(function(modal) {

                modal.show();

            });

        });
    }

    $('#backup-course').on('click', function() {

        const selectedCourses = $('#courses').val() || [];

        if (selectedCourses.length === 0) {
            alert('Please select at least one course.');
            return;
        }

        const button = $(this);

        button.prop('disabled', true);
        button.text('Taking metadata, please wait.');

        let completed = 0;
        let failed = 0;

        function processCourse(index, migrationIds) {

            if (index >= selectedCourses.length) {

                button.prop('disabled', false);
                button.text('Backup Selected Courses');

                showMigrationAlert(
                    'Backup process completed. ' +
                    'Successful: ' + completed + '. ' +
                    'Failed: ' + failed + '.'
                );

                return;
            }

            const courseId = selectedCourses[index];

            const courseName = $('#courses option[value="' + courseId + '"]').text();

            button.text('Backing up: ' + courseName);

            console.log('Starting backup for course:', courseId);

            $.ajax({
                url: 'backup.php',
                type: 'POST',
                data: {
                    courseid: courseId,
                    migrationid: migrationIds[String(courseId)],
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',

                success: function(response) {

                    if (response.success) {

                        completed++;

                        console.log(
                            'Backup successful:',
                            response.coursename,
                            response.filename
                        );

                        // Show Course Migrator logs.
                        if (response.logs) {
                            console.log(
                                'Course Migrator Logs:',
                                response.logs
                            );
                        }

                        // Show destination transfer information.
                        if (response.transfer) {
                            console.log(
                                'Destination Transfer:',
                                response.transfer
                            );
                        }

                        if (
                            response.transfer &&
                            response.transfer.response &&
                            response.transfer.response.data &&
                            response.transfer.response.data.logs
                        ) {
                            console.log(
                                'Destination Restore Logs:',
                                response.transfer.response.data.logs
                            );
                        }

                    } else {

                        failed++;

                        console.error(
                            'Backup failed:',
                            response.message
                        );

                        if (response.logs) {
                            console.error(
                                'Course Migrator Error Logs:',
                                response.logs
                            );
                        }

                        if (response.transfer) {
                            console.error(
                                'Destination Transfer Error:',
                                response.transfer
                            );
                        }

                        if (
                            response.transfer &&
                            response.transfer.response &&
                            response.transfer.response.data &&
                            response.transfer.response.data.logs
                        ) {
                            console.error(
                                'Destination Restore Error Logs:',
                                response.transfer.response.data.logs
                            );
                        }

                    }

                    processCourse(index + 1, migrationIds);
                },

                error: function(xhr) {

                    failed++;

                    console.error(
                        'Backup request failed for course:',
                        courseId
                    );

                    console.error(
                        'HTTP Status:',
                        xhr.status
                    );

                    console.error(
                        'Raw Response:',
                        xhr.responseText
                    );

                    // Try to read the JSON error response.
                    try {

                        var errorResponse = JSON.parse(xhr.responseText);

                        console.error(
                            'Course Migrator Error:',
                            errorResponse.message
                        );

                        // Show Course Migrator logs if available.
                        if (errorResponse.logs) {
                            console.error(
                                'Course Migrator Error Logs:',
                                errorResponse.logs
                            );
                        }

                        // Show Destination error information if available.
                        if (errorResponse.transfer) {
                            console.error(
                                'Destination Transfer Error:',
                                errorResponse.transfer
                            );
                        }

                        // Show Destination restore logs if available.
                        if (
                            errorResponse.transfer &&
                            errorResponse.transfer.response &&
                            errorResponse.transfer.response.data &&
                            errorResponse.transfer.response.data.logs
                        ) {
                            console.error(
                                'Destination Restore Error Logs:',
                                errorResponse.transfer.response.data.logs
                            );
                        }

                        // Show actual Destination restore error.
                        if (
                            errorResponse.transfer &&
                            errorResponse.transfer.response &&
                            errorResponse.transfer.response.data &&
                            errorResponse.transfer.response.data.error
                        ) {
                            console.error(
                                'Destination Restore Error:',
                                errorResponse.transfer.response.data.error
                            );
                        }

                    } catch (e) {

                        console.error(
                            'Could not parse error response as JSON.'
                        );

                    }

                    processCourse(index + 1, migrationIds);
                }

            });
        }

        $.ajax({
            url: 'get_metadata.php?sesskey=' + encodeURIComponent(M.cfg.sesskey),
            type: 'POST',
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            data: JSON.stringify({
                courseids: selectedCourses,
                sesskey: M.cfg.sesskey
            }),

            success: function(response) {

                if (!response || response.success !== true) {
                    button.prop('disabled', false);
                    button.text('Backup Selected Courses');

                    showMigrationAlert('Metadata collection failed. Backup was not started.');
                    return;
                }

                console.log('Course metadata collected:', response.data);

                // Map each course ID to its migration record ID.
                const migrationIds = {};

                response.data.forEach(function(item) {
                    migrationIds[String(item.courseid)] = item.migrationid;
                });

                // Verify that every selected course has a metadata record.
                const missingCourse = selectedCourses.find(function(courseId) {
                    return !migrationIds[String(courseId)];
                });

                if (missingCourse) {
                    button.prop('disabled', false);
                    button.text('Backup Selected Courses');

                    showMigrationAlert('Metadata record missing for course ID: ' + missingCourse + '. Backup was not started.');
                    return;
                }

                button.text('Creating Backups...');
                processCourse(0, migrationIds);
            },

            error: function(xhr) {

                console.error(
                    'Metadata request failed:',
                    xhr.status,
                    xhr.responseText
                );

                button.prop('disabled', false);
                button.text('Backup Selected Courses');

                showMigrationAlert('Unable to collect course metadata. Backup was not started. ' + 'Check the browser console and Moodle error logs.');
            }
        });

    });


});
JS
);

echo $OUTPUT->footer();