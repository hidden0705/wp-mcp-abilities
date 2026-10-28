# WP MCP Abilities

[![CI](https://github.com/hidden0705/wp-mcp-abilities/actions/workflows/ci.yml/badge.svg)](https://github.com/hidden0705/wp-mcp-abilities/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/hidden0705/wp-mcp-abilities)](https://github.com/hidden0705/wp-mcp-abilities/releases)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-21759b.svg)](https://wordpress.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-8892bf.svg)](https://php.net/)

Administrator-level MCP abilities for WordPress, built on the official
[`mcp-adapter`](https://wordpress.org/plugins/mcp-adapter/) plugin.

Every ability becomes a **first-class MCP tool** with its full input schema, so an
agent calls it once instead of walking the adapter's discover → info → execute
meta-tool chain. No third-party bridge, no relay service, no call limits: the AI
client talks straight to your site over HTTPS.

```
AI client  ──MCP over HTTPS──>  mcp-adapter  ──>  WP MCP Abilities  ──>  WordPress
```

## What it adds

| Area | What you get |
| --- | --- |
| WP-CLI | The real `wp` binary as shell-level admin (pipes allowed), 90 s timeout — **opt-in** |
| REST | Any WordPress/WooCommerce REST route executed as the authenticated user |
| Files | List/read/write/delete/search under `wp-content`, atomic writes, rollback copies |
| Elementor | One safe super-ability with 16 actions (structure, widgets, templates, globals, snapshots) |
| Diagnostics | Scored site health, PHP error tails, cron, autoload size, security checks |
| Content | Fetch rendered HTML, import media from a URL, PageSpeed/local audit |
| Knowledge | A 46-playbook WordPress/WooCommerce/Elementor library, searchable by agents |

## Requirements

- WordPress **6.9+** (the Abilities API is in core)
- PHP **8.0+**
- [`mcp-adapter`](https://wordpress.org/plugins/mcp-adapter/) **0.7+**, active
- HTTPS and an **Application Password** for an administrator account
- WP-CLI is optional and **off by default**; enable it with `WPMCP_ENABLE_WPCLI`

## Installation

1. Install and activate `mcp-adapter`.
2. Upload `wp-mcp-abilities.zip` under **Plugins → Add New → Upload Plugin**, or copy
   the `wp-mcp-abilities/` folder to `wp-content/plugins/`.
3. Activate **WP MCP Abilities**.
4. Optional: enable WP-CLI by adding `define( 'WPMCP_ENABLE_WPCLI', true );` to
   `wp-config.php` (needed only for `wpmcp/wpcli`).
5. Create an application password under **Users → Profile → Application Passwords**.
6. Point your MCP client at:

```
https://example.com/wp-json/mcp/mcp-adapter-default-server
```

With plain permalinks, use:

```
https://example.com/index.php?rest_route=/mcp/mcp-adapter-default-server/
```

A minimal client configuration:

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "http",
      "url": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_USERNAME_COLON_APPLICATION_PASSWORD"
      }
    }
  }
}
```

Generate the header value with:

```bash
printf '%s' 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' | base64
```

## Tools

All abilities require `manage_options`. Names map to MCP tools by replacing `/`
with `-`. Thirteen are always registered; `wpmcp/wpcli` only when the site opts in.

| Ability | MCP tool | Purpose | Writes |
| --- | --- | --- | --- |
| `wpmcp/agent-context` | `wpmcp-agent-context` | Site profile, stack, ability catalog, skill index, warnings. Start here. | — |
| `wpmcp/rest-request` | `wpmcp-rest-request` | Any REST route, as the current user | yes |
| `wpmcp/wpcli` | `wpmcp-wpcli` | WP-CLI through the real binary — **only when `WPMCP_ENABLE_WPCLI` is true** | yes |
| `wpmcp/files` | `wpmcp-files` | `wp-content` file CRUD + search, with backups | yes |
| `wpmcp/elementor` | `wpmcp-elementor` | Safe Elementor builder, 16 actions | yes |
| `wpmcp/fetch-page` | `wpmcp-fetch-page` | Rendered HTML of any URL | — |
| `wpmcp/media-import` | `wpmcp-media-import` | Sideload a file into the Media Library | yes |
| `wpmcp/audit-url` | `wpmcp-audit-url` | PageSpeed Insights, local fallback metrics | — |
| `wpmcp/list-skills` | `wpmcp-list-skills` | Skill index, filter by category/keyword | — |
| `wpmcp/get-skill` | `wpmcp-get-skill` | Full markdown playbook for one slug | — |
| `wpmcp/search-skills` | `wpmcp-search-skills` | Full-text search with a relevance floor; names plugins the library does not cover | — |
| `wpmcp/skill-write` | `wpmcp-skill-write` | Create/update/delete a playbook, with backup | yes |
| `wpmcp/site-health` | `wpmcp-site-health` | Scored diagnostics, six sections | — |
| `wpmcp/restore` | `wpmcp-restore` | List/restore/prune rollback copies | yes |

### Elementor super-ability

Instead of 200+ per-widget tools, `wpmcp/elementor` exposes 16 actions:

`get-structure`, `get-widget`, `add-widget`, `update-widget`, `move-widget`,
`duplicate-widget`, `delete-widget`, `create-page`, `set-page-settings`,
`get-globals`, `set-globals`, `list-templates`, `apply-template`,
`regenerate-css`, `snapshots`, `rollback`.

Every write snapshots the previous `_elementor_data`, encodes with `wp_slash`,
reads the layout back and invalidates only that post's CSS. A failed read-back
restores the previous layout automatically. Kit globals are the exception: they
affect every page, so `set-globals` flushes the whole Elementor cache.

### Third-party namespaces

Sites that run Elementor's own MCP plugin (formerly Angie) get 22 `elementor/*`
abilities and 3 `core/*` info abilities on the same endpoint. Add or remove
namespaces with a filter:

```php
add_filter( 'wpmcp_extra_tool_namespaces', function ( $namespaces ) {
	$namespaces[] = 'woocommerce/';
	return $namespaces;
} );
```

Abilities annotated `mcp.type: resource` (kit classes, global variables, dynamic
tags, interaction schema) are exposed in `resources/list`, not `tools/list`, as the
adapter expects.

## Skills

The plugin ships 46 markdown playbooks: Core (9), Operations (8), Growth (10),
WooCommerce (11), Elementor (8). They cover the traps that generic model knowledge
misses: HPOS tables, Elementor's `_elementor_data` format, block checkout, cache
purge matrices, `search-replace` safe ordering, and more.

Typical agent loop:

```
wpmcp-agent-context
wpmcp-search-skills   { "query": "stock status" }
wpmcp-get-skill       { "slug": "woocommerce-products" }
... do the work ...
wpmcp-fetch-page      { "url": "https://example.com/product/x/" }
```

New playbooks can be written by the agent itself with `wpmcp/skill-write`. They land
in `wp-content/wpmcp-skills/`, survive plugin updates, and are available to every
future client immediately. A site playbook with the same slug as a bundled one
shadows it, so an agent can override a shipped playbook without losing the original.
`list-skills` reports `source` as `bundled` or `site`.

An existing install that edited playbooks inside the plugin folder has them moved to
`wp-content/wpmcp-skills/` once, on the first request after upgrading. The move uses
`skills/manifest.json` checksums to tell a shipped file from an edited one.

Search has a relevance floor. A hit must match at least half of the query terms, and
results are ranked by a term-weighted score rather than by any single word. If the
query names something no playbook mentions — LearnPress, SeedProd, a plugin this
library does not cover — `search-skills` returns no results, lists the uncovered
term, and tells the agent to continue with the core abilities and save what it
learns with `skill-write`. It no longer offers loosely related playbooks that look
like an answer.

## Safety model

| Control | Behaviour |
| --- | --- |
| Capability | Every ability calls `permission_callback` with `manage_options` |
| Read-only mode | `WPMCP_READONLY` or the `wpmcp_readonly` filter disables every write path, including WP-CLI |
| Confirm gates | Delete widget, set globals, rollback, file delete, restore/prune, skill delete require `confirm: true` |
| Path guard | `wpmcp/files` cannot leave `wp-content`; `..` and symlink escapes are rejected |
| URL guard | Fetching, media import and audits refuse loopback, private, link-local and reserved addresses; redirects are re-validated |
| Backups | Every file write/delete and every Elementor write keeps a rollback copy |
| Atomic writes | Temp file + `rename()`; Elementor writes are read back before success is reported |
| Audit log | Last 200 `wpmcp/*` calls in a non-autoloaded option, secret-like keys redacted |

### Configuration

| Constant / filter | Default | Effect |
| --- | --- | --- |
| `WPMCP_READONLY` | `false` | Disable all writes |
| `wpmcp_readonly` filter | constant value | Dynamic read-only switch |
| `WPMCP_ENABLE_WPCLI` | `false` | Register the WP-CLI ability (shell-level access) |
| `wpmcp_enable_wpcli` filter | constant value | Dynamic WP-CLI switch |
| `wpmcp_allow_private_urls` filter | `false` | Allow fetching private/internal URLs (local development) |
| `WPMCP_PAGESPEED_API_KEY` | empty | Google PageSpeed API key |
| `wpmcp_pagespeed_api_key` filter | option value | Override the key at runtime |
| `wpmcp_extra_tool_namespaces` filter | `['elementor/', 'core/']` | Extra ability namespaces on the server |
| `WPMCP_REMOVE_DATA` | `false` | Let `uninstall.php` delete the plugin's options |

Read-only mode for a live site:

```php
define( 'WPMCP_READONLY', true );
```

Enable WP-CLI on a site where the agent is expected to do real work:

```php
define( 'WPMCP_ENABLE_WPCLI', true );
```

## Security warning

This plugin is **administrator-level by design**. An application password for an
administrator can already do almost anything through WordPress; this plugin makes
that power explicit and scriptable:

- `wpmcp/wpcli`, when enabled, runs the real `wp` binary through a shell. `wp eval`
  is PHP execution. It is off by default for exactly this reason.
- `wpmcp/files` can write PHP files under `wp-content`.
- `wpmcp/rest-request` can install and activate plugins.

Treat the application password like an SSH key:

1. Use a dedicated administrator account, not your personal one.
2. HTTPS only; never expose the endpoint over plain HTTP.
3. Restrict `/wp-json/mcp/*` by IP or basic auth at the edge where possible.
4. Use `WPMCP_READONLY` for agents that only inspect.
5. Revoke the application password when a client is retired.
6. Review `wpmcp_audit_log` after agent sessions.

This is not a sandbox and it is not malware-safe. Only connect clients you trust.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| 401 | Application password wrong, or the user is not an administrator |
| 404 on the endpoint | `mcp-adapter` inactive, or permalinks need the `?rest_route=` form |
| `tools/list` 403 behind a WAF | Response inspection on very large tool lists; reduce `wpmcp_extra_tool_namespaces` |
| `proc_open is disabled` | Host disables it; use `wpmcp/rest-request` instead |
| `wpmcp-wpcli` missing from `tools/list` | WP-CLI is opt-in: add `define( 'WPMCP_ENABLE_WPCLI', true );` |
| `wp binary not found` | WP-CLI is not installed; the rest of the plugin still works |
| `Refusing to fetch … private or reserved address` | SSRF guard; use the `wpmcp_allow_private_urls` filter only for local development |
| PageSpeed returns 429 | No API key, shared anonymous quota; add `WPMCP_PAGESPEED_API_KEY` |
| Elementor add-widget rejects `e-*` | Atomic elements are not enabled on that site; use classic widgets |

## Development

```bash
composer install
composer lint     # php -l on every PHP file
composer phpcs    # WordPress coding standards
```

The plugin is a single bootstrap file plus `skills/`. Contributions are welcome;
see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
