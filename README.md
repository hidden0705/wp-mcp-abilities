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
| WP-CLI | The real `wp` binary as shell-level admin (pipes allowed), 90 s timeout |
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
- WP-CLI is optional; `wpmcp/wpcli` reports a clear error when the binary is missing

## Installation

1. Install and activate `mcp-adapter`.
2. Upload `wp-mcp-abilities.zip` under **Plugins → Add New → Upload Plugin**, or copy
   the `wp-mcp-abilities/` folder to `wp-content/plugins/`.
3. Activate **WP MCP Abilities**.
4. Create an application password under **Users → Profile → Application Passwords**.
5. Point your MCP client at:

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

All 14 abilities require `manage_options`. Names map to MCP tools by replacing `/`
with `-`.

| Ability | MCP tool | Purpose | Writes |
| --- | --- | --- | --- |
| `wpmcp/agent-context` | `wpmcp-agent-context` | Site profile, stack, ability catalog, skill index, warnings. Start here. | — |
| `wpmcp/rest-request` | `wpmcp-rest-request` | Any REST route, as the current user | yes |
| `wpmcp/wpcli` | `wpmcp-wpcli` | WP-CLI through the real binary | yes |
| `wpmcp/files` | `wpmcp-files` | `wp-content` file CRUD + search, with backups | yes |
| `wpmcp/elementor` | `wpmcp-elementor` | Safe Elementor builder, 16 actions | yes |
| `wpmcp/fetch-page` | `wpmcp-fetch-page` | Rendered HTML of any URL | — |
| `wpmcp/media-import` | `wpmcp-media-import` | Sideload a file into the Media Library | yes |
| `wpmcp/audit-url` | `wpmcp-audit-url` | PageSpeed Insights, local fallback metrics | — |
| `wpmcp/list-skills` | `wpmcp-list-skills` | Skill index, filter by category/keyword | — |
| `wpmcp/get-skill` | `wpmcp-get-skill` | Full markdown playbook for one slug | — |
| `wpmcp/search-skills` | `wpmcp-search-skills` | Full-text search across playbooks | — |
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
reads the layout back, deletes the post CSS and purges the page. A failed
read-back restores the previous layout automatically.

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

New playbooks can be written by the agent itself with `wpmcp/skill-write` and are
available to every future client immediately.

## Safety model

| Control | Behaviour |
| --- | --- |
| Capability | Every ability calls `permission_callback` with `manage_options` |
| Read-only mode | `WPMCP_READONLY` or the `wpmcp_readonly` filter disables every write path, including WP-CLI |
| Confirm gates | Delete widget, set globals, rollback, file delete, restore/prune, skill delete require `confirm: true` |
| Path guard | `wpmcp/files` cannot leave `wp-content`; `..` and symlink escapes are rejected |
| Backups | Every file write/delete and every Elementor write keeps a rollback copy |
| Atomic writes | Temp file + `rename()`; Elementor writes are read back before success is reported |
| Audit log | Last 200 `wpmcp/*` calls in a non-autoloaded option, secret-like keys redacted |

### Configuration

| Constant / filter | Default | Effect |
| --- | --- | --- |
| `WPMCP_READONLY` | `false` | Disable all writes |
| `wpmcp_readonly` filter | constant value | Dynamic read-only switch |
| `WPMCP_PAGESPEED_API_KEY` | empty | Google PageSpeed API key |
| `wpmcp_pagespeed_api_key` filter | option value | Override the key at runtime |
| `wpmcp_extra_tool_namespaces` filter | `['elementor/', 'core/']` | Extra ability namespaces on the server |
| `WPMCP_REMOVE_DATA` | `false` | Let `uninstall.php` delete the plugin's options |

Read-only mode for a live site:

```php
define( 'WPMCP_READONLY', true );
```

## Security warning

This plugin is **administrator-level by design**. An application password for an
administrator can already do almost anything through WordPress; this plugin makes
that power explicit and scriptable:

- `wpmcp/wpcli` runs the real `wp` binary through a shell. `wp eval` is PHP execution.
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
| `wp binary not found` | WP-CLI is not installed; the rest of the plugin still works |
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
