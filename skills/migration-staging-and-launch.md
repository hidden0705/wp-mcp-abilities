---
title: Migration, Staging & Launch
category: Operations
summary: Move a WordPress site or promote staging to production without broken URLs, lost orders, or a cache serving the old site.
updated: 2026-10-26
---

# Migration, Staging & Launch

Migration = files + database + domain/URL change + cache/SSL/search-engine cleanup, in that order.

## Pre-flight

```
wp core version
wp plugin list --status=active --format=json --fields=name,version
wp db size
wp option get siteurl
wp option get home
wp option get permalink_structure
```

Record: PHP version, DB size, active plugins, theme, HPOS state, cron jobs, external integrations (SMTP, payment webhooks, shipping).

## Export

```
wp db export wp-content/uploads/migration-db.sql
```

Files: archive `wp-content` plus `wp-config.php`. Exclude caches to keep the archive small:

- `wp-content/cache/`
- `wp-content/uploads/elementor/css/`
- `wp-content/ai1wm-backups/`
- `wp-content/updraft/`
- `wp-content/litespeed/`

## Import on the destination

1. Create the DB and user; grant standard WordPress privileges.
2. Import the dump.
3. Upload files.
4. Edit `wp-config.php`: DB name/user/password/host, and new salts if the site is public.
5. Add `define( 'WP_HOME' )` / `define( 'WP_SITEURL' )` temporarily if the domain differs.

## URL change

Always dry-run:

```
wp search-replace "https://staging.example.com" "https://example.com" --all-tables --dry-run
wp search-replace "https://staging.example.com" "https://example.com" --all-tables --skip-columns=guid
```

Then:

```
wp cache flush
wp rewrite flush --hard
wp option update siteurl "https://example.com"
wp option update home "https://example.com"
wp litespeed-purge all
```

Serialized data is handled by WP-CLI; raw SQL is not.

## Staging → production checklist

- [ ] DB imported, URLs replaced
- [ ] `wp-config.php` points at production DB
- [ ] Salts rotated (staging sessions must not work in production)
- [ ] Search engine visibility: production indexable, staging noindex
- [ ] SSL certificate valid on production
- [ ] Payment gateway in live mode (not sandbox)
- [ ] SMTP/transactional email configured for the production domain
- [ ] Webhooks updated (WooCommerce, OttoKit, shipping)
- [ ] Cron events present
- [ ] Cache purged, CDN purged
- [ ] `robots.txt` and sitemap correct
- [ ] Test order placed and refunded
- [ ] Admin login works, 2FA enabled
- [ ] Backups scheduled on production

## Domain change specifics

- DNS TTL lowered to 300s **before** the switch.
- SSL issued for both apex and `www`.
- 301 from the old domain to the new one at the server, path-preserving.
- Google Search Console: add the new property, change of address.
- Update: `siteurl`, `home`, WooCommerce shop page IDs (unchanged), email templates, canonical URLs, social profiles, payment gateway return URLs, shipping label accounts.

## Staging access protection

Staging must not be indexable or publicly usable:

- HTTP Basic auth at the server, or IP allowlist.
- `wp option update blog_public 0`.
- `robots.txt` with `Disallow: /`.
- Disable outgoing email on staging (`define( 'WP_MAIL_DISABLE', true )` is not core; use an SMTP plugin's "disable sending" or a `wp_mail` filter) so customers do not receive test orders.

## Moving hosts without downtime

1. Lower DNS TTL 24h ahead.
2. Build the destination, import, test via hosts file or temporary URL.
3. Freeze writes (maintenance mode), final DB export/import.
4. Switch DNS.
5. Keep the old host live for 48–72h as rollback.
6. Verify, then decommission.

## Common failures

| Symptom | Cause | Fix |
| --- | --- | --- |
| Redirect loop | `siteurl`/`home` mismatch or SSL | fix options; check `FORCE_SSL_ADMIN` |
| 404 on all but home | rewrites not flushed | `wp rewrite flush --hard` |
| Mixed content | `http://` in DB | search-replace to https |
| Images broken | uploads not copied | rsync/upload `wp-content/uploads` |
| Login redirects to old domain | cached options | flush cache, check `siteurl` |
| Orders missing | HPOS tables not exported | include `wc_orders*` |
| Emails not sending | SMTP credentials for old domain | reconfigure SMTP |

## Rollback plan

Before switching: note the old DNS records, keep the old host active, keep a known-good DB dump from the freeze moment. Rollback = restore DNS + old files/DB, not a rebuild.

## Gotchas

- Elementor stores absolute URLs inside `_elementor_data`; search-replace fixes them only when serialized data is parsed correctly (WP-CLI does; raw SQL does not).
- WooCommerce order emails contain the old domain in already-sent messages; you cannot change history.
- Licence-bound plugins may deactivate on a new domain; re-authorise them.
- `.htaccess` from the old host may include rules that break the new one — regenerate WordPress's block.
- Cron events can fire twice during cutover; Action Scheduler handles duplicates but custom hooks may not.
