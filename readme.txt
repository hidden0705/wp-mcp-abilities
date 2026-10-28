=== WP MCP Abilities ===
Contributors: hidden0705
Tags: mcp, ai, wp-cli, elementor, woocommerce
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Administrator-level MCP abilities for WordPress: REST, files with rollback, Elementor builder, site health, optional WP-CLI, and 46 skill playbooks.

== Description ==

WP MCP Abilities extends the official `mcp-adapter` plugin with 14 administrator-level
abilities, each registered as a first-class MCP tool with its full input schema. An AI
client calls a tool once instead of walking the adapter's discover → info → execute
chain. There is no relay service and no call limit: the client talks straight to your
site over HTTPS.

The plugin is built for agents that do real work on a site, so every write path has a
safety rail: capability checks, confirm gates, path containment, atomic writes,
rollback copies, Elementor snapshots and an audit log.

= Included abilities =

* **Agent context** – site profile, stack, ability catalog, skill index and warnings.
* **REST request** – any WordPress or WooCommerce REST route as the current user.
* **WP-CLI** – the real `wp` binary, pipelines allowed, 90 second timeout. Off by
default: enable it with `define( 'WPMCP_ENABLE_WPCLI', true );`.
* **Files** – list, read, write, delete and search under `wp-content`, with backups.
* **Elementor builder** – one super-ability with 16 actions instead of 200+ tools.
* **Fetch page** – rendered HTML for verification after edits.
* **Media import** – sideload a file from a URL into the Media Library.
* **PageSpeed audit** – Google PageSpeed Insights, with a local fallback.
* **Skills** – list, get, search and write 46 WordPress/WooCommerce/Elementor playbooks.
* **Site health** – scored diagnostics across six sections.
* **Restore** – list, restore and prune the automatic rollback copies.

= Third-party abilities =

Sites running Elementor's own MCP plugin also get its 22 `elementor/*` abilities and
3 `core/*` info abilities on the same endpoint. A filter controls which namespaces are
surfaced:

`add_filter( 'wpmcp_extra_tool_namespaces', function ( $namespaces ) { ... } );`

Abilities annotated as `mcp.type: resource` are exposed in `resources/list`, not
`tools/list`.

= Safety =

* Every ability requires `manage_options`.
* `WPMCP_READONLY` (or the `wpmcp_readonly` filter) disables every write path.
* Destructive actions require `confirm: true`.
* File paths cannot escape `wp-content`.
* Every write keeps a rollback copy; Elementor writes are read back and reverted if
  the layout did not survive.
* The last 200 ability calls are logged with secret-like keys redacted.

**Security warning:** this plugin is administrator-level by design. `wp eval` is PHP
execution, `wpmcp/files` can write PHP, and `wpmcp/rest-request` can install plugins.
Use a dedicated administrator account, HTTPS, and a scoped application password.
Read-only mode is available for inspection-only agents.

== Installation ==

1. Install and activate the `mcp-adapter` plugin.
2. Upload the `wp-mcp-abilities` folder to `/wp-content/plugins/`, or install the ZIP
   through Plugins → Add New → Upload Plugin.
3. Activate **WP MCP Abilities**.
4. Create an application password under Users → Profile.
5. Connect your MCP client to `https://example.com/wp-json/mcp/mcp-adapter-default-server`
   (or `?rest_route=/mcp/mcp-adapter-default-server/` with plain permalinks) using
   HTTP Basic auth.

== Frequently Asked Questions ==

= Does this replace mcp-adapter? =

No. `mcp-adapter` provides the MCP transport, authentication and server. This plugin
registers abilities on that server and, optionally, exposes them as direct tools.

= Does it work without WP-CLI? =

Yes. WP-CLI is opt-in: without `define( 'WPMCP_ENABLE_WPCLI', true );` the
`wpmcp/wpcli` ability is not registered at all. The other abilities work normally.

= Where do agent-written skills go? =

`wp-content/wpmcp-skills/`, so a plugin update cannot delete them. A site playbook
with the same slug as a bundled one shadows it. Files that older versions wrote into
the plugin folder are migrated there once per version.

= Where are the backups? =

Under `wp-content/wpmcp-backups/`. They are never deleted by the uninstaller. The
directory is protected with `.htaccess` on Apache/LiteSpeed; on Nginx add a deny rule
for that path.

= Can I turn off writes? =

Yes. Add `define( 'WPMCP_READONLY', true );` to `wp-config.php`, or return true from
the `wpmcp_readonly` filter.

= Is this on WordPress.org? =

No. The plugin grants administrator-level code execution by design, which is outside
the WordPress.org plugin directory guidelines. Releases are distributed from the
project repository.

== Screenshots ==

None. The plugin has no admin screens; it is configured with constants and filters.

== Changelog ==

= 2.6.0 =
* Fixed: `search-skills` no longer returns loosely related playbooks. A hit must
  match at least half the query terms, and a plugin the library does not cover
  returns no results plus a hint naming it.
* Changed: `agent-context` tells the agent what to do when no playbook matches.

= 2.5.0 =
* Changed: `wpmcp/wpcli` is opt-in. Add `define( 'WPMCP_ENABLE_WPCLI', true );` to
  `wp-config.php` to keep using WP-CLI. Without it the ability is not registered.
* Changed: agent-written playbooks move to `wp-content/wpmcp-skills/`, so plugin
  updates cannot delete them. Existing edits are migrated once per version.
* Changed: Elementor cache invalidation is scoped to the edited post; only kit
  globals flush the site-wide cache.
* Added: SSRF guard on fetch-page, media-import and audit-url. Loopback, private,
  link-local and reserved addresses are refused; the site's own host is allowed and
  `wpmcp_allow_private_urls` opts out for local development.
* Added: `media-import` enforces its 25 MB limit.
* Added: `create-page` validates elements and wraps loose widgets in section > column.
* Fixed: `wpmcp_el_merge` can now clear a list and ignores empty `_id` values.

= 2.4.0 =
* Added: `elementor/*` and `core/*` abilities as direct tools through the
  `wpmcp_extra_tool_namespaces` filter (42 tools on a site running Elementor's MCP
  plugin).
* Added: `mcp.type: resource` abilities are registered in `resources/list`.
* Added: Elementor MCP tools section in the `elementor-editing` playbook.
* Fixed: plugin header version now matches `WPMCP_VERSION`.
* Changed: header metadata, license file and public documentation.

= 2.3.0 and earlier =
Internal releases, not publicly distributed.

== Upgrade Notice ==

= 2.6.0 =
Search results are stricter. No configuration change required.

= 2.5.0 =
WP-CLI is now opt-in: add `define( 'WPMCP_ENABLE_WPCLI', true );` to wp-config.php
before updating if agents rely on it. Agent-written skills move to wp-content and are
migrated automatically.
