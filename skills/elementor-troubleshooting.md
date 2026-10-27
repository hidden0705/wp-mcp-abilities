---
title: Elementor Troubleshooting
category: Elementor
summary: Fix the editor that will not load, the CSS that will not update, the blank page, and the version mismatch — with the safe-mode and rollback paths that actually work.
updated: 2026-10-26
---

# Elementor Troubleshooting

## First facts to collect

```
wp plugin list --status=active --format=json --fields=name,version
wp eval 'echo defined("ELEMENTOR_VERSION") ? ELEMENTOR_VERSION : "not loaded";'
wp eval 'echo defined("ELEMENTOR_PRO_VERSION") ? ELEMENTOR_PRO_VERSION : "no pro";'
wp eval 'echo WP_MEMORY_LIMIT;'
wp option get elementor_active_kit
```

Elementor 4.3.5 with PRO Elements 4.3.2 here. A mismatch between the free and Pro versions causes blank editors and missing widgets.

## Symptom → fix

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Editor infinite loading | memory, plugin conflict, loopback blocked | raise memory, safe mode, check REST loopback |
| "The preview could not be loaded" | server blocking `?elementor-preview=`, SSL mismatch | allow the preview URL, fix SSL |
| Blank page on front end | corrupted `_elementor_data` | restore backup / decode JSON |
| Styles not updating | CSS cache, file permissions | regenerate CSS, purge LiteSpeed |
| Widgets panel empty | library not synced, JS error | Elementor → Tools → Sync Library |
| "Invalid content" | block/JSON mismatch | restore revision, re-save |
| Editor works, front end 500 | memory or a widget fatal | `debug.log` |
| Icons missing | Font Awesome disabled/experiment | enable icons, clear cache |
| Changes revert after save | object cache/page cache | flush, check revisions |
| Cannot open a single template | conditions/type corrupt | fix `_elementor_template_type` |

## Safe mode

Elementor → Tools → Safe Mode (or the admin bar). It disables plugins/themes for Elementor only, without affecting visitors.

If the editor works in safe mode, reactivate plugins one by one to find the conflict. Common culprits: optimization plugins, security plugins blocking the editor REST calls, and other page builders.

## The preview request

The editor loads a preview iframe from `/?elementor-preview=<id>`. If the site blocks unauthenticated loopback or requires auth on all URLs:

- Allow `elementor-preview` in the security plugin.
- Ensure the site can reach itself over HTTPS.
- Check `WP_HOME`/`WP_SITEURL` match the actual URL.
- A staging site behind Basic auth must allow the preview path or the editor will fail.

## Regenerate CSS

```
wp elementor flush-css
wp cache flush
wp litespeed-purge all
```

If the CLI command is missing, delete the meta and files:

```
wp eval '
$ids = get_posts( array( "post_type" => "any", "posts_per_page" => -1, "fields" => "ids", "meta_key" => "_elementor_edit_mode" ) );
foreach ( $ids as $id ) { delete_post_meta( $id, "_elementor_css" ); }
echo count( $ids );
'
```

Then delete `wp-content/uploads/elementor/css/*` (keep the directory).

## Corrupted page data

```
wp eval '
$raw = get_post_meta( 123, "_elementor_data", true );
$json = json_decode( $raw, true );
echo ( null === $json ) ? "INVALID: " . json_last_error_msg() : "valid, " . count( $json ) . " top-level";
'
```

Invalid → restore from a backup or a revision. Revisions do not include `_elementor_data` by default, so the file backup is the real safety net.

## Version mismatch / rollback

```
wp plugin install elementor --version=4.2.1 --force
wp plugin install elementor-pro --version=4.2.0 --force
```

Deactivate Pro before rolling back free, then reactivate after. Keep both on the same minor line.

## Memory and limits

```
wp config set WP_MEMORY_LIMIT 256M
wp config set WP_MAX_MEMORY_LIMIT 512M
```

Elementor's editor is memory-hungry; 128 MB often fails on large pages. Also check `max_input_vars` (1000+ for big forms) and `post_max_size`.

## Library sync

Elementor → Tools → Sync Library rebuilds the local library index. Use after importing templates or when the widget panel is empty.

```
wp elementor library import wp-content/uploads/template.json
```

## When the editor is unusable

1. Export the page template.
2. Edit `_elementor_data` directly (see `elementor-editing`).
3. Verify on the front end.
4. Reopen the editor once to confirm it parses.

## Gotchas

- Safe mode is for diagnosis; leaving it on hides the real issue.
- Deleting `_elementor_css` for thousands of posts at once causes a regeneration storm; do it in batches.
- A caching plugin can cache the editor preview; exclude `elementor-preview`.
- Elementor 4 atomic pages and classic pages have different schemas; do not copy JSON between them.
- A child theme update can overwrite template overrides used by Elementor.
- If the site uses LiteSpeed "Delay JS", the editor may never boot; exclude wp-admin.
