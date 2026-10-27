---
title: Block Themes & theme.json
category: Core
summary: Work with full site editing — theme.json tokens, templates, parts, patterns, global styles, and the limits of editing a block theme from an agent.
updated: 2026-10-26
---

# Block Themes & theme.json

A block theme is PHP-light: templates are HTML, styles are JSON, and the Site Editor owns layout. OceanWP Child is a classic theme, so this skill matters mainly when evaluating or building a new theme.

## Is the site a block theme?

```
wp eval 'echo wp_is_block_theme() ? "block" : "classic";'
wp theme list --format=json
```

## Anatomy

```
theme/
  style.css
  theme.json
  templates/
    index.html
    single.html
    page.html
    archive.html
    404.html
  parts/
    header.html
    footer.html
  patterns/
    hero.php
  functions.php
```

`templates/*.html` are block markup; `parts/*.html` are reusable sections referenced by `<!-- wp:template-part {"slug":"header"} /-->`.

## theme.json structure

```json
{
  "$schema": "https://schemas.wp.org/trunk/theme.json",
  "version": 3,
  "settings": {
    "color": { "palette": [ { "slug": "brand", "color": "#0F172A", "name": "Brand" } ] },
    "typography": { "fontFamilies": [ { "slug": "body", "name": "Body", "fontFamily": "Inter, sans-serif" } ] },
    "layout": { "contentSize": "760px", "wideSize": "1200px" },
    "spacing": { "units": [ "px", "rem", "%", "vw" ] }
  },
  "styles": {
    "color": { "background": "#ffffff", "text": "#111827" },
    "typography": { "fontFamily": "var(--wp--preset--font-family--body)", "fontSize": "17px" },
    "elements": { "link": { "color": { "text": "var(--wp--preset--color--brand)" } } }
  },
  "customTemplates": [ { "name": "landing", "title": "Landing", "postTypes": [ "page" ] } ]
}
```

Version 3 is current for WP 6.6+. `settings` defines what the UI may use; `styles` applies defaults.

## Global styles are stored in the DB

The Site Editor writes user changes to the `wp_global_styles` post for the theme, not to `theme.json`:

```
wp post list --post_type=wp_global_styles --fields=ID,post_title,post_status --format=json
wp post get <ID> --field=post_content
```

`theme.json` is the baseline; the global styles post overrides it. Editing `theme.json` alone will not change a value the user already customised.

## Templates and template parts

```
wp post list --post_type=wp_template --fields=ID,post_name,post_status --format=json
wp post list --post_type=wp_template_part --fields=ID,post_name,post_status --format=json
```

Templates are auto-created from theme files when customised (status `publish`). Delete the DB copy to fall back to the file:

```
wp post delete <ID>
```

## Editing template HTML safely

A template is block markup with no PHP. Keep it valid:

```html
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
  <!-- wp:post-title {"level":1} /-->
  <!-- wp:post-content /-->
</div>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
```

Invalid JSON in the comment breaks the template editor. Small edits, then reload the Site Editor.

## theme.json and Elementor

Elementor does not read `theme.json` for its styling. On a block theme, Elementor pages still use the kit (see `elementor-kit`). Do not try to unify them by copying tokens; pick one source of truth per page.

## Fonts

- Block themes: declare in `theme.json` `fontFamilies`, or use the Font Library (uploads fonts to `wp-content/fonts/`).
- Classic/Elementor: Google Fonts or Elementor custom fonts.
- Self-host when possible; third-party Google Fonts requests are a GDPR issue in the EU.

## Patterns

```php
// patterns/hero.php
<?php
/**
 * Title: Hero
 * Slug: qadri/hero
 * Categories: featured
 */
?>
<!-- wp:cover {"minHeight":420} -->
<div class="wp-block-cover" style="min-height:420px">
  <div class="wp-block-cover__inner-container">
    <!-- wp:heading {"level":1} -->
    <h1 class="wp-block-heading">Built in India</h1>
    <!-- /wp:heading -->
  </div>
</div>
<!-- /wp:cover -->
```

Patterns appear in the inserter and are editable per page (not synced).

## Build vs edit decision

| Task | Do |
| --- | --- |
| New marketing page | build with blocks/patterns, not PHP |
| Change global colour | Site Editor → Styles (or kit if Elementor page) |
| New header layout | edit `parts/header.html` or the Site Editor |
| New template for a CPT | `templates/single-<cpt>.html` |
| Complex dynamic query | block + `core/query`, not custom PHP |

## Gotchas

- `theme.json` changes are cached in the browser and in the Site Editor; hard reload after saving.
- Global styles post is per theme; switching themes does not carry your customisations.
- `version` mismatch (2 vs 3) changes which settings keys are honoured.
- Block themes ignore `header.php` / `footer.php`; a child theme with those files will not override a block theme's parts.
- OceanWP (classic) cannot use `templates/*.html`; use its hooks and child theme instead.
- Exporting a block theme with the Site Editor includes DB customisations; a ZIP of the theme folder alone does not.
