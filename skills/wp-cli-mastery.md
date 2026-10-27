---
title: WP-CLI Mastery
category: Core
summary: Drive WordPress from the shell — plugin/theme/core management, database access, search-replace, cron, one-off PHP, and the flags that prevent disasters.
updated: 2026-10-26
---

# WP-CLI Mastery

`wpmcp/wpcli` runs `wp` with a 90-second timeout, `--path=<ABSPATH>`, and no pipes/redirects. Output keeps the last ~20 KB.

## Golden rules

- Always start read-only: `list`, `get`, `status`, `--dry-run`.
- Destructive commands get a backup first.
- No pipes: use `--format=json`, `--fields=`, `--filter`, or `eval`.
- Big output: write to `wp-content/uploads/*.json` and read via `wpmcp/files`.

## Discovery

```
core version
core check-update --format=json
plugin list --format=json --fields=name,status,version,update
plugin list --status=active --format=json --fields=name,version
theme list --format=json
option get siteurl
option get home
option get permalink_structure
cron event list --format=json
db size
```

## Plugins

```
plugin install woocommerce --activate
plugin activate mcp-adapter
plugin deactivate <slug>
plugin update <slug> --dry-run
plugin update --all --yes
plugin delete <slug>
plugin verify-checksums woocommerce
```

`plugin verify-checksums` is the fastest malware check for wordpress.org plugins: modified files are listed.

## Themes

```
theme list --format=json
theme activate oceanwp-child-theme-master
theme update --all
theme delete <slug>
```

Never activate a parent theme while custom code lives only in the child.

## Posts, meta, terms

```
post list --post_type=page --fields=ID,post_title,post_status --format=json
post get 123 --field=post_content
post create --post_type=page --post_title="New" --post_status=draft --porcelain
post update 123 --post_status=publish
post meta get 123 _elementor_data --format=json
post meta update 123 _thumbnail_id 456
term list category --fields=term_id,name,count --format=json
menu list --format=json
```

`--porcelain` returns only the new ID — ideal for chaining manually.

## Database

```
db query "SELECT ID,post_title FROM wp_posts WHERE post_type='product' AND post_status='publish' LIMIT 20" --format=json
db query "SELECT option_name, LENGTH(option_value) AS bytes FROM wp_options WHERE autoload='yes' ORDER BY bytes DESC LIMIT 20" --format=json
db export wp-content/uploads/db-$(date +%Y%m%d-%H%M).sql
db import wp-content/uploads/db-20261026-1200.sql
db check
db optimize
```

`wp db export` accepts a path; avoid `$(date)` inside the ability if quoting gets messy — pass a literal filename.

## Search-replace

```
search-replace "http://old.example" "https://new.example" --all-tables --dry-run
search-replace "http://old.example" "https://new.example" --all-tables --skip-columns=guid
search-replace "old@example.com" "new@example.com" --all-tables --dry-run
```

Rules: dry-run first; count matches; back up; never `--all-tables` across a multisite network without understanding each site's tables; skip `guid` unless you know why you are changing it.

Serialized data (Elementor, widgets, options) is handled correctly. Raw SQL is not.

## Users and roles

```
user list --role=administrator --fields=ID,user_login,user_email --format=json
user get 1 --format=json
user update 5 --role=shop_manager
user meta get 1 nickname
```

Creating users with passwords or app passwords is a human-approved action; do it in the UI or via REST, not by pasting secrets into a command log.

## Cron and background jobs

```
cron event list --format=json
cron event run wp_version_check
cron event delete <hook> <timestamp>
cron event schedule <hook> 3600
```

WooCommerce/action-scheduler jobs are separate: `action-scheduler run`, `action-scheduler list --status=pending`.

## Cache and rewrites

```
cache flush
transient delete --expired
rewrite flush --hard
rewrite list --format=json
litespeed-purge all
```

## One-off PHP

```
eval 'echo json_encode( get_option("woocommerce_currency") );'
eval 'echo count( get_posts( array("post_type"=>"product","fields"=>"ids","posts_per_page"=>-1) ) );'
eval 'var_export( wp_get_theme()->get("Name") );'
```

Use single quotes around the whole command; inside use double quotes for PHP strings. A fatal in `eval` kills only that CLI process.

## Maintenance mode

```
maintenance-mode activate
maintenance-mode deactivate
```

Activate for migrations, deactivate immediately after. Never leave it on.

## Troubleshooting the CLI itself

| Error | Meaning | Fix |
| --- | --- | --- |
| `Error: This does not seem to be a WordPress installation` | wrong path | pass `--path=` explicitly |
| `proc_open is disabled` | host restriction | use `wpmcp/rest-request` |
| `sh: wp: command not found` | binary missing | use REST |
| output truncated | >20 KB | write to file, read via `files` |
| exit 255 | PHP fatal | read `debug.log` |
| DB connection error | credentials/host | check `wp-config.php` |

## Safety table

| Command | Reversible? |
| --- | --- |
| `plugin update` | yes, reinstall old version |
| `search-replace` | only from backup |
| `db import` | only from backup |
| `db reset` | no — destroys data |
| `post delete --force` | no (revisions may not exist) |
| `user delete` | no |
| `site empty` | no |

Treat everything in the bottom half as Critical.
