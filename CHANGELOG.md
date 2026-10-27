# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- Move agent-written skills out of the plugin directory so updates cannot delete them.
- Harden URL fetching (`fetch-page`, `media-import`, `audit-url`) against SSRF.
- Enforce the documented 25 MB limit in `media-import`.
- Make `wpmcp/wpcli` opt-in for public installs.
- Scope Elementor cache invalidation to the edited post.
- Internationalise user-facing strings.

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
