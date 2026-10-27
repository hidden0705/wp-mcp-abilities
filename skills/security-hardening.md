---
title: Security Hardening
category: Operations
summary: Close the common WordPress attack surface — updates, logins, application passwords, file permissions, XML-RPC, and a verification checklist you can rerun.
updated: 2026-10-26
---

# Security Hardening

Goal: reduce the ways in without breaking the site or locking out the admin.

## Baseline audit (read-only)

```
wp core version
wp plugin list --status=active --format=json --fields=name,version,update
wp theme list --format=json --fields=name,status,version,update
wp user list --role=administrator --fields=ID,user_login,user_email,user_registered --format=json
wp option get users_can_register
wp option get default_role
wp eval 'echo defined("DISALLOW_FILE_EDIT") ? "file-edit-off" : "file-edit-on";'
wp eval 'echo defined("FORCE_SSL_ADMIN") ? "ssl-admin-on" : "ssl-admin-off";'
wp eval 'echo defined("WP_DEBUG_DISPLAY") && WP_DEBUG_DISPLAY ? "display-on" : "display-off";'
```

Red flags: more than two administrators, an `admin` username, `users_can_register=1` with a privileged default role, updates pending for weeks, `WP_DEBUG_DISPLAY` on in production.

## Priority order (highest impact first)

1. **Patch.** Core, plugins, themes. Most compromises exploit known, fixed vulnerabilities.
2. **Remove unused plugins/themes.** Inactive code is still attack surface if reachable.
3. **Login protection.** 2FA for admins, rate limiting, no `admin` username.
4. **Least privilege.** `shop_manager` for store staff, `editor` for content.
5. **HTTPS everywhere.** Valid certificate, `FORCE_SSL_ADMIN`, HSTS at the server.
6. **File permissions.** Directories 755, files 644, `wp-config.php` 600.
7. **Backups.** An uncompromised backup is the only guaranteed recovery.
8. **Monitoring.** File integrity, login logs, uptime.

## wp-config.php constants

```php
define( 'DISALLOW_FILE_EDIT', true );      // no browser file editor
define( 'DISALLOW_FILE_MODS', false );     // keep false; true blocks updates too
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', true );            // log without display
define( 'WP_DEBUG_DISPLAY', false );
define( 'FORCE_SSL_ADMIN', true );
define( 'WP_POST_REVISIONS', 10 );
define( 'AUTOMATIC_UPDATER_DISABLED', false );
```

Do not set `DISALLOW_FILE_MODS` to true unless you update exclusively from CLI — it also disables plugin installs from wp-admin and from `wpmcp/rest-request`'s plugin routes.

## Application passwords

- One per integration, named after it (`wp-mcp-agent`, `backup-script`).
- Never reuse across agents.
- Revoke when an integration is retired or a device is lost.
- Requires HTTPS; unavailable over plain HTTP.

List the names (values are hashed):

```
wp eval 'echo json_encode( array_keys( (array) get_user_meta( 1, "_application_passwords", true ) ) );'
```

Revoke all for a user:

```
wp user meta delete 1 _application_passwords
```

## Login hardening

- Limit attempts at the server or with a plugin (5–10 per 15 min).
- 2FA for every administrator.
- Rename or block `wp-login.php` only if you have a tested alternate path; a wrong rule locks you out.
- Block `xmlrpc.php` unless a specific integration needs it.

```
wpmcp/fetch-page { "url": "https://example.com/xmlrpc.php" }
```

A 405/403 is good. A 200 that accepts POST is a brute-force amplifier.

## File permissions

```
wp eval 'echo substr( sprintf( "%o", fileperms( ABSPATH . "wp-config.php" ) ), -4 );'
```

Expected: `600` or `640` for `wp-config.php`, `755` dirs, `644` files. Anything `777` is wrong.

## Database hardening

- Unique `wp_` prefix (changing it on a live site is risky; do it at build time).
- Separate DB user with only the privileges WordPress needs (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP).
- No `root` DB user.
- Backups stored outside the web root.

## Headers and TLS

Set at the server/CDN:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

A Content-Security-Policy is valuable but breaks Elementor and third-party scripts easily; roll it out in report-only mode first.

## Security plugins: what they actually do

| Layer | Plugin example | Note |
| --- | --- | --- |
| Firewall/WAF | Wordfence, Solid Security | server-level WAF is stronger |
| Malware scan | Wordfence, Wordfence CLI | scans files against signatures |
| Login protection | Limit Login Attempts, Solid | pair with 2FA |
| Activity log | WP Activity Log | useful for incident timelines |

Do not stack two firewalls; they conflict and slow the site.

## Verify after hardening

1. Log out, log in with 2FA.
2. Place a test order (if WooCommerce) — checkout must still work.
3. Run `wpmcp/fetch-page` on home, shop, cart, checkout.
4. Confirm REST still accepts the agent's application password.
5. Check `debug.log` for new fatals.

## Gotchas

- A security rule that blocks `/wp-json/` breaks this MCP server and the block editor. Allowlist REST for authenticated requests.
- Changing `siteurl`/`home` to HTTPS without a valid certificate causes redirect loops.
- `DISALLOW_FILE_EDIT` does not stop `wpmcp/files` — file writes go through PHP, not the editor.
- Deleting an admin user does not delete their posts by default; reassign first.
- Staging sites often have weak credentials and public access; protect them or delete them.
