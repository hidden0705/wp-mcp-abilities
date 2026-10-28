# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- Internationalise the remaining agent-facing descriptions.

## [2.7.1] - 2026-10-28

### Fixed

- The lazy feed sync ran on `rest_api_init`, so an unauthenticated request to the
  MCP endpoint could make the site fetch and install a feed. It now runs on
  `wp_before_execute_ability` for `wpmcp/*` abilities, after the ability's
  permission callback has passed. The 12-hour interval and the one-hour throttle
  are unchanged.

## [2.7.0] - 2026-10-28

### Added

- **Signed community skill feed.** Every install checks
  `github.com/hidden0705/wp-mcp-skills` twice a day and installs only playbooks
  whose manifest signature, sha256 and byte count all verify. The manifest is
  signed with an offline RSA-3072 key; only the public key ships in the plugin.
  Replaced and removed playbooks are backed up before the change, so
  `wpmcp/restore` can undo a bad sync.
- `wpmcp/skill-sync` with `action=status`, `check` and `apply`, plus per-feed
  state (last check, last apply, installed count, last error) in `agent-context`.
- Precedence `site` > team feed > `community` > `bundled`. A site playbook always
  wins; a feed cannot replace a bundled slug unless its manifest entry says
  `override: true`.
- `WPMCP_SKILLS_FEED` kill switch and the `wpmcp_skill_feeds` filter for private
  or team feeds. An unsigned feed is refused unless the site opts in per feed.
- Tombstones: a playbook removed upstream is deleted from installs, not left
  behind as a stale instruction.
- `wp wpmcp export --author=<handle>` turns site-written playbooks into PR-ready
  files for the community repository: it writes the expected front matter, maps
  the site domain, emails and absolute paths to `example.com` /
  `/path/to/wordpress/...`, skips files that look like they contain a secret, and
  refuses to share a bundled slug without `--allow-override`.

### Changed

- `wpmcp/list-skills` reports feed playbooks with their `feed`, `author`,
  `version` and `updated` fields, and resolves the winning source.
- `agent-context` guardrails warn that community playbooks come from other sites
  and must not be followed blindly.

## [2.6.0] - 2026-10-28

### Fixed

- `wpmcp/search-skills` returned any playbook that matched one word, so a query
  naming a plugin the library does not cover ("seedprod coming soon page") listed
  Elementor playbooks scoring 36 — they matched "page" and nothing else. That reads
  as an answer to an agent that cannot see the ranking code. A hit now has to match
  at least half of the query terms, terms are weighted by rarity, and the slug and
  title outrank passing mentions in a long body. A query term that appears in no
  playbook returns no results, names the uncovered term, and explains what to do
  next instead of offering the nearest neighbour.
- `search-skills` results now carry `matched` and `coverage`, and an empty search
  carries a `hint`.

### Changed

- `agent-context` guardrails now say what to do when no playbook matches: continue
  with the core abilities, back up first, verify, and save the lesson with
  `wpmcp/skill-write` rather than improvising from a near match.

## [2.5.0] - 2026-10-27

### Changed

- **`wpmcp/wpcli` is opt-in.** It runs the real `wp` binary through a shell, so a
  leaked application password meant remote code execution on every install. The
  ability is no longer registered unless the site sets
  `define( 'WPMCP_ENABLE_WPCLI', true )` in `wp-config.php` (or returns true from
  the `wpmcp_enable_wpcli` filter). An existing install that relied on WP-CLI must
  add the constant; `agent-context` now reports the state and warns when it is off.
- **Agent-written playbooks move to `wp-content/wpmcp-skills/`.** A plugin update
  used to replace the plugin folder and delete every playbook an agent had written.
  Bundled playbooks stay in `skills/` (read-only); site playbooks live in
  `wp-content/wpmcp-skills/` and shadow a bundled playbook with the same slug.
  Existing edits in the plugin folder are migrated once per version, using
  `skills/manifest.json` checksums to tell a shipped file from an edited one.
- **Elementor cache invalidation is per post.** Every widget edit called
  Elementor's site-wide `clear_cache()`, so one heading change rebuilt the CSS of
  every page. Only the edited post's stylesheet is deleted and regenerated now;
  site-wide flushing is reserved for kit globals.

### Added

- **SSRF guard** for `wpmcp/fetch-page`, `wpmcp/media-import` and
  `wpmcp/audit-url`: loopback, private, link-local, CGNAT and reserved addresses
  are refused, and redirects are re-validated by WordPress. The site's own host is
  always allowed (many hosts resolve their domain privately behind a proxy), and
  local/staging can opt out with the `wpmcp_allow_private_urls` filter.
- `wpmcp/media-import` enforces its documented 25 MB limit and falls back to a
  timestamped filename when the URL has no basename.
- `create-page` validates `elements`: every node needs an `elType`, ids are made
  unique, and loose top-level widgets are wrapped in a section > column instead of
  writing a document Elementor cannot open.
- Text domain loading from `languages/`, so a translation can be dropped in
  without a build step.
- `source` (`bundled` or `site`) in `list-skills` and `agent-context` output.

### Fixed

- `wpmcp_el_merge` could not clear a list (an empty array was a no-op) and treated
  an empty `_id` as a real id. Empty lists now clear, empty objects keep maps, and
  id-less items replace the list as documented.
- `wpmcp/wpcli` input schema said "no pipes or redirects" while the description and
  the implementation allow both.

## [2.4.0] - 2026-10-26

### Added

- `elementor/*` and `core/*` abilities can be surfaced as direct MCP tools through the
  `wpmcp_extra_tool_namespaces` filter. A site running Elementor's MCP plugin exposes
  22 Elementor tools and 3 core info tools alongside the 14 bundled ones.
- Abilities annotated `mcp.type: resource` are registered in `resources/list` instead
  of `tools/list`, matching the adapter contract.
- The `elementor-editing` playbook documents Elementor's own MCP tools.

### Changed

- Plugin header metadata is ready for public distribution: `Requires at least`,
  `Requires Plugins`, `Text Domain`, `Domain Path`, `License URI`, `Update URI`.
- Public README, `readme.txt`, changelog, security policy and contribution guide.

### Fixed

- The plugin header reported `2.3.0` while `WPMCP_VERSION` was `2.4.0`.
- Header description no longer claims that deleting the plugin preserves skill files.

## Earlier versions

Versions 1.0.0 through 2.3.0 were internal releases and were not publicly distributed.
They introduced the abilities API integration, the WP-CLI runner, the `wp-content` file
manager with rollback, the Elementor super-ability, site health, the skills library and
the audit log.
