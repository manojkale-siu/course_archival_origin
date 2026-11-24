<?php
// English strings for Course Migrator Origin.

$string['pluginname'] = 'coursemigrator_origin';
$string['missingreceivetoken'] = 'Ask a site administrator to save a valid secret token in the Course Migrator Origin settings before starting a backup.';
$string['taskusercleanup'] = 'Course Migrator user cleanup';
$string['usercleanupdryrun'] = 'Run user cleanup in dry-run mode';
$string['usercleanupdryrun_desc'] = 'When enabled, the scheduled task reports eligible accounts without deleting them or changing cleanup statuses. Disable this setting to allow user deletion.';

$string['accessdenied'] = 'Access denied';
$string['coursemigrator_origin:manage'] = 'Manage course archival and view migration reports';
$string['directoraccessdenied'] = 'Only active Directors can access the Director Approval page.';
$string['entryaccessdenied'] = 'Course Archival is available to site administrators, Moodle managers, and active Directors.';
$string['managementaccessdenied'] = 'This page is for migration operators. Directors can access Director Approval and Migration Report.';
$string['migrationreport'] = 'Migration Report';

$string['receivetoken'] = 'Secret token';
$string['receivetoken_desc'] = 'Enter the shared token accepted by the R-LMS archive receiver. Use the eye icon to show or hide the token.';
$string['setupnoteheading'] = 'Setup note';
$string['setupnote'] = 'Save the R-LMS receiver secret token before sending course backups. If a token was previously set in backup.php, enter it here after upgrading.';
$string['overviewnote'] = 'Set the R-LMS receiver secret token in Settings before sending backups.';
$string['invalidreceivetoken'] = 'Enter the receiver token on a single line.';

$string['cronrunnowwarning'] = 'Browser task runner unavailable';
$string['cronrunnowwarning_desc'] = 'Moodle uses the PHP passthru function to launch scheduled tasks from the Run now button. The web PHP runtime does not provide this function. A server administrator can run the task with Moodle CLI or review the web PHP configuration.';

$string['processhelp'] = 'If you want to know more about this process or question first read this docs : <a href="https://drive.google.com/drive/folders/1X9lhv81lGm2sHeHkozCSNr0RYpl8B5DC?usp=sharing">Link (click here)</a>';
