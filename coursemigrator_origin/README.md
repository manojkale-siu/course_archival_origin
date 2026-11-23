# Course Archival and Cleanup Manager Origin

`coursemigrator_origin` is a Moodle 4.5+ local plugin for institute LMS sites. It sends backups to the existing R-LMS receiver at `https://lmsarchive.siu.edu.in/local/coursemigrator/receive.php`.

## Install on an institute LMS

1. Upload the release ZIP under **Site administration → Plugins → Install plugins**, or copy `coursemigrator_origin` to `local/coursemigrator_origin` and run the standard Moodle upgrade.
2. On the institute server, edit `local/coursemigrator_origin/backup.php` and replace `REPLACE_LATER` with the token accepted by the deployed receiver. Keep the real value out of Git.
3. Select **Course Archival & Cleanup Manager** from the Moodle custom menu. The common entry URL is `/local/coursemigrator_origin/index.php`.

Installation and upgrade add the menu entry to **Custom menu items** and preserve existing entries. Site administrators and Moodle managers land on the migration page and can access the migration report. Active Directors land on Director Approval. If a user qualifies for both, they land on the migration page and can open Director Approval from its toolbar. Other logged-in users see an access-denied message.

Installation creates missing migration and approval tables and reuses existing tables and records. The tables are retained on uninstall.

When switching from the standalone deployment, update links from `/attendance-report/coursemigrator/` and disable the old standalone cron entry. If you installed the earlier Origin plugin as `local_coursemigrator`, uninstall that Origin plugin before installing `local_coursemigrator_origin`. Moodle treats them as separate plugins.

## Scheduled user cleanup

Moodle registers **Course Migrator user cleanup** automatically. The implementation is in `classes/task/user_cleanup_cron.php`. It runs daily during the 02:00 hour by default through Moodle's standard cron. Change the schedule under **Site administration → Server → Scheduled tasks**.

Each run appends timestamped entries to `logs/user_cleanup_cron.log` beside `public_html`. On layouts without a `public_html` ancestor, the task uses `logs` inside Moodle's data directory. The Moodle cron account needs write access to the log directory and file.

The new scheduled task starts in dry-run mode. Review its output before disabling **Run user cleanup in dry-run mode** to allow account deletion.
