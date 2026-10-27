---
title: Elementor Page Editing
category: Elementor
summary: Edit Elementor pages at the data level — the 4.x atomic/classic split, _elementor_data JSON, safe mutation, cache regeneration, and verification.
---

# Elementor Page Editing

This site runs **Elementor 4.3.5** with PRO Elements 4.3.2.

## Use the ability first

The site exposes **`wpmcp-elementor`** (ability `wpmcp/elementor`), which does the
whole safe round-trip for you: decode → mutate → `wp_slash` encode → read-back
verify → `_elementor_css` delete → LiteSpeed purge, with a snapshot of the
previous `_elementor_data` before every write.

| Action | Use it for |
| --- | --- |
| `get-structure` | Nested outline with ids, elTypes, setting keys and text previews |
| `get-widget` | Full settings of one node — copy the shape from here |
| `add-widget` | Add a widget; auto-creates section+column on an empty page |
| `update-widget` | Merge settings (deep, lists merge by `_id`) or `replace: true` |
| `move-widget`, `duplicate-widget` | Reorder / copy a subtree with fresh ids |
| `delete-widget` | Needs `confirm: true`; returns the backup name |
| `create-page` | New page/post in builder mode (default draft) |
| `set-page-settings` | `template`, `hide_title`, `page_layout`, background, `custom_css` |
| `get-globals`, `set-globals` | Kit colors/typography/layout (`set` needs `confirm: true`) |
| `list-templates`, `apply-template` | Elementor library templates, inserted with fresh ids |
| `regenerate-css` | Clear Elementor CSS for one post or all |
| `snapshots`, `rollback` | Inspect and restore pre-edit layouts |

Typical edit: `get-structure` → `get-widget <id>` → `update-widget {element_id,
settings}` → `wpmcp-fetch-page` to confirm the rendered text.

### Elementor's own MCP tools (`elementor-*`)

Since plugin v2.4.0 the same endpoint also exposes Elementor's own 22 MCP
abilities as direct tools — handy for what `wpmcp-elementor` does not cover:

| Tool | Use it for |
| --- | --- |
| `elementor-get-page-structure` | Read-only outline of any document (also for posts/templates) |
| `elementor-manage-elements` | Elementor's native element CRUD |
| `elementor-manage-classes`, `elementor-reorder-classes` | Kit global CSS classes |
| `elementor-manage-global-variable`, `elementor-get-default-styles` | Global variables / default styles |
| `elementor-get-widget-schema`, `elementor-list-widget-schemas` | Exact settings schema for a widget type |
| `elementor-build-composition` | Build a whole layout from a composition payload |
| `elementor-create-preview-link`, `elementor-publish-document` | Preview URL and publish |
| `elementor-list-components`, `elementor-manage-component` | Reusable components |
| `elementor-list-site-parts`, `elementor-manage-site-parts` | Header/footer templates |
| `elementor-update-page-settings`, `elementor-create-page` | Document settings / new document |

Related read-only resources: `elementor://global-classes`,
`elementor://global-variables`, `elementor://dynamic-tags`,
`elementor://interactions/schema`, `elementor://style/best-practices`.

Do **not** point a client at Angie's own `/elementor/mcp` endpoint: it publishes
every ability on the site (89 tools) and the host WAF answers its `tools/list`
with 403. Use this server instead.


Rules the tool enforces, and you must respect:

- A classic **section holds columns only**. A widget goes in a column; if you
  pass a section, the tool fills it with a column first.
- **Atomic elements are off here** (`elementor_experiment-e_atomic_elements`).
  `e-*` types are refused with a reason — use classic widgets.
- Every write is snapshotted to `wp-content/wpmcp-backups/elementor/` (10 newest
  per post). `rollback` with `confirm: true` restores the newest, or pass a
  `backup` name from `snapshots`.

The manual procedure below is the fallback for anything the ability does not
cover (bulk rewrites, custom migration scripts). Same rules apply.

## The 4.x split

Elementor 4.0 (April 2026) introduced an atomic foundation: Atomic Elements, Variables, Classes and Components, with a single-DIV wrapper and per-device styling.

- **New installs** start with atomic features on.
- **Existing sites** keep classic widgets after the update unless atomic is enabled (Elements panel notice or Elementor → Editor → Settings → Atomic Editor).
- Both can coexist on one page.

Detect what a page uses:

```
wp eval '
$data = json_decode( get_post_meta( 123, "_elementor_data", true ), true );
$types = array();
$walk = function ( $els ) use ( &$walk, &$types ) {
  foreach ( (array) $els as $el ) {
    $k = ( $el["elType"] ?? "?" ) . ":" . ( $el["widgetType"] ?? "-" );
    $types[ $k ] = ( $types[ $k ] ?? 0 ) + 1;
    if ( ! empty( $el["elements"] ) ) { $walk( $el["elements"] ); }
  }
};
$walk( $data );
echo json_encode( $types );
'
```

Atomic pages contain element types like `e-heading`, `e-paragraph`, `e-button`, `e-div-block`; classic pages use `widget` with `widgetType: heading`, `text-editor`, etc.

## Storage map

| Data | Location |
| --- | --- |
| Layout tree | post meta `_elementor_data` (JSON string) |
| Edit mode | `_elementor_edit_mode` = `builder` |
| Template type | `_elementor_template_type` (`wp-page`, `wp-post`, `header`, `footer`, `single`, `archive`, `popup`, `loop-item`) |
| Elementor version | `_elementor_version` |
| Page settings | `_elementor_page_settings` |
| Generated CSS | `_elementor_css` meta + `uploads/elementor/css/` |
| Dynamic tag bindings | `__dynamic__` inside settings |

## Node shape

```json
{
  "id": "1a2b3c4d",
  "elType": "container",
  "settings": { "content_width": { "unit": "px", "size": 1200 } },
  "elements": [
    { "id": "5e6f7a8b", "elType": "widget", "widgetType": "heading",
      "settings": { "title": "Hello", "header_size": "h2" }, "elements": [] }
  ]
}
```

- **id**: 7–8 hex chars, unique within the page.
- `elType`: `container`, `section`, `column`, `widget` (classic) or atomic element types.
- `widgetType` only on classic widgets.

## Safe edit procedure

1. Read `_elementor_data`, keep a copy.
2. Decode to an array.
3. Mutate the smallest subtree.
4. Encode with `wp_json_encode()`.
5. Write with `update_post_meta( $id, '_elementor_data', wp_slash( $json ) )`.
6. Delete `_elementor_css`.
7. Purge caches.
8. Verify the HTML.

`wp_slash()` is mandatory: `update_post_meta` unslashes, and unescaped quotes corrupt the JSON.

## Example: change a heading

```
wp eval '
$id = 123;
$data = json_decode( get_post_meta( $id, "_elementor_data", true ), true );
$walk = function ( &$els ) use ( &$walk ) {
  foreach ( $els as &$el ) {
    if ( ( $el["widgetType"] ?? "" ) === "heading" && ( $el["settings"]["title"] ?? "" ) === "Old title" ) {
      $el["settings"]["title"] = "New title";
    }
    if ( ! empty( $el["elements"] ) ) { $walk( $el["elements"] ); }
  }
};
$walk( $data );
update_post_meta( $id, "_elementor_data", wp_slash( wp_json_encode( $data ) ) );
delete_post_meta( $id, "_elementor_css" );
echo "done";
'
```

For atomic headings, the setting is usually `title` or `text` on an `e-heading` element — inspect an existing node first.

## Replace a whole layout

```
wp eval '
$tree = json_decode( file_get_contents( WP_CONTENT_DIR . "/uploads/new-layout.json" ), true );
$old  = get_post_meta( 123, "_elementor_data", true );
file_put_contents( WP_CONTENT_DIR . "/uploads/backup-page-123.json", $old );
update_post_meta( 123, "_elementor_data", wp_slash( wp_json_encode( $tree ) ) );
update_post_meta( 123, "_elementor_edit_mode", "builder" );
update_post_meta( 123, "_elementor_template_type", "wp-page" );
delete_post_meta( 123, "_elementor_css" );
'
```

## Cache regeneration

```
wp elementor flush-css
wp cache flush
wp litespeed-purge all
```

If `wp elementor flush-css` is unavailable, delete `uploads/elementor/css/*` and the `_elementor_css` meta for affected posts.

## Page settings

Common keys in `_elementor_page_settings`:

| Key | Effect |
| --- | --- |
| `template` | `elementor_canvas`, `elementor_header_footer`, `default` |
| `hide_title` | hide theme title |
| `page_layout` | `default`, `full-width` |
| `background_background` | `classic`/`gradient` |
| `custom_css` | per-page CSS (Pro) |

## Verifying

- `wpmcp/fetch-page` on the URL; search for the new text.
- If text is new but styling is old → CSS cache.
- If the page is blank → invalid JSON; restore the backup.
- Check the editor loads without "invalid content" warnings.

## Gotchas

- Revisions do not cover `_elementor_data` by default; your own backup is the rollback.
- Mixing a classic `column` directly under an atomic container breaks layout.
- Widget setting keys are per widget; copy the shape from an existing node.
- Pro-only widgets render nothing if the Pro plugin is deactivated; PRO Elements provides them here.
- Global colors/fonts live in the kit, not the page (see `elementor-kit`).
- Elementor Canvas bypasses the theme header/footer; do not use it for normal pages.
