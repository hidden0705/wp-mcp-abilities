## What changed

<!-- One or two sentences. Link the issue if there is one. -->

## Type

- [ ] Bug fix
- [ ] New ability or action
- [ ] Skill playbook
- [ ] Documentation / packaging
- [ ] Refactor (no behaviour change)

## Safety review

- [ ] Every new write path checks `wpmcp_is_readonly()`.
- [ ] Destructive actions require `confirm: true`.
- [ ] A rollback copy is kept before any file or layout change.
- [ ] Paths stay inside `wp-content`; URLs are validated.
- [ ] No credentials, tokens, dumps, ZIPs or personal data in the diff.

## Testing

<!-- Exact steps, ability names, inputs, expected and actual output. -->

- [ ] `php -l` clean on every changed PHP file.
- [ ] Tested on a disposable site, not production.
- [ ] For Elementor changes: throwaway draft, layout survived read-back.

## Checklist

- [ ] `CHANGELOG.md` updated under `## [Unreleased]`.
- [ ] Documentation updated (README, readme.txt, skill) where behaviour changed.
- [ ] New strings use the `wp-mcp-abilities` text domain.
