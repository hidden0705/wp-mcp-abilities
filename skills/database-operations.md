---
title: Database Operations
category: Core
summary: Work with the WordPress database safely — wpdb queries, options and autoload, revisions, orphaned meta, HPOS order tables, and search-replace discipline.
updated: 2026-10-26
---

# Database Operations

WordPress stores almost everything in a handful of tables. Understand which one owns the truth before writing.

## Table map

| Table | Owns |
| --- | --- |
| `posts` | posts, pages, products (as CPT), attachments, revisions, menus |
| `postmeta` | all post/product/order meta when not HPOS |
| `options` | settings, rewrites, cron, transients (non-expiring) |
| `users`, `usermeta` | accounts, roles, capabilities |
| `terms`, `term_taxonomy`, `term_relationships` | categories, tags, product cats |
| `comments`, `commentmeta` | comments and reviews |
| `wc_orders`, `wc_orders_meta`, `wc_order_addresses`, `wc_order_operational_data` | HPOS orders |
| `wc_order_product_lookup`, `wc_order_stats` | reporting (read-only, derived) |
| `actionscheduler_*` | Action Scheduler jobs |

Check the prefix and HPOS first:

```
wp db query "SELECT DATABASE() AS db" --format=json
wp option get woocommerce_custom_orders_table_enabled
```

## Safe query pattern

```
wp db query "SELECT ID, post_title FROM wp_posts WHERE post_type='page' AND post_status='publish' LIMIT 20" --format=json
```

- Always `LIMIT` when exploring.
- Never `SELECT *` on `postmeta` or `options` — they are huge.
- Use `$wpdb->prepare` in PHP; in CLI, quote carefully and prefer IDs you already have.

Read-only checks worth running monthly:

```
wp db query "SELECT COUNT(*) AS revisions FROM wp_posts WHERE post_type='revision'" --format=json
wp db query "SELECT COUNT(*) AS orphans FROM wp_postmeta pm LEFT JOIN wp_posts p ON p.ID=pm.post_id WHERE p.ID IS NULL" --format=json
wp db query "SELECT option_name, LENGTH(option_value) AS bytes FROM wp_options WHERE autoload='yes' ORDER BY bytes DESC LIMIT 25" --format=json
wp db query "SELECT COUNT(*) AS expired FROM wp_options WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" --format=json
```

## Autoloaded options

Every autoloaded option is read on every request. A healthy site keeps total autoload under ~800 KB–1 MB. Usual offenders: page-builder caches, backup plugin state, `rewrite_rules`, stale plugin options.

```
wp option list --autoload=on --fields=option_name,size --format=json
```

Disable autoload for a specific option (does not delete it):

```
wp option update <name> --autoload=no
```

Do not disable `siteurl`, `home`, `active_plugins`, `template`, `stylesheet`, `rewrite_rules`, or `cron`.

## Transients

```
wp transient list --format=json
wp transient delete --expired
wp transient delete --all
```

`--all` on a busy store forces regeneration storms. Prefer expired-only.

## Revisions

```
wp post list --post_type=revision --posts_per_page=20 --fields=ID,post_parent,post_date --format=json
wp post delete $(wp post list --post_type=revision --format=ids) --force
```

Keep at least 5–10 revisions per post; they are the only undo for content. Limit future growth in `wp-config.php`:

```php
define( 'WP_POST_REVISIONS', 10 );
```

## Search-replace discipline

```
wp search-replace "old.com" "new.com" --all-tables --dry-run
wp search-replace "old.com" "new.com" --all-tables --skip-columns=guid
```

- Dry-run, read the per-table counts, back up, then run.
- `--skip-columns=guid` is standard; changing GUIDs can make feed readers re-publish everything.
- Serialized arrays are fixed by WP-CLI; raw SQL `REPLACE()` corrupts them.
- After a replace: `wp cache flush`, `wp rewrite flush`, purge LiteSpeed, then fetch the homepage and one product page.

## HPOS specifics

When HPOS is active, orders live in `wc_orders`, not `posts`. `postmeta` for an order may be empty or stale.

- Never update an order with `wp_update_post`, `update_post_meta`, or SQL on `posts` — WooCommerce 10.7+ disables sync-on-read by default, so your change is silently ignored.
- Use `$order = wc_get_order( $id ); $order->set_status( 'completed' ); $order->save();` or the REST API.
- HPOS sync to posts is one-way (HPOS → posts) in compatibility mode.
- Back up `wc_orders*` tables together with `posts`; a posts-only dump loses orders.

## Options that matter

| Option | Meaning |
| --- | --- |
| `siteurl`, `home` | canonical URLs; mismatch causes redirect loops |
| `permalink_structure` | empty = plain permalinks |
| `active_plugins` | serialized list — never edit by hand |
| `template`, `stylesheet` | parent/child theme slugs |
| `woocommerce_custom_orders_table_enabled` | HPOS on/off |
| `woocommerce_currency` | store currency |
| `elementor_active_kit` | active Elementor kit post ID |
| `litespeed.conf` | LiteSpeed settings array |
| `cron` | scheduled events (serialized) |

## Cleanup that is safe vs risky

Safe:
- delete expired transients
- delete orphaned postmeta (after backup)
- delete spam comments and their meta
- optimize tables (`wp db optimize`)

Risky:
- deleting "unused" options by name pattern
- deleting users without reassigning content
- dropping tables from uninstalled plugins that may still be referenced
- `wp db reset` (destroys everything)

## Migration and export

```
wp db export wp-content/uploads/db-backup.sql
wp db import wp-content/uploads/db-backup.sql
```

A DB dump does not include uploads or plugin files. Full backup = DB + `wp-content` + `wp-config.php`.

## Gotchas

- MySQL strict mode rejects some legacy inserts; check `wp db query "SELECT @@sql_mode"`.
- `wp db query` returns every column as a string.
- Table prefix is not always `wp_`; `wp db prefix` (or `$wpdb->prefix`) tells you.
- Object cache can serve stale option values after a direct SQL update — flush it.
- Never run `ALTER TABLE` on HPOS or Action Scheduler tables while checkout is processing orders.
