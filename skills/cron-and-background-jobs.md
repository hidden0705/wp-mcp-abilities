---
title: Cron & Background Jobs
category: Operations
summary: Keep WP-Cron, Action Scheduler and queue workers healthy — diagnose missed schedules, duplicated jobs, and stuck WooCommerce background processes.
updated: 2026-10-26
---

# Cron & Background Jobs

WordPress has two schedulers: **WP-Cron** (core, request-triggered) and **Action Scheduler** (WooCommerce and many plugins, DB-backed, more reliable).

## Which is which

| Scheduler | Stored in | Used by |
| --- | --- | --- |
| WP-Cron | `cron` option | core updates, publishing, transients |
| Action Scheduler | `actionscheduler_actions` | WooCommerce, subscriptions, imports, OttoKit |
| Real server cron | crontab/systemd | `wp cron event run --due-now` |

## Inspect WP-Cron

```
wp cron event list --format=json
wp cron event list --fields=hook,next_run_gmt,recurrence --format=json
wp cron test
```

`wp cron test` reports whether WP-Cron can spawn a loopback request. A failure means scheduled tasks only run when traffic hits the site (or never, on a low-traffic store).

## Run due events now

```
wp cron event run --due-now
wp cron event run wp_version_check
```

Do not run `--due-now` repeatedly on a busy site; some hooks are expensive.

## Action Scheduler

```
wp action-scheduler list --status=pending --per_page=20
wp action-scheduler list --status=failed --per_page=20
wp action-scheduler run --hooks=wc-admin_import_orders
wp action-scheduler action run <action_id>
wp action-scheduler action delete <action_id>
```

Statuses: `pending`, `in-progress`, `complete`, `failed`, `canceled`.

WooCommerce → Status → Scheduled Actions shows the same data with a UI, including the error message for failed actions.

## Why jobs get stuck

- **No traffic**: WP-Cron never triggers. Fix with a real server cron.
- **Loopback blocked**: host or security plugin blocks `wp-cron.php`.
- **Action Scheduler queue backed up**: thousands of pending actions, each slow.
- **Fatal in the job**: action fails and retries until it gives up.
- **Time limits**: job exceeds `max_execution_time` and is killed mid-run.
- **Duplicate hooks**: two plugins schedule the same job with different args.

## Real cron (recommended for stores)

Disable the request-triggered runner:

```php
define( 'DISABLE_WP_CRON', true );
```

Add a server cron every 5 minutes:

```
*/5 * * * * cd /path/to/site && wp cron event run --due-now --quiet
```

For Action Scheduler, a queue runner is already triggered by traffic and by its own loopback; the WooCommerce docs recommend keeping the default unless the host blocks loopback.

## Diagnosing a missed scheduled post

```
wp cron event list --fields=hook,next_run_gmt --format=json
wp option get timezone_string
wp post get 123 --field=post_date
wp post get 123 --field=post_status
```

A `future` post whose `post_date` passed but status did not change means `publish_future_post` never ran.

## Cleaning up

```
wp cron event delete <hook> <timestamp>
wp cron event unschedule <hook>
```

Remove orphaned hooks left by deleted plugins:

```
wp eval '
$cron = get_option( "cron" );
foreach ( (array) $cron as $ts => $hooks ) {
    if ( ! is_array( $hooks ) ) { continue; }
    foreach ( array_keys( $hooks ) as $hook ) {
        if ( ! has_action( $hook ) ) { echo $hook . "\n"; }
    }
}
'
```

Do not bulk-delete from the `cron` option by hand; it is serialized and easy to corrupt.

## Action Scheduler table growth

`actionscheduler_actions` and `actionscheduler_logs` can reach millions of rows.

```
wp db query "SELECT status, COUNT(*) AS n FROM wp_actionscheduler_actions GROUP BY status" --format=json
wp action-scheduler clean --status=complete --before="30 days ago"
```

WooCommerce also has a built-in cleanup interval under Tools → Scheduled Actions → Settings.

## Gotchas

- `DISABLE_WP_CRON` without a real cron means nothing scheduled ever runs.
- Running `wp cron event run --due-now` during checkout can add load; avoid peak hours.
- Duplicated actions usually mean the scheduling hook fires more than once (e.g. on every page load) — check `wp_next_scheduled` guards.
- Timezone mistakes make a "missed" job look missed when it is scheduled for the wrong hour.
- Deleting a plugin does not delete its pending actions unless it cleans up on uninstall.
- Action Scheduler retries failed actions with backoff; a permanently failing action will keep retrying until it reaches the attempt limit.
