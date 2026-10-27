---
title: MCP Site Operations
category: Operations
summary: How an agent should drive this site over MCP: orientation, backups, narrowest tool, verification, rollback, read-only mode.
---

# MCP Site Operations

## When to use this

You are an AI agent connected to this site over MCP and about to change something.

## Order of calls

1. `wpmcp/agent-context` — site profile, read-only state, warnings, skill index.
2. `wpmcp/get-skill` for the domain playbook (elementor-editing, woocommerce-products, ...).
3. `wpmcp/wpcli` `db export` before schema, plugin, bulk content or search-replace work.
4. Make the change with the narrowest tool: `rest-request` for CRUD, `wpcli` for menus/widgets/options/transients, `files` for code.
5. `wpmcp/fetch-page` the affected URL, then `wpcli` `litespeed-purge all`.
6. If a file edit was wrong: `wpmcp/restore` action=`list` then `restore`.

## Safety rules

- `files` write and delete keep rollback copies in `wp-content/wpmcp-backups`; delete needs `confirm: true`.
- Destructive WP-CLI commands (`db drop`, `site empty`, `post delete`, `plugin delete`) need `confirm: true`.
- Read-only mode (`WPMCP_READONLY` in wp-config.php) refuses every write path. Check `agent-context` before assuming a write will work.
- Every ability call is recorded in the non-autoloaded option `wpmcp_audit_log` (last 200, secrets redacted).
- Never print application passwords or tokens. The MCP transport already authenticates.

## Traps on this stack

- Plain permalinks: REST lives at `index.php?rest_route=...`, never `/wp-json/...`.
- Elementor stores layout JSON in `_elementor_data`; change it with a PHP unserialize/serialize round trip, never string replace.
- LiteSpeed caches aggressively: purge after content, CSS or plugin changes or you will verify a stale page.
- The host WAF rejects request bodies containing `../`; use paths without traversal.

## Verify

Re-read the skill after editing it.
