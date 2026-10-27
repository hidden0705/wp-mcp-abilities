---
title: Elementor Theme Builder
category: Elementor
summary: Manage headers, footers, single/archive templates and display conditions — where conditions live, how to edit them safely, and how to debug a template that will not apply.
updated: 2026-10-26
---

# Elementor Theme Builder

Theme Builder templates are `elementor_library` posts with `_elementor_template_type` and rules in `_elementor_conditions`.

## Template types

| Type | Applies to |
| --- | --- |
| `header` | site header |
| `footer` | site footer |
| `single` | single post/page/product |
| `single-post`, `single-page`, `single-product` | specific post type |
| `archive` | category/tag/shop archive |
| `product-archive` | shop and product categories |
| `search-results`, `404` | special pages |
| `popup` | popups (separate system) |
| `loop-item` | Loop Grid item template |

## List templates

```
wp post list --post_type=elementor_library --fields=ID,post_title,post_status --format=json
wp eval '
$ids = get_posts( array( "post_type" => "elementor_library", "posts_per_page" => -1, "fields" => "ids" ) );
$out = array();
foreach ( $ids as $id ) {
  $out[] = array(
    "id" => $id,
    "title" => get_the_title( $id ),
    "type" => get_post_meta( $id, "_elementor_template_type", true ),
    "conditions" => get_post_meta( $id, "_elementor_conditions", true ),
    "status" => get_post_status( $id ),
  );
}
echo json_encode( $out );
'
```

## Conditions

Stored as an array of strings like:

```
[ "include/general", "exclude/singular/page" ]
```

Common values: `include/general`, `include/archive`, `include/singular`, `include/singular/product`, `include/product_archive`, `exclude/...`.

Set conditions by writing the meta (after reading the current value):

```
wp eval '
$id = 456;
update_post_meta( $id, "_elementor_conditions", array( "include/singular/product" ) );
echo "set";
'
```

But prefer the editor for condition changes: Elementor validates conflicts and regenerates CSS.

## Creating a header template programmatically

```
wp eval '
$id = wp_insert_post( array(
  "post_type"   => "elementor_library",
  "post_title"  => "Site Header",
  "post_status" => "publish",
) );
update_post_meta( $id, "_elementor_template_type", "header" );
update_post_meta( $id, "_elementor_edit_mode", "builder" );
update_post_meta( $id, "_elementor_conditions", array( "include/general" ) );
update_post_meta( $id, "_elementor_data", wp_slash( wp_json_encode( array(
  array(
    "id" => substr( md5( (string) $id ), 0, 8 ),
    "elType" => "container",
    "settings" => array( "flex_direction" => "row", "padding" => array( "unit" => "px", "top" => "16", "right" => "24", "bottom" => "16", "left" => "24" ) ),
    "elements" => array(),
  ),
) ) ) );
delete_post_meta( $id, "_elementor_css" );
echo $id;
'
```

Then open it in the editor to add widgets; hand-building complex layouts is error-prone.

## How a template wins

1. Elementor loads all published templates of the needed type.
2. Conditions are evaluated; a more specific condition beats `include/general`.
3. Conflicts (two headers including general) resolve by the most recently edited, which is why a "new header not showing" is often an older header still including general.

To force one template:

```
wp eval '
$templates = get_posts( array( "post_type" => "elementor_library", "posts_per_page" => -1, "fields" => "ids", "meta_key" => "_elementor_template_type", "meta_value" => "header" ) );
foreach ( $templates as $t ) {
  if ( $t !== 456 ) { update_post_meta( $t, "_elementor_conditions", array( "exclude/general" ) ); }
}
'
```

## Theme Builder vs OceanWP header

If OceanWP's own header is active, an Elementor header may not render. Check OceanWP → Header settings and whether the page uses Elementor Canvas/Full Width. One header system at a time.

## Popups

Popups are `elementor_library` posts with `_elementor_template_type = popup`, plus:

- `_elementor_conditions` for where they appear
- trigger settings (on load, on scroll, on exit, after delay)
- `_elementor_popup_display_settings`

Do not show a popup before consent or before the page is interactive; it hurts INP and compliance.

## Cache after template changes

```
wp elementor flush-css
wp cache flush
wp litespeed-purge all
```

Template CSS is generated per template post and per page that uses it; a purge is required for both.

## Debugging "template not applying"

1. Is the template published, not draft?
2. Is `_elementor_template_type` correct?
3. Are conditions set and non-conflicting?
4. Is another template more specific?
5. Is the theme overriding it (OceanWP header/footer enabled)?
6. Is a page cache serving the old layout?
7. Is Elementor's "Theme Builder" experiment disabled?

## Gotchas

- Deleting a template does not remove its conditions from other posts; re-check the list.
- Editing a header template affects every page — test on staging or during low traffic.
- Conditions are serialized; writing a string instead of an array breaks evaluation.
- A `single` template with `include/general` overrides the theme's single template for every post type.
- Popups count as templates in the library list; do not delete them accidentally.
- Elementor 4 atomic elements work in Theme Builder templates too, but mixing atomic and classic in one template is fragile.
