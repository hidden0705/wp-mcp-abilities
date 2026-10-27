---
title: Elementor Kit & Global Styles
category: Elementor
summary: Change site-wide colors, typography, spacing and layout through the Elementor kit — and understand how 4.x Variables and Classes relate to the classic kit.
updated: 2026-10-26
---

# Elementor Kit & Global Styles

## How the kit works

- Active kit is a post of type `elementor_library`, sub-type `kit`.
- Its ID is in option `elementor_active_kit`.
- Global settings are in kit meta `_elementor_page_settings` (JSON).
- Generated CSS: `uploads/elementor/css/post-<KIT_ID>.css`.

```
wp option get elementor_active_kit
wp post meta get <KIT_ID> _elementor_page_settings --format=json
```

## Classic kit keys

| Key | Meaning |
| --- | --- |
| `system_colors` | `[{_id,title,color}]` primary, secondary, text, accent |
| `custom_colors` | user palette, same shape |
| `system_typography` | `[{_id,title,typography_*}]` |
| `custom_typography` | user text styles |
| `container_width` | `{unit,size}` |
| `space_between_widgets` | default gap |
| `site_name`, `site_description` | used by some widgets |

## Elementor 4.x Variables and Classes

Atomic Elementor adds:

- **Variables** — named values (color, font, spacing, size) reusable across elements.
- **Classes** — named style sets applied to many elements, like CSS utility classes managed in the UI.
- **Components** — reusable element groups with instance overrides.

They are stored in the kit/global data as well, but the exact keys are version-specific. Do not hand-edit them blind: read the current `_elementor_page_settings` and copy the structure of an existing variable/class rather than inventing one.

## Set the brand palette (classic)

```
wp eval '
$kit = (int) get_option( "elementor_active_kit" );
$s = get_post_meta( $kit, "_elementor_page_settings", true );
if ( ! is_array( $s ) ) { $s = array(); }
$s["system_colors"] = array(
  array( "_id" => "primary",   "title" => "Primary",   "color" => "#0F172A" ),
  array( "_id" => "secondary", "title" => "Secondary", "color" => "#1E293B" ),
  array( "_id" => "text",      "title" => "Text",      "color" => "#334155" ),
  array( "_id" => "accent",    "title" => "Accent",    "color" => "#E11D48" ),
);
update_post_meta( $kit, "_elementor_page_settings", wp_slash( $s ) );
delete_post_meta( $kit, "_elementor_css" );
echo "kit updated";
'
```

Preserve keys you are not changing: read the array, modify one key, write it back.

## Set the body font

```
wp eval '
$kit = (int) get_option( "elementor_active_kit" );
$s = get_post_meta( $kit, "_elementor_page_settings", true );
foreach ( (array) ( $s["system_typography"] ?? array() ) as &$t ) {
  if ( ( $t["_id"] ?? "" ) === "primary" ) {
    $t["typography_font_family"] = "Inter";
    $t["typography_font_weight"] = "400";
    $t["typography_font_size"]   = array( "unit" => "px", "size" => 17 );
  }
}
unset( $t );
update_post_meta( $kit, "_elementor_page_settings", wp_slash( $s ) );
delete_post_meta( $kit, "_elementor_css" );
'
```

Fonts must be available: Google Fonts enabled, or uploaded via Elementor → Custom Fonts. A missing family silently falls back.

## Refresh after kit changes

```
wp elementor flush-css
wp cache flush
wp litespeed-purge all
```

Check the kit CSS file's mtime changes. If not, the meta did not save or `elementor_active_kit` points elsewhere.

## Kit vs theme vs block styles

| Layer | Controls |
| --- | --- |
| Elementor kit | Elementor pages/widgets (global colors, fonts) |
| Theme (OceanWP) | header/footer/typography outside Elementor, blog layout |
| block theme `theme.json` | block editor styles (not used on this site) |

Editing the kit does not change OceanWP's non-Elementor areas, and vice versa. Pick the layer that owns the page.

## Global Classes (3.10+)

Stored separately from the kit. If a class is renamed or deleted, every element using it loses those styles. Before editing:

1. Search `_elementor_data` for the class ID across posts.
2. Change the class definition, not each element.
3. Purge CSS and verify two pages that use it.

## Site identity

```
wp option get blogname
wp option get blogdescription
```

Set both options and the kit's `site_name` when rebranding.

## Gotchas

- The kit is a normal post; do not delete it. Note the ID before bulk operations.
- Editing the kit affects every page. For a one-page campaign, use local styles.
- A child theme palette does not override the kit.
- Kit CSS is per post ID and cached; a stale file is the usual "colors did not change".
- Elementor 4 atomic Variables do not retroactively change classic widgets; classic widgets use the kit's system colors until migrated.
- Importing a kit template can overwrite custom colors — export the current settings first.
