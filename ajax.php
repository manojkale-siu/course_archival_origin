<?php

/**
 * Course Archival & Cleanup Manager - AJAX Handler
 *
 * Responsibilities:
 * - Load approved Batch, Semester, and Specialization categories.
 * - Inherit category approval from approved ancestor categories.
 * - Include categories containing individually approved courses.
 * - Return approved courses for the selected category.
 * - Respond with JSON and appropriate HTTP status codes.
 *
 * Related files: index.php, backup.php, director_approval.php.
 */

use local_coursemigrator_origin\access;

require_once(__DIR__ . '/../../config.php');

require_login();
access::require_management(true);

global $DB;

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHA);
$parentid = optional_param('parentid', 0, PARAM_INT);

/**
 * Check whether a category is approved directly or inherits approval
 * from any of its ancestor categories.
 *
 * This supports future descendant categories automatically.
 */
function coursemigrator_category_is_approved(int $categoryid): bool {
    global $DB;

    $category = $DB->get_record(
        'course_categories',
        ['id' => $categoryid],
        'id, parent',
        IGNORE_MISSING
    );

    while ($category) {
        $approved = $DB->record_exists(
            'course_migrator_approval',
            [
                'category_id' => $category->id,
                'approval_status' => 'approved',
            ]
        );

        if ($approved) {
            return true;
        }

        if ((int) $category->parent === 0) {
            break;
        }

        $category = $DB->get_record(
            'course_categories',
            ['id' => $category->parent],
            'id, parent',
            IGNORE_MISSING
        );
    }

    return false;
}



/**
 * Check whether a Program should be visible in the Program dropdown.
 *
 * A Program is visible if:
 * 1. The Program itself is approved.
 * 2. Any category under the Program is approved.
 * 3. Any course under the Program is individually approved.
 */
function coursemigrator_program_has_approval(int $programid): bool {
    global $DB;

    // Check whether the Program or any descendant category is approved.
    $program = $DB->get_record(
        'course_categories',
        ['id' => $programid],
        'id, path',
        IGNORE_MISSING
    );

    if (!$program) {
        return false;
    }

    $categoryapproval = $DB->record_exists_sql(
        "SELECT 1
           FROM {course_migrator_approval} a
           JOIN {course_categories} cc
             ON cc.id = a.category_id
          WHERE a.approval_status = :status
            AND (cc.id = :programid OR cc.path LIKE :categorypath)",
        [
            'status' => 'approved',
            'programid' => $programid,
            'categorypath' => $program->path . '/%',
        ]
    );

    if ($categoryapproval) {
        return true;
    }

    // Check whether any individually approved course belongs to this Program
    // or one of its descendant categories.
    $courseapproval = $DB->record_exists_sql(
        "SELECT 1
           FROM {course_migrator_approval} a
           JOIN {course} c
             ON c.id = a.approved_course_id
           JOIN {course_categories} cc
             ON cc.id = c.category
          WHERE a.approval_status = :status
            AND c.id <> :siteid
            AND (cc.id = :programid OR cc.path LIKE :categorypath)",
        [
            'status' => 'approved',
            'siteid' => SITEID,
            'programid' => $programid,
            'categorypath' => $program->path . '/%',
        ]
    );

    return $courseapproval;
}



/**
 * Return child categories that are approved, inherit approval,
 * or contain individually approved courses.
 */
function coursemigrator_get_approved_child_categories(int $parentid): array {
    global $DB;

    $categories = $DB->get_records(
        'course_categories',
        ['parent' => $parentid],
        'name ASC',
        'id, name'
    );

    $result = [];

    foreach ($categories as $category) {
        $categoryid = (int) $category->id;

        // The category itself or an ancestor is approved.
        $visible = coursemigrator_category_is_approved($categoryid);

        if (!$visible) {
            // Check for an individually approved course in this category
            // or any descendant category.
            $categoryrecord = $DB->get_record(
                'course_categories',
                ['id' => $categoryid],
                'id, path',
                MUST_EXIST
            );

            $visible = $DB->record_exists_sql(
                "SELECT 1
                   FROM {course_migrator_approval} a
                   JOIN {course} c
                     ON c.id = a.approved_course_id
                   JOIN {course_categories} cc
                     ON cc.id = c.category
                  WHERE a.approval_status = :status
                    AND c.id <> :siteid
                    AND (cc.id = :categoryid OR cc.path LIKE :categorypath)",
                [
                    'status' => 'approved',
                    'siteid' => SITEID,
                    'categoryid' => $categoryid,
                    'categorypath' => $categoryrecord->path . '/%',
                ]
            );
        }

        if ($visible) {
            $result[] = [
                'id' => $categoryid,
                'name' => $category->name,
            ];
        }
    }

    return $result;
}



/**
 * Return courses that are approved individually or belong to an
 * approved category branch.
 */
function coursemigrator_get_approved_courses(int $categoryid): array {
    global $DB;

    $courses = $DB->get_records(
        'course',
        ['category' => $categoryid],
        'fullname ASC',
        'id, fullname'
    );

    $result = [];
    
    $categoryapproved = coursemigrator_category_is_approved( $categoryid );

    foreach ($courses as $course) {
        // Exclude the Moodle site course.
        if ((int) $course->id === SITEID) {
            continue;
        }

        $individuallyapproved = $DB->record_exists(
            'course_migrator_approval',
            [
                'approved_course_id' => $course->id,
                'approval_status' => 'approved',
            ]
        );

        // $categoryapproved = coursemigrator_category_is_approved( $categoryid );

        if ($individuallyapproved || $categoryapproved) {
            $result[] = [
                'id' => (int) $course->id,
                'name' => $course->fullname,
            ];
        }
    }

    return $result;
}


try {
    switch ($action) {

        case 'batches':
        case 'semesters':
        case 'specializations':

            $result = coursemigrator_get_approved_child_categories(
                $parentid
            );

            break;

        case 'courses':

            $result = coursemigrator_get_approved_courses(
                $parentid
            );

            break;

        default:

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
            ]);

            exit;
    }

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Unable to load approved categories or courses.',
    ]);
}