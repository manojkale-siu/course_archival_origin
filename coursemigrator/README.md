# Course Archival and Cleanup Manager Origin

This Moodle 4.5+ local plugin runs on institute LMS sites. It sends backups to the existing receiver at `https://lmsarchive.siu.edu.in/local/coursemigrator/receive.php`.

## Install on an institute LMS

1. Upload the release ZIP under **Site administration → Plugins → Install plugins**, or copy the `coursemigrator` directory to `local/coursemigrator` and run the standard Moodle upgrade.
2. Set the shared token in the site's Moodle `config.php` before it loads `lib/setup.php`. Use the value accepted by the deployed archive receiver.

   ```php
   $CFG->local_coursemigrator_receive_token = 'REPLACE_WITH_THE_DEPLOYED_RECEIVER_TOKEN';
   ```

3. Open `/local/coursemigrator/index.php` for course selection, `/local/coursemigrator/director_approval.php` for approvals, and `/local/coursemigrator/coursemigrator_report.php` for reporting.

Installation creates the migration and approval tables if they are missing and reuses the tables and data from the standalone Origin deployment. These tables are retained on plugin uninstall so existing migration records and approvals remain available.

## Scheduled user cleanup

Moodle registers **Course Migrator user cleanup** automatically. It runs daily during the 02:00 hour by default through the site's standard Moodle cron. The schedule is editable in **Site administration → Server → Scheduled tasks**.

The task starts in dry-run mode. Review its logs before disabling **Run user cleanup in dry-run mode** in the plugin settings to allow account deletion.

When switching over from the standalone deployment, update links from `/attendance-report/coursemigrator/` and disable the old `user_cleanup_cron.php` cron entry. Keep the site's standard Moodle cron running.
