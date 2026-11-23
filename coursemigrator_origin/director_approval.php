<?php
// director_approval.php

/**
 * Course Archival & Cleanup Manager - Director Approval
 *
 * Responsibilities:
 * - Restrict access to authorized Directors using Moodle profile fields.
 * - Display the course category hierarchy with lazy-loaded descendants.
 * - Approve categories and individual courses for migration.
 * - Revoke existing category and course approvals.
 * - Store approval status, approver, revocation details, and timestamps.
 * - Display current approval states and partial category selections.
 *
 * Related files: index.php, ajax.php, get_metadata.php, backup.php.
 */

use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $CFG, $USER, $OUTPUT, $PAGE;

$PAGE->set_url(new moodle_url('/local/coursemigrator_origin/director_approval.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Director Approval - Course Archival & Cleanup Manager');
$PAGE->set_heading('Director Approval - Course Archival & Cleanup Manager');

$PAGE->requires->css( new moodle_url('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css') );

/**
 * Check whether the approval table exists.
 *
 * @return bool
 */
function director_approval_table_exists(): bool {
    global $DB;

    return $DB->get_manager()->table_exists(new xmldb_table('course_migrator_category_approval'));
}

/**
 * Find the Program ancestor for a category.
 *
 * Assumes the hierarchy is:
 * Institute -> Program -> Batch -> Semester -> Specialization.
 *
 * If a category has no Institute parent, the top-level category
 * is used as the program/category branch identifier.
 *
 * @param int $categoryid
 * @return int
 */
function director_approval_get_program_id(int $categoryid): int {
    global $DB;

    $current = $DB->get_record(
        'course_categories',
        ['id' => $categoryid],
        'id, parent',
        IGNORE_MISSING
    );

    if (!$current) {
        return 0;
    }

    $visited = [];
    $programid = (int)$current->id;

    while ((int)$current->parent > 0) {
        if (isset($visited[$current->id])) {
            break;
        }

        $visited[$current->id] = true;

        $parent = $DB->get_record(
            'course_categories',
            ['id' => $current->parent],
            'id, parent',
            IGNORE_MISSING
        );

        if (!$parent) {
            break;
        }

        // The current category is directly below the root category.
        if ((int)$parent->parent === 0) {
            $programid = (int)$current->id;
            break;
        }

        $current = $parent;
        $programid = (int)$current->id;
    }

    return $programid;
}



/**
 * Check whether a category contains any approved descendant
 * category or individually approved course.
 */
function director_approval_has_approved_descendant(
    int $categoryid,
    array $categories,
    array $coursesbycategory,
    array $approvedcategories,
    array $approvedcourses
): bool {

    // Check individually approved courses directly in this category.
    foreach ($coursesbycategory[$categoryid] ?? [] as $course) {
        if (isset($approvedcourses[(int) $course->id])) {
            return true;
        }
    }

    // Check child categories recursively.
    foreach ($categories as $child) {
        if ((int) $child->parent !== $categoryid) {
            continue;
        }

        if (isset($approvedcategories[(int) $child->id])) {
            return true;
        }

        if (director_approval_has_approved_descendant(
            (int) $child->id,
            $categories,
            $coursesbycategory,
            $approvedcategories,
            $approvedcourses
        )) {
            return true;
        }
    }

    return false;
}

/**
 * Render one category level.
 * Descendants are loaded only when the arrow is clicked.
 */
function director_approval_render_tree(
    int $parentid,
    array $categories,
    array $coursesbycategory,
    array $approvedcategories,
    array $approvedcourses
): string {
    $html = '';

    foreach ($categories as $category) {
        if ((int)$category->parent !== $parentid) {
            continue;
        }

        $id = (int)$category->id;
        $haschildren = false;

        foreach ($categories as $child) {
            if ((int)$child->parent === $id) {
                $haschildren = true;
                break;
            }
        }

        $hascourses = !empty($coursesbycategory[$id]);
        $expandable = $haschildren || $hascourses;

        $html .= '<div class="category-node mb-2">';
        $html .= '<div class="d-flex align-items-center gap-2 p-2 border rounded">';

        if ($expandable) {
            $html .= '<button type="button" class="btn btn-sm btn-light tree-toggle"';
            $html .= ' data-categoryid="' . $id . '"';
            $html .= ' aria-expanded="false" title="Expand category">';
            $html .= '<span class="tree-arrow">&#9654;</span>';
            $html .= '</button>';
        } else {
            $html .= '<span style="width:32px;display:inline-block"></span>';
        }

        // $checked = isset($approvedcategories[$id]) ? ' checked' : '';
        
        
        $directapproved = isset($approvedcategories[$id]);
        
        $partial = !$directapproved &&
            director_approval_has_approved_descendant(
                $id,
                $categories,
                $coursesbycategory,
                $approvedcategories,
                $approvedcourses
            );
        
        $checked = $directapproved ? ' checked' : '';
        $partialattribute = $partial ? ' data-partial="1"' : '';

        

        $html .= '<input class="form-check-input category-checkbox"';
        $html .= ' type="checkbox" name="categoryids[]"';
        $html .= ' value="' . $id . '"';
        // $html .= ' id="category-' . $id . '"' . $checked . '>';
        $html .= ' id="category-' . $id . '"' . $checked;
        $html .= $partialattribute . '>';

        $html .= '<label class="form-check-label fw-semibold flex-grow-1"';
        $html .= ' for="category-' . $id . '">';
        $html .= s($category->name);
        $html .= '</label>';

        $html .= '<small class="text-muted">ID: ' . $id . '</small>';
        $html .= '</div>';

        if ($expandable) {
            $html .= '<div class="category-children ms-4 mt-2"';
            $html .= ' id="children-' . $id . '"';
            $html .= ' data-loaded="0" style="display:none">';

            $html .= '<div class="text-muted small loading-message"';
            $html .= ' style="display:none">';
            $html .= '<span class="spinner-border spinner-border-sm me-2"';
            $html .= ' role="status"></span>Loading categories and courses...';
            $html .= '</div>';

            $html .= '<div class="children-content"></div>';
            $html .= '</div>';
        }

        $html .= '</div>';
    }

    return $html;
}

/**
 * Render the contents of one expanded category.
 */
function director_approval_render_children(
    int $parentid,
    array $categories,
    array $coursesbycategory,
    array $approvedcategories,
    array $approvedcourses
): string {
    $html = director_approval_render_tree(
        $parentid,
        $categories,
        $coursesbycategory,
        $approvedcategories,
        $approvedcourses
    );

    if (!empty($coursesbycategory[$parentid])) {
        $html .= '<div class="direct-courses border-start ms-4 ps-3 mb-3">';

        foreach ($coursesbycategory[$parentid] as $course) {
            $id = (int)$course->id;
            $checked = isset($approvedcourses[$id]) ? ' checked' : '';

            $html .= '<div class="form-check mb-2">';
            $html .= '<input class="form-check-input course-checkbox"';
            $html .= ' type="checkbox" name="courseids[]"';
            $html .= ' value="' . $id . '"';
            $html .= ' id="course-' . $id . '"' . $checked . '>';

            $html .= '<label class="form-check-label" for="course-' . $id . '">';
            $html .= s($course->fullname);
            $html .= '</label>';

            $html .= '<small class="text-muted ms-2">ID: ' . $id . '</small>';
            $html .= '</div>';
        }

        $html .= '</div>';
    }

    return $html;
}

// ---------------------------------------------------------
// 1. Validate Director access.
// ---------------------------------------------------------

if (!access::is_director()) {
    access::deny('directoraccessdenied');
}

// ---------------------------------------------------------
// 2. Check the approval table.
// ---------------------------------------------------------

if (!director_approval_table_exists()) {
    echo $OUTPUT->header();

    echo html_writer::div(
        'The category approval table was not found. ' .
        'Please verify the database table name and configuration.',
        'alert alert-warning'
    );

    echo $OUTPUT->footer();
    exit;
}


// ---------------------------------------------------------
// AJAX: Load a category's children on demand.
// ---------------------------------------------------------

if (optional_param('ajax', '', PARAM_ALPHA) === 'children') {

    header('Content-Type: text/html; charset=utf-8');
    
    if (!isloggedin() || isguestuser()) {
        http_response_code(401);
        echo 'Your session has expired. Please reload the page and log in again.';
        exit;
    }
    
    require_sesskey();

    $parentid = required_param('parentid', PARAM_INT);

    $parent = $DB->get_record(
        'course_categories',
        ['id' => $parentid],
        'id',
        MUST_EXIST
    );

    $categories = $DB->get_records(
        'course_categories',
        [],
        'name ASC',
        'id, name, parent, depth'
    );

    $courses = $DB->get_records_sql(
        "SELECT id, fullname, shortname, category
           FROM {course}
          WHERE id <> :siteid
            AND category > 0
          ORDER BY fullname ASC",
        ['siteid' => SITEID]
    );

    $coursesbycategory = [];

    foreach ($courses as $course) {
        $categoryid = (int)$course->category;
        $coursesbycategory[$categoryid][] = $course;
    }

    $approvedcategories = [];
    $approvedcourses = [];

    $approvalrecords = $DB->get_records(
        'course_migrator_category_approval',
        ['approval_status' => 'approved'],
        '',
        'id, category_id, approved_course_id'
    );

    foreach ($approvalrecords as $approval) {
        if (!empty($approval->category_id)) {
            $approvedcategories[(int)$approval->category_id] = true;
        }

        if (!empty($approval->approved_course_id)) {
            $approvedcourses[(int)$approval->approved_course_id] = true;
        }
    }

    header('Content-Type: text/html; charset=utf-8');

    echo director_approval_render_children(
        $parentid,
        $categories,
        $coursesbycategory,
        $approvedcategories,
        $approvedcourses
    );

    exit;
}


$successmessage = '';
$errormessage = '';

/**
 * Process revocation of selected category/course approvals.
 * This version does not check migration status.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('revoke', 0, PARAM_BOOL)) {

    require_sesskey();

    $categoryids = array_unique(array_map(
        'intval',
        optional_param_array('categoryids', [], PARAM_INT)
    ));

    $courseids = array_unique(array_map(
        'intval',
        optional_param_array('courseids', [], PARAM_INT)
    ));

    if (empty($categoryids) && empty($courseids)) {
        $errormessage = 'Select at least one category or course to revoke.';
    } else {
        $now = time();
        $revokedcount = 0;
        $transaction = $DB->start_delegated_transaction();

        try {
            // Revoke selected category approvals.
            foreach ($categoryids as $categoryid) {
                $approval = $DB->get_record(
                    'course_migrator_category_approval',
                    ['category_id' => $categoryid],
                    '*',
                    IGNORE_MISSING
                );

                if ($approval && $approval->approval_status === 'approved') {
                    $approval->approval_status = 'revoked';
                    $approval->revoked_by = (int) $USER->id;
                    $approval->revoked_at = $now;
                    $approval->comments = 'Approval revoked by Director.';
                    $approval->updated_at = $now;

                    $DB->update_record(
                        'course_migrator_category_approval',
                        $approval
                    );

                    $revokedcount++;
                }
            }

            // Revoke selected individual course approvals.
            foreach ($courseids as $courseid) {
                $approval = $DB->get_record(
                    'course_migrator_category_approval',
                    ['approved_course_id' => $courseid],
                    '*',
                    IGNORE_MISSING
                );

                if ($approval && $approval->approval_status === 'approved') {
                    $approval->approval_status = 'revoked';
                    $approval->revoked_by = (int) $USER->id;
                    $approval->revoked_at = $now;
                    $approval->comments = 'Course approval revoked by Director.';
                    $approval->updated_at = $now;

                    $DB->update_record(
                        'course_migrator_category_approval',
                        $approval
                    );

                    $revokedcount++;
                }
            }

            $transaction->allow_commit();

            $successmessage = $revokedcount . ' approval(s) revoked successfully.';

        } catch (Throwable $e) {
            
            error_log(
                '[Course Migrator] Approval revocation failed. ' .
                'Director ID: ' . (int)$USER->id .
                '. Error: ' . $e->getMessage()
            );
            
            $transaction->rollback($e);
            
            $errormessage = 'Unable to revoke the selected approvals due to a technical error. Please try again.';
        }
    }
}

// ---------------------------------------------------------
// 3. Process approval submission.
// ---------------------------------------------------------

else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $categoryids = optional_param_array(
        'categoryids',
        [],
        PARAM_INT
    );

    $courseids = optional_param_array(
        'courseids',
        [],
        PARAM_INT
    );

    $categoryids = array_values(
        array_unique(array_filter(array_map('intval', $categoryids)))
    );

    $courseids = array_values(
        array_unique(array_filter(array_map('intval', $courseids)))
    );

    if (empty($categoryids) && empty($courseids)) {
        $errormessage = 'Please select at least one category or course.';
    } else {
        $now = time();
        $transaction = $DB->start_delegated_transaction();

        try {
            // Save selected category approvals.
            foreach ($categoryids as $categoryid) {
                $category = $DB->get_record(
                    'course_categories',
                    ['id' => $categoryid],
                    'id, name, parent',
                    MUST_EXIST
                );

                $programid = director_approval_get_program_id(
                    (int)$category->id
                );

                $existing = $DB->get_record(
                    'course_migrator_category_approval',
                    ['category_id' => $categoryid],
                    '*',
                    IGNORE_MISSING
                );

                if ($existing) {
                    $existing->program_category_id = $programid;
                    $existing->approval_status = 'approved';
                    $existing->approved_by = (int)$USER->id;
                    $existing->approved_at = $now;
                    $existing->revoked_by = null;
                    $existing->revoked_at = null;
                    $existing->comments = 'Approved by Director.';
                    $existing->updated_at = $now;

                    $DB->update_record(
                        'course_migrator_category_approval',
                        $existing
                    );
                } else {
                    $record = (object)[
                        'category_id' => $categoryid,
                        'approved_course_id' => null,
                        'program_category_id' => $programid,
                        'approval_status' => 'approved',
                        'approved_by' => (int)$USER->id,
                        'approved_at' => $now,
                        'revoked_by' => null,
                        'revoked_at' => null,
                        'comments' => 'Approved by Director.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $DB->insert_record(
                        'course_migrator_category_approval',
                        $record
                    );
                }
            }

            // Save individual course approvals.
            foreach ($courseids as $courseid) {
                $course = $DB->get_record(
                    'course',
                    ['id' => $courseid],
                    'id, fullname, category',
                    MUST_EXIST
                );

                if ((int)$course->id === SITEID ||
                        (int)$course->category <= 0) {
                    throw new moodle_exception(
                        'Invalid course selected: ' . $courseid
                    );
                }

                $category = $DB->get_record(
                    'course_categories',
                    ['id' => $course->category],
                    'id',
                    MUST_EXIST
                );

                $programid = director_approval_get_program_id(
                    (int)$category->id
                );

                $existing = $DB->get_record(
                    'course_migrator_category_approval',
                    ['approved_course_id' => $courseid],
                    '*',
                    IGNORE_MISSING
                );

                if ($existing) {
                    $existing->program_category_id = $programid;
                    $existing->approval_status = 'approved';
                    $existing->approved_by = (int)$USER->id;
                    $existing->approved_at = $now;
                    $existing->revoked_by = null;
                    $existing->revoked_at = null;
                    $existing->comments = 'Course approved by Director.';
                    $existing->updated_at = $now;

                    $DB->update_record(
                        'course_migrator_category_approval',
                        $existing
                    );
                } else {
                    $record = (object)[
                        'category_id' => null,
                        'approved_course_id' => $courseid,
                        'program_category_id' => $programid,
                        'approval_status' => 'approved',
                        'approved_by' => (int)$USER->id,
                        'approved_at' => $now,
                        'revoked_by' => null,
                        'revoked_at' => null,
                        'comments' => 'Course approved by Director.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $DB->insert_record(
                        'course_migrator_category_approval',
                        $record
                    );
                }
            }

            $transaction->allow_commit();

            $successmessage = 'Approval saved successfully. ' .
                'The selected categories and courses have been approved.';

        } catch (Throwable $e) {
            
            error_log(
                '[Course Migrator] Approval save failed. ' .
                'Director ID: ' . (int)$USER->id .
                '. Error: ' . $e->getMessage()
            );
            
            $transaction->rollback($e);
            
            $errormessage = 'Unable to save the selected approvals due to a technical error. Please try again.';
            
        }
    }
}


$approvedcategories = [];
$approvedcourses = [];

$approvalrecords = $DB->get_records(
    'course_migrator_category_approval',
    ['approval_status' => 'approved'],
    '',
    'id, category_id, approved_course_id'
);

foreach ($approvalrecords as $approval) {
    if (!empty($approval->category_id)) {
        $approvedcategories[(int)$approval->category_id] = true;
    }

    if (!empty($approval->approved_course_id)) {
        $approvedcourses[(int)$approval->approved_course_id] = true;
    }
}


// ---------------------------------------------------------
// 4. Load categories and courses.
// ---------------------------------------------------------

$categories = $DB->get_records(
    'course_categories',
    [],
    'name ASC',
    'id, name, parent, depth'
);

$courses = $DB->get_records_sql(
    "SELECT id, fullname, shortname, category
       FROM {course}
      WHERE id <> :siteid
        AND category > 0
      ORDER BY fullname ASC",
    ['siteid' => SITEID]
);

$coursesbycategory = [];

foreach ($courses as $course) {
    $categoryid = (int)$course->category;

    if (!isset($coursesbycategory[$categoryid])) {
        $coursesbycategory[$categoryid] = [];
    }

    $coursesbycategory[$categoryid][] = $course;
}

// ---------------------------------------------------------
// 5. Display the Director page.
// ---------------------------------------------------------

echo $OUTPUT->header();

?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between
                align-items-center mb-4 gap-2">

        <div>
            <h2 class="mb-1">Director Approval</h2>
            <p class="text-muted mb-0">
                Approve Programs, Batches, Semesters, Specializations
                or individual courses for Course Migrator.
            </p>
        </div>

        <?php if (access::can_manage()): ?>
            <a href="<?php echo new moodle_url('/local/coursemigrator_origin/coursemigrator_report.php'); ?>"
               class="btn btn-secondary">Back to Report</a>
        <?php endif; ?>

    </div>

    <?php if ($successmessage !== ''): ?>
        <div class="alert alert-success">
            <?php echo s($successmessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($errormessage !== ''): ?>
        <div class="alert alert-warning">
            <?php echo s($errormessage); ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong>Important:</strong>
        Selecting a category approves that category branch, including
        its descendants and future descendants. Selecting an individual
        course approves only that course.
    </div>

    <form method="post"
          action="<?php echo $PAGE->url->out(false); ?>"
          class="card">

        <div class="card-header">
            <h5 class="mb-0">Select Categories and Courses</h5>
        </div>

        <div class="card-body">

            <?php if (empty($categories)): ?>

                <div class="alert alert-warning mb-0">
                    No course categories were found.
                </div>

            <?php else: ?>

                <div class="d-flex flex-wrap gap-2 mb-3">

                    <button type="button"
                            class="btn btn-outline-primary btn-sm"
                            id="select-all">
                        Select All Categories and Courses
                    </button>

                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            id="clear-all">
                        Clear Selection
                    </button>

                </div>

                <div class="category-tree">

                    <?php
                        echo director_approval_render_tree(
                            0,
                            $categories,
                            $coursesbycategory,
                            $approvedcategories,
                            $approvedcourses
                        );
                    ?>

                </div>

            <?php endif; ?>

        </div>

        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                
            <span class="text-muted small">
                Review your selection before approving.
            </span>
            
            
            <button type="submit"
                    name="revoke"
                    value="1"
                    class="btn btn-outline-danger"
                    onclick="return confirm('Revoke approval for the selected categories and courses?');">
                <i class="fa fa-ban me-1"></i>
                Revoke Selected Approvals
            </button>
                
            <button type="submit"
                    class="btn btn-success"
                    <?php echo empty($categories) ? 'disabled' : ''; ?>>
                <i class="fa fa-check-circle me-1"></i>
                Approve for Backup
            </button>
            
        </div>
        
        <input type="hidden"
               name="sesskey"
               value="<?php echo s(sesskey()); ?>">

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tree = document.querySelector('.category-tree');
    
    if (!tree) {
        return;
    }
    
    function updatePartialCheckboxes(container) {
        container.querySelectorAll(
            '.category-checkbox[data-partial="1"]'
        ).forEach(function (checkbox) {
            checkbox.indeterminate = true;
        });
    }
    
    updatePartialCheckboxes(tree);

    const endpoint = <?php
        echo json_encode(
            (new moodle_url(
                '/local/coursemigrator_origin/director_approval.php'
            ))->out(false)
        );
    ?>;

    const sesskey = <?php echo json_encode(sesskey()); ?>;

    tree.addEventListener('click', async function (event) {
        const button = event.target.closest('.tree-toggle');

        if (!button) {
            return;
        }

        const categoryid = button.dataset.categoryid;
        const container = document.getElementById(
            'children-' + categoryid
        );

        if (!container) {
            return;
        }

        const arrow = button.querySelector('.tree-arrow');
        const isOpen = container.style.display !== 'none';

        if (isOpen) {
            container.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            arrow.innerHTML = '&#9654;';
            return;
        }

        container.style.display = 'block';
        button.setAttribute('aria-expanded', 'true');
        arrow.innerHTML = '&#9660;';

        if (container.dataset.loaded === '1') {
            return;
        }

        const loader = container.querySelector('.loading-message');
        const content = container.querySelector('.children-content');

        loader.style.display = 'block';
        button.disabled = true;

        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('ajax', 'children');
            url.searchParams.set('parentid', categoryid);
            url.searchParams.set('sesskey', sesskey);

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            // if (!response.ok) {
            //     throw new Error('Unable to load category contents.');
            // }
            
            const responseText = await response.text();
            
            if (
                response.redirected ||
                response.url.includes('/login/') ||
                responseText.includes('Your session has timed out') ||
                responseText.includes('Your session has expired')
            ) {
                content.textContent =
                    'Your Moodle session has expired. Reload this page and log in again.';
                return;
            }
            
            if (!response.ok) {
                throw new Error('Unable to load category contents. HTTP ' + response.status);
            }
            
            // Detect an HTML login page instead of inserting it into the tree.
            if (
                responseText.includes('loginform') ||
                responseText.includes('id="login"')
            ) {
                content.textContent =
                    'Moodle returned the login page. Reload this page and log in again.';
                return;
            }
            

            // content.innerHTML = await response.text();
            content.innerHTML = responseText;
            updatePartialCheckboxes(content);
            container.dataset.loaded = '1';

        } catch (error) {
            content.textContent = error.message +
                ' Please collapse and reopen this category to retry.';
        } finally {
            loader.style.display = 'none';
            button.disabled = false;
        }
    });

    document.getElementById('select-all')?.addEventListener(
        'click',
        function () {
            tree.querySelectorAll(
                '.category-checkbox, .course-checkbox'
            ).forEach(function (checkbox) {
                checkbox.checked = true;
            });
        }
    );

    document.getElementById('clear-all')?.addEventListener(
        'click',
        function () {
            tree.querySelectorAll(
                '.category-checkbox, .course-checkbox'
            ).forEach(function (checkbox) {
                checkbox.checked = false;
            });
        }
    );
});
</script>

<?php

echo $OUTPUT->footer();