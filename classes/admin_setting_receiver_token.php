<?php
// Use Moodle's password visibility control while keeping the receiver token encrypted in storage.

namespace local_coursemigrator_origin;

defined('MOODLE_INTERNAL') || die();

final class admin_setting_receiver_token extends \admin_setting_configpasswordunmask {
    /** Return the token for the administrator's password field. */
    public function get_setting() {
        $stored = parent::get_setting();
        if ($stored === null || $stored === '') {
            return $stored;
        }

        try {
            return \core\encryption::decrypt($stored);
        } catch (\moodle_exception $e) {
            return '';
        }
    }

    /** Save a valid token using Moodle's encrypted storage format. */
    public function write_setting($data) {
        if (preg_match('/[\r\n]/', $data)) {
            return get_string('invalidreceivetoken', 'local_coursemigrator_origin');
        }

        $data = trim($data);
        if ($data === $this->get_setting()) {
            return '';
        }

        $stored = $data === '' ? '' : \core\encryption::encrypt($data);
        return $this->config_write($this->name, $stored) ? '' : get_string('errorsetting', 'admin');
    }
}
