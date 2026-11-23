<?php
// Access rules shared by Origin pages and request handlers.

namespace local_coursemigrator_origin;

defined('MOODLE_INTERNAL') || die();

final class access {
    /** Check system-level permission to manage migrations and view reports. */
    public static function can_manage(): bool {
        return has_capability('local/coursemigrator_origin:manage', \context_system::instance());
    }

    /** Require management access on a page or JSON endpoint. */
    public static function require_management(bool $json = false): void {
        if (!self::can_manage()) {
            self::deny('managementaccessdenied', $json);
        }
    }

    /** Return a localized HTTP 403 response. */
    public static function deny(string $reason, bool $json = false): void {
        global $OUTPUT, $PAGE;

        http_response_code(403);
        $message = get_string($reason, 'local_coursemigrator_origin');
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        $title = get_string('accessdenied', 'local_coursemigrator_origin');
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        echo $OUTPUT->header();
        echo $OUTPUT->notification($message, 'error');
        echo $OUTPUT->footer();
        exit;
    }

    /**
     * Confirm that the current user is an authorized Director.
     *
     * Required profile fields:
     * User Category = Staff
     * Designation = Director
     * User Status = Active
     *
     * Username must start with t or n.
     * Moodle account must not be deleted or suspended.
     *
     */

    public static function is_director(): bool {
        global $DB, $USER;

        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN {user_info_data} uc
                    ON uc.userid = u.id
                  JOIN {user_info_field} fc
                    ON fc.id = uc.fieldid
                  JOIN {user_info_data} ud
                    ON ud.userid = u.id
                  JOIN {user_info_field} fd
                    ON fd.id = ud.fieldid
                  JOIN {user_info_data} us
                    ON us.userid = u.id
                  JOIN {user_info_field} fs
                    ON fs.id = us.fieldid
                 WHERE u.id = :userid
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND (
                        LOWER(u.username) LIKE :teaching
                        OR LOWER(u.username) LIKE :nonteaching
                   )
                   AND LOWER(TRIM(fc.shortname)) = :categoryfield
                   AND LOWER(TRIM(uc.data)) = :categoryvalue
                   AND LOWER(TRIM(fd.shortname)) = :designationfield
                   AND LOWER(TRIM(ud.data)) = :designationvalue
                   AND LOWER(TRIM(fs.shortname)) = :statusfield
                   AND LOWER(TRIM(us.data)) = :statusvalue";

        $params = [
            'userid' => (int)$USER->id,
            'teaching' => 't%',
            'nonteaching' => 'n%',
            'categoryfield' => 'user_category',
            'categoryvalue' => 'staff',
            'designationfield' => 'designation',
            'designationvalue' => 'director',
            'statusfield' => 'user_status',
            'statusvalue' => 'active',
        ];

        return $DB->record_exists_sql($sql, $params);
    }
}
