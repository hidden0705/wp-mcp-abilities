# Contributing to WP MCP Abilities

Thanks for taking the time to contribute. This project deals with administrator-level
WordPress operations, so small mistakes have large consequences. Please read this
guide before opening a pull request.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating
you agree to uphold it.

## Requirements

- PHP 8.0+
- WordPress 6.9+ with `mcp-adapter` 0.7+
- Composer (optional, for coding standards)
- A disposable WordPress site for testing. **Never test write abilities on a live site.**

## Local setup

```bash
git clone https://github.com/hidden0705/wp-mcp-abilities.git
cd wp-mcp-abilities
composer install
composer lint
composer phpcs
```

Copy the folder to `wp-content/plugins/wp-mcp-abilities/` on a test site, activate
`mcp-adapter` and then this plugin.

## Coding standards

- WordPress Coding Standards, tabs, Yoda conditions, `snake_case` functions.
- Every global function, constant and hook is prefixed `wpmcp_` / `WPMCP_`.
- All user-facing strings must be translatable: `__()`, `esc_html__()`, `_x()` with the
  `wp-mcp-abilities` text domain.
- Escape output, sanitize input, and use `$wpdb->prepare()` for any dynamic SQL.
- No `extract()`, no `eval()` outside `wpmcp/wpcli` (which is the documented feature),
  no silencing errors with `@` unless the failure is genuinely non-fatal and handled.
- Keep the single-file bootstrap unless a pull request also updates the release ZIP
  process. The `includes/` split is planned; discuss it in an issue first.

## Abilities

When adding or changing an ability:

1. Add its name to `wpmcp_ability_names()`.
2. Register it on `wp_abilities_api_init` with `label`, `description`, `category`,
   `input_schema`, `output_schema`, `execute_callback`, `permission_callback` and
   `meta` annotations.
3. Use `$admin_gate` for the permission callback. Anything that writes must also check
   `wpmcp_is_readonly()`.
4. Destructive actions require `confirm: true` and must keep a rollback copy.
5. Anything that fetches a caller-supplied URL must go through `wpmcp_check_url()` and
   pass `reject_unsafe_urls` to `wp_remote_get()`. New shell or code-execution paths
   must be opt-in constants, like `WPMCP_ENABLE_WPCLI`.
6. Describe the tool for an agent, not for a human reading a settings page: say when to
   use it, what it returns, and what it refuses to do.
7. Add a changelog entry.

## Skills

Playbooks live in `skills/*.md` with YAML front matter. They are the read-only set
shipped with the plugin; anything an agent writes at runtime goes to
`wp-content/wpmcp-skills/` instead.

```markdown
---
title: Elementor Page Editing
category: Elementor
summary: One sentence an agent can use to decide whether to load this file.
---

# Elementor Page Editing

...
```

- Categories: Core, Operations, Growth, WooCommerce, Elementor.
- Write for an agent that has never seen the site: commands, gotchas, verification.
- Do not include credentials, tokens, personal data or a real customer's domain. Use
  `example.com`.
- Site-specific observations belong in the site's own skill library, not in the
  bundled playbooks.
- After adding, editing or deleting a bundled playbook, regenerate the checksum
  manifest or the next upgrade will mistake your file for a site edit:

  ```bash
  python3 - <<'PY'
  import hashlib, json, pathlib
  m = {p.stem: hashlib.sha256(p.read_bytes()).hexdigest()
       for p in sorted(pathlib.Path('skills').glob('*.md'))}
  pathlib.Path('skills/manifest.json').write_text(json.dumps(m, indent=2, sort_keys=True) + "\n")
  PY
  ```

## Tests

There is no automated test suite yet. Until there is one, a pull request must include:

- `php -l` clean on every PHP file.
- The exact manual test steps you ran (ability, input, expected output).
- For Elementor changes: a throwaway draft page, not a live page, and proof that the
  layout survived a read-back.

## Pull requests

- One logical change per pull request.
- Conventional Commits are welcome: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`.
- Describe the user-visible effect and any migration or rollback steps.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- Do not commit ZIPs, database dumps, `.env` files, application passwords, or backups.

## Security

Do not open a public issue for a vulnerability. Follow [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contribution is licensed under
GPL-2.0-or-later.
