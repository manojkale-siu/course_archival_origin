<?php
// System permission for course migration, reports, and the related endpoints.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/coursemigrator_origin:manage' => [
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
