---
title: Backups & Disaster Recovery
category: Operations
summary: Take backups you can actually restore — database, files, config, offsite copies, retention, and a tested restore procedure for the common disaster types.
updated: 2026-10-26
---

# Backups & Disaster Recovery

A backup is only real if you have restored it once.

## What to back up

| Component | Why |
| --- | --- |
| Database | content, orders, options, users, Elementor data |
| `wp-content` | plugins, themes, uploads, mu-plugins |
| `wp-config.php` | salts, DB credentials, constants |
| `.htaccess` / server rules | redirects, caching, security rules |

A DB dump alone cannot restore a deleted plugin or missing images. A file archive alone cannot restore orders.

## Quick snapshot before risky work

```
wp db export wp-content/uploads/backup-db-20261026-1200.sql
```

Theme/plugin archive without shell zip:

```
wp eval '
$zip = new ZipArchive();
$zip->open( WP_CONTENT_DIR . "/uploads/backup-theme-20261026.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE );
$root = WP_CONTENT_DIR . "/themes/oceanwp-child-theme-master";
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) { if ( $f->isFile() ) { $zip->addFile( $f->getPathname(), substr( $f->getPathname(), strlen( $root ) + 1 ) ); } }
$zip->close();
echo "ok";
'
```

Verify both files exist and are non-trivial in size before proceeding.

## Full-site backup

If a backup plugin is active (UpdraftPlus, BackWPup, Duplicator, Solid Backups), use its schedule and its "backup now" action. Check what is installed first:

```
wp plugin list --status=active --fields=name --format=json
```

If none is active, the minimum viable full backup is:

1. `wp db export` to a file outside the web root if possible.
2. Archive `wp-content` (themes, plugins, uploads).
3. Copy `wp-config.php`.
4. Download all three to your machine or object storage.

Do not store backups only in `wp-content/uploads` — they are web-accessible and often the first thing an attacker downloads.

## Restore procedures

**Database only** (content mistake, bad import):

```
wp db import wp-content/uploads/backup-db-20261026-1200.sql
wp cache flush
wp litespeed-purge all
```

**Single file/folder** (bad theme edit):

```
wp eval '
$zip = new ZipArchive();
$zip->open( WP_CONTENT_DIR . "/uploads/backup-theme-20261026.zip" );
$zip->extractTo( WP_CONTENT_DIR . "/themes/oceanwp-child-theme-master" );
$zip->close();
echo "restored";
'
```

**Whole site** (host migration, severe corruption):

1. Put the site in maintenance mode.
2. Restore files first, then the DB.
3. Update `siteurl`/`home` if the domain changed.
4. `wp cache flush`, `wp rewrite flush --hard`, purge LiteSpeed.
5. Log in, verify orders and checkout, then disable maintenance mode.

## Disaster → action table

| Disaster | Restore |
| --- | --- |
| Bad plugin update | reinstall previous version; DB usually untouched |
| Bad theme file edit | restore that file |
| Accidental page deletion | restore page from revision, not full DB |
| Accidental product deletion | restore from DB backup (products are posts) |
| Corrupted DB | import last good dump |
| Hacked site | restore a pre-compromise backup, then rotate credentials |
| Host lost everything | files + DB on new host, update config |
| Ransomware/encryption | restore from offsite copy; do not pay |

## Retention policy

- 7 daily snapshots
- 4 weekly
- 3 monthly
- 1 offsite copy at all times

For a store, losing one day of orders is usually acceptable; losing a week is not. Adjust daily retention to order volume.

## Testing a restore

Once a quarter, on staging:

1. Import the DB dump into a fresh database.
2. Extract the files.
3. Point `wp-config.php` at the test DB.
4. Check homepage, a product, cart, checkout, and admin login.
5. Note the time it took — that is your real recovery time.

## Backup plugin comparison

| Tool | Strength | Watch out |
| --- | --- | --- |
| UpdraftPlus | incremental, offsite (S3/Drive) | free version restore is manual |
| BackWPup | flexible jobs, CLI | large archives time out on shared hosts |
| Duplicator | migration-friendly | package size limits |
| Solid Backups | staging + restore | license for remote storage |
| Host-level | fastest bare-metal restore | may not cover files or be portable |

## Gotchas

- A backup that excludes `uploads` restores a site with broken images and missing product photos.
- HPOS orders live in `wc_orders*` tables; a `wp_posts`-only export misses them.
- `wp db export` on a large store can exceed the 90-second CLI limit — export selected tables or use the host tool.
- Object cache survives a DB import; flush it or you will read stale options.
- Restoring an old DB after new orders arrived loses those orders. Export current data first.
- Backup files in `uploads` are a data leak; protect the directory or move them.
