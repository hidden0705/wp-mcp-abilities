# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.4.x | Yes |
| < 2.4 | No (internal releases) |

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Use GitHub's private vulnerability reporting:

1. Open the repository's **Security** tab.
2. Choose **Report a vulnerability**.
3. Include the affected version, WordPress/PHP versions, reproduction steps and impact.

You will get an acknowledgement within 7 days and a status update within 30 days.
Credit is given in the release notes unless you prefer to stay anonymous.

## Threat model

This plugin is **administrator-level by design**. It does not try to be a sandbox.
Anyone who holds a valid application password for an administrator can already edit
themes and plugins through wp-admin; this plugin makes the same power available to an
MCP client.

What that means in practice:

| Ability | Worst case if the credential leaks |
| --- | --- |
| `wpmcp/wpcli` (opt-in) | Arbitrary shell commands through `wp eval` / `wp shell` |
| `wpmcp/files` | Write PHP under `wp-content`, including mu-plugins |
| `wpmcp/rest-request` | Install and activate any plugin from WordPress.org |
| `wpmcp/elementor` | Change any page layout; read all page content |
| `wpmcp/restore` | Restore old copies of edited files |
| `wpmcp/skill-write` | Change the instructions future agents read |

The correct mitigation is credential hygiene, not plugin-level sandboxing:

1. Use a dedicated administrator account for MCP, not a personal one.
2. Serve the site over HTTPS only.
3. Restrict `/wp-json/mcp/*` by IP or edge authentication where possible.
4. Leave `WPMCP_ENABLE_WPCLI` off unless an agent genuinely needs shell-level access.
5. Use `WPMCP_READONLY` for agents that only need to inspect.
6. Revoke the application password when a client is retired.
7. Read `wpmcp_audit_log` after agent sessions.

## In scope

- Authentication or capability bypass in an ability.
- Path traversal or symlink escape in `wpmcp/files`, `wpmcp/restore`, or the backup
  directory.
- An SSRF bypass in `wpmcp/fetch-page`, `wpmcp/media-import` or `wpmcp/audit-url`:
  a public URL that redirects, resolves or rebinds into a private or reserved
  address despite the guard.
- A site playbook path that escapes `wp-content/wpmcp-skills/` or shadows an
  ability it should not.
- SQL injection, remote code execution or privilege escalation that does **not**
  require an administrator application password.
- Exposure of secrets in the audit log, error messages or backups.
- Backup files readable over HTTP on a supported web server configuration.
- Cross-site scripting in any admin output.

## Out of scope

- Anything an administrator can already do intentionally (`wp eval`, writing PHP,
  installing plugins). This is documented behaviour, not a vulnerability.
- Sites that expose `wp-content/wpmcp-backups/` because the web server ignores
  `.htaccess` **and** the operator did not add the documented deny rule.
- Vulnerabilities in WordPress core, `mcp-adapter`, Elementor, WooCommerce or other
  plugins.
- Missing rate limiting on an endpoint that already requires administrator auth.
- Social engineering, physical access, or a compromised hosting account.

## Hardening checklist

- [ ] HTTPS everywhere; `FORCE_SSL_ADMIN` on.
- [ ] Dedicated administrator account with a strong unique password.
- [ ] Application password scoped to that account; stored in a password manager.
- [ ] `define( 'WPMCP_READONLY', true );` on production for inspection-only agents.
- [ ] `wp-content/wpmcp-backups/` denied at the web server (Nginx snippet below).
- [ ] `wp-content/wpmcp-skills/` denied at the web server (same rule).
- [ ] `WPMCP_ENABLE_WPCLI` off unless the agent needs it.
- [ ] `DISALLOW_FILE_EDIT` on; `WP_DEBUG_DISPLAY` off.
- [ ] Regular off-site backups, tested restores.
- [ ] Audit log reviewed after each agent session.

Nginx:

```nginx
location ^~ /wp-content/wpmcp-backups/ {
	deny all;
	return 403;
}

location ^~ /wp-content/wpmcp-skills/ {
	deny all;
	return 403;
}
```

## Disclosure policy

- Fixes are prepared privately and released as a patch version.
- A GitHub Security Advisory is published after the fix is available.
- Reporters are credited unless they ask otherwise.
