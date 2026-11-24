# Course Archival and Cleanup Manager Origin

`coursemigrator_origin` is a Moodle 4.0.6+ local plugin for institute LMS sites. It sends backups to the existing R-LMS receiver at `https://lmsarchive.siu.edu.in/local/coursemigrator/receive.php`.

## Install on an institute LMS

1. Upload the release ZIP under **Site administration → Plugins → Install plugins**, or copy `coursemigrator_origin` to `local/coursemigrator_origin` and run the standard Moodle upgrade.
2. Go to **Site administration → Plugins → Plugins overview** and choose **Settings** for `coursemigrator_origin`. Enter and save the **Secret token** accepted by the deployed receiver. Use the eye icon to show or hide the token. On an upgrade from a version that used a token in `backup.php`, enter it in Settings again.
3. Select **Course Archival & Cleanup Manager** from the Moodle custom menu. The common entry URL is `/local/coursemigrator_origin/index.php`.

Installation and upgrade add the menu entry to **Custom menu items**. Uninstall removes the plugin entry and preserves other menu items. Active Directors land on Director Approval and can open Migration Report in read-only mode. Director access takes precedence for accounts that also have management permissions. Other site administrators and Moodle managers land on Migration and can access its report. Other logged-in users see an access-denied message.

Installation creates missing migration and approval tables and reuses existing tables and records. For Moodle 4.0.6 compatibility, installation or upgrade renames the legacy approval table to `course_migrator_approval` when the new table is absent. The tables are retained on uninstall.

When switching from the standalone deployment, update links from `/attendance-report/coursemigrator/` and disable the old standalone cron entry. If you installed the earlier Origin plugin as `local_coursemigrator`, uninstall that Origin plugin before installing `local_coursemigrator_origin`. Moodle treats them as separate plugins.

## Scheduled user cleanup

Moodle registers **Course Migrator user cleanup** automatically. The implementation is in `classes/task/user_cleanup_cron.php`. It runs daily at 11:00 AM in the Moodle server timezone through the standard Moodle cron. Find it under **Site administration → Server → Scheduled tasks**.

Each run appends timestamped entries to `logs/user_cleanup_cron.log` beside `public_html`. On layouts without a `public_html` ancestor, the task uses `logs` inside Moodle's data directory. The Moodle cron account needs write access to the log directory and file.

The new scheduled task starts in dry-run mode. Review its output before disabling **Run user cleanup in dry-run mode** to allow account deletion.

### Running cleanup manually

Moodle 4.0.6 uses PHP `passthru()` to start its CLI runner from the browser **Run now** button. If the browser reports `Call to undefined function core\task\passthru()`, check `disable_functions` in **Site administration → Server → PHP info**. The hosting administrator controls whether that function is available to web PHP.

With server access, run the following from the Moodle directory as the same OS user that runs Moodle cron. Confirm the plugin is in dry-run mode if you only want to test it.

```sh
php admin/cli/scheduled_task.php --execute='\local_coursemigrator_origin\task\user_cleanup_cron'
```

The regular Moodle CLI cron does not use the browser launcher. Check the task's **Last run** and **Logs** in Scheduled tasks to verify execution.
