---
title: Elementor Dynamic Content & Loops
category: Elementor
summary: Bind widgets to real data — dynamic tags, ACF/custom fields, Loop Grid, taxonomy archives, and the settings that make templates reusable.
updated: 2026-10-26
---

# Elementor Dynamic Content & Loops

## Dynamic tags

A dynamic tag binds a control to data instead of a static value. Stored inside the widget settings under `__dynamic__`:

```json
"settings": {
  "title": "Fallback",
  "__dynamic__": {
    "title": "[elementor-tag id=\"abc123\" name=\"post-title\" settings=\"%7B%7D\"]"
  }
}
```

The tag string is URL-encoded settings. Do not hand-write it unless you copy an existing tag and change only the name/settings.

Common tags:

| Tag | Data |
| --- | --- |
| `post-title` | current post title |
| `post-excerpt` | excerpt |
| `post-content` | content |
| `featured-image` | featured image |
| `site-title`, `site-tagline` | site identity |
| `author-name`, `author-info` | author |
| `post-date`, `post-modified` | dates |
| `taxonomy-terms` | categories/tags |
| `acf` / `meta` | custom field |
| `woocommerce-product-price` | product price |
| `woocommerce-product-rating` | rating |

## Custom fields (ACF / meta)

- ACF fields are exposed via the `acf` dynamic tag (Pro/ACF integration) or `meta` with the field key.
- For plain post meta, use the `meta` tag with key `_example_fabric`.
- Meta keys starting with `_` are hidden fields; the tag still reads them.
- If the value is empty, the widget shows the fallback — set a sensible fallback.

## Loop Grid

A Loop Grid uses a **loop item** template (`_elementor_template_type = loop-item`) and a query.

Setup:
1. Create a Loop Item template with dynamic tags (title, image, price).
2. Add a Loop Grid widget to the page.
3. Choose the loop template and the query (posts, products, taxonomy).
4. Set columns per device and pagination.

Query settings live in the widget's `settings` (`_query`, `query_*` keys). For products, use the WooCommerce query type; for custom post types, select the CPT.

```
wp eval '
$ids = get_posts( array( "post_type" => "elementor_library", "posts_per_page" => -1, "fields" => "ids", "meta_key" => "_elementor_template_type", "meta_value" => "loop-item" ) );
foreach ( $ids as $id ) { echo $id . " " . get_the_title( $id ) . "\n"; }
'
```

## Archive templates

An archive template uses the current query by default. Do not set a fixed query in an archive template unless you intend to override the archive.

- Product archive: use WooCommerce loop widgets, not a custom Loop Grid, or you lose sorting/filtering.
- Category archive: Loop Grid with "Current Query" preserves pagination.

## Reusable content

| Need | Use |
| --- | --- |
| Same block on many pages, edited centrally | Global widget / Component (4.x) |
| Same layout with different data | Loop item template |
| One-off copy | duplicate the section |
| Content shared with non-Elementor pages | synced pattern (Gutenberg) |

Global widgets are stored as `elementor_library` type `global-widget`; editing one updates all instances.

## Dynamic visibility

Pro's "Display Conditions" hide widgets based on login state, role, date, URL. They are evaluated server-side. Do not rely on them for security — a hidden widget's data can still be in the HTML if it is rendered client-side.

## ACF relationship fields

- Relationship fields return post IDs; use a Loop Grid with a query set to the relationship, or a Posts widget.
- Repeater fields need a loop over the repeater (ACF + Elementor Pro dynamic tags support varies).
- If a repeater does not render, check the ACF field name and whether the field is registered for the current post type.

## Performance rules for loops

- Limit posts per page (6–12) and use pagination/load more.
- Avoid `orderby=rand()`.
- Do not nest loops; each adds queries.
- Exclude heavy widgets (maps, sliders) from loop items.
- Cache the page; loops are server-rendered.

## Debugging dynamic output

```
wp eval '
$data = json_decode( get_post_meta( 123, "_elementor_data", true ), true );
$walk = function ( $els ) use ( &$walk ) {
  foreach ( (array) $els as $el ) {
    if ( ! empty( $el["settings"]["__dynamic__"] ) ) {
      echo ( $el["widgetType"] ?? $el["elType"] ) . ": " . json_encode( $el["settings"]["__dynamic__"] ) . "\n";
    }
    if ( ! empty( $el["elements"] ) ) { $walk( $el["elements"] ); }
  }
};
$walk( $data );
'
```

If a tag renders empty: the field is empty, the tag points at the wrong key, or the template is being rendered outside the loop context.

## Gotchas

- Dynamic tags inside a loop item resolve to the loop's current post, not the page.
- ACF field keys vs field names: the `meta` tag needs the meta key (`field_xxx` or the name depending on ACF settings).
- Loop Grid + WooCommerce variable products can show the parent price range; use the price widget, not a meta tag.
- Global widgets cannot be edited from the page that uses them; edit the source.
- Dynamic visibility does not reduce page weight; hidden content may still load.
- Elementor 4 atomic elements use a different settings schema for some tags; verify after migration.
