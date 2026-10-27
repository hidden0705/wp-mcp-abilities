---
title: Debugging & Troubleshooting
category: Operations
summary: Diagnose a broken WordPress site in a fixed order — stabilise, read the fatal, isolate by symptom, verify the environment, then fix the root cause.
updated: 2026-10-26
---

# Debugging & Troubleshooting

## Step 0 — Stabilise

If the site is down, get it serving before investigating:

```
wp plugin deactivate --all
wp theme activate twentytwentyfour
wp litespeed-purge all
```

Then reactivate in groups of 3–5 and test after each. Binary-search, do not add one at a time from 20 plugins.

## Step 1 — Read the fatal

Enable logging (never display errors in production):

```
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
```

Read the newest lines:

```
wpmcp/files { "action": "read", "path": "debug.log" }
```

`wp config set` is cleaner than editing the file, but confirm it did not duplicate constants:

```
wp config get WP_DEBUG
```

The newest `PHP Fatal error` names the file and line. That is usually the answer.

## Step 2 — Symptom → cause

| Symptom | Likely cause | First check |
| --- | --- | --- |
| White screen, front and admin | fatal in plugin/theme/mu-plugin | `debug.log` |
| White screen, one page | bad template or Elementor JSON | fatal line; page meta |
| HTTP 500 everywhere | PHP version, `.htaccess`, memory | host error log |
| 503 / "briefly unavailable" | `.maintenance` file | `wp eval 'echo file_exists(ABSPATH.".maintenance")?"yes":"no";'` |
| Redirect loop | `siteurl` ≠ `home`, or SSL | `wp option get siteurl/home` |
| 404 everywhere except home | rewrites missing | `wp rewrite flush --hard` |
| Login works, admin blank | admin JS/CSS blocked | browser console; security plugin |
| Layout broken, HTML fine | stale CSS/minify/cache | purge `cssjs`, `min`, LiteSpeed |
| Images missing | uploads path/permissions | `wp-content/uploads` exists and writable |
| Slow everywhere | plugin queries, object cache | Query Monitor, `wp db query "SHOW PROCESSLIST"` |
| Emails missing | SMTP/DNS | `email-deliverability` |
| REST 401/403 | security rule, app password | `rest-api-and-integrations` |

## Step 3 — Environment facts

```
wp core version
wp eval 'echo PHP_VERSION;'
wp eval 'echo WP_MEMORY_LIMIT . " / " . WP_MAX_MEMORY_LIMIT;'
wp db check
wp option get siteurl
wp option get home
wp option get permalink_structure
wp theme list --format=json
wp plugin list --status=active --fields=name,version --format=json
```

Plain permalinks (`permalink_structure` empty) explain a whole class of REST and 404 issues.

## Step 4 — Isolate subsystems

```
wp plugin list --status=active --format=json
wp cron event list --format=json
wp eval 'echo json_encode( wp_get_theme()->get( "Name" ) );'
```

Stale state:

```
wp cache flush
wp transient delete --expired
wp litespeed-purge all
```

## Step 5 — Content vs code

```
wp post list --post_type=page --post_status=any --fields=ID,post_title,post_status --format=json
wp post get 123 --field=post_status
wp post meta get 123 _elementor_data --format=json
```

Content "disappearing" is usually a draft/trash status, a menu pointing at a deleted page, or a cache.

## Step 6 — Recovery mode

WordPress emails a recovery link on fatals and creates recovery-mode state. The CLI equivalent:

```
wp plugin deactivate <slug>
```

If the fatal is in a file you just edited, restore it immediately; the front end recovers as soon as syntax is valid.

## Reading a long log

```
wp eval '
$f = WP_CONTENT_DIR . "/debug.log";
if ( ! file_exists( $f ) ) { echo "no log"; exit; }
$lines = file( $f );
echo implode( "\n", array_slice( $lines, -100 ) );
'
```

Rotate instead of deleting:

```
wp eval 'rename( WP_CONTENT_DIR . "/debug.log", WP_CONTENT_DIR . "/debug-" . date( "Ymd-His" ) . ".log" );'
```

## Common WordPress-specific traps

- **Memory limit**: a 500 with "Allowed memory size exhausted" needs `WP_MEMORY_LIMIT` raised in `wp-config.php` and at the host.
- **Max execution time**: imports and backups time out at 30–60s; raise per-request, not globally.
- **Object cache stale**: after a DB import or direct SQL, flush Redis/Memcached or the site reads old options.
- **LiteSpeed serving stale HTML**: purge all; check `x-litespeed-cache`.
- **Elementor CSS not regenerating**: delete `_elementor_css` meta and `uploads/elementor/css/*`.
- **Plugin conflict**: deactivate all, reactivate in groups; do not test with a cache plugin on.
- **Theme conflict**: switch to a default theme temporarily; if the issue vanishes, it is the theme/child theme.
- **`.htaccess` corruption**: regenerate via Settings → Permalinks (save) or `wp rewrite flush --hard`.

## After the fix

1. Reactivate everything you disabled.
2. Turn `WP_DEBUG_DISPLAY` off; keep logging if useful.
3. Flush caches.
4. Fetch the affected URL and confirm 200 + expected content.
5. Write down cause and fix; repeat incidents share triggers.

## Gotchas

- Never edit `wp-config.php` without a copy; a syntax error there has no recovery mode.
- Deleting a plugin folder leaves its options, tables and cron events.
- `wp db check` checks table health, not logical corruption.
- If `proc_open` is blocked, all `wpmcp/wpcli` calls fail — use REST and `files`.
- Debug logging on a busy site grows fast; rotate it.
