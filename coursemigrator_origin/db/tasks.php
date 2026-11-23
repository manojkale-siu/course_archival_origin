<?php
// Moodle schedules this task through its standard cron runner.

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_coursemigrator_origin\task\user_cleanup_cron',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
