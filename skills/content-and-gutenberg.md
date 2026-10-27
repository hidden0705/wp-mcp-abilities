---
title: Content & Gutenberg
category: Core
summary: Create and edit posts, pages, blocks and menus correctly — block markup, reusable patterns, scheduling, taxonomies, and safe bulk content operations.
updated: 2026-10-26
---

# Content & Gutenberg

WordPress 7.1 adds responsive block styles, a new media editor, Notes/@mentions, and the admin bar inside every editor. Content is still stored as HTML with block comments.

## How block content is stored

```html
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Title</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Body text.</p>
<!-- /wp:paragraph -->
```

Rules:
- The JSON attributes live in the opening comment; the HTML between comments is the saved markup.
- Invalid block markup triggers "This block contains unexpected or invalid content" and forces a repair.
- Nested blocks are ordered inside their parent's comments (`wp:columns` → `wp:column`).
- `wp:html` is raw HTML; `wp:shortcode` is a shortcode block.

## Reading content

```
wp post get 123 --field=post_content
wp post list --post_type=page --fields=ID,post_title,post_status,post_date --format=json
```

For a quick structural view of block types in a page:

```
wp eval '
$c = get_post_field( "post_content", 123 );
preg_match_all( "/<!-- wp:([a-z0-9\/-]+)/", $c, $m );
echo json_encode( array_count_values( $m[1] ) );
'
```

## Creating a page

```
wp post create --post_type=page --post_title="Size Guide" --post_name="size-guide" --post_status=draft --porcelain
```

Then set content. Passing large HTML through a CLI argument is fragile — write it to a file and import:

```
wpmcp/files { "action": "write", "path": "uploads/size-guide.html", "content": "<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->" }
wp eval '$html = file_get_contents( WP_CONTENT_DIR . "/uploads/size-guide.html" ); wp_update_post( array( "ID" => 456, "post_content" => wp_slash( $html ) ) );'
```

`wp_slash()` matters: `wp_update_post` unslashes, and unescaped JSON/HTML quotes corrupt block attributes.

## Editing one block's text

Safest approach: read the raw content, replace the exact string once, write back, then check block validity by loading the editor or re-parsing.

```
wp eval '
$c = get_post_field( "post_content", 123 );
$c = str_replace( "Old price $149", "New price $129", $c );
wp_update_post( array( "ID" => 123, "post_content" => wp_slash( $c ) ) );
delete_post_meta( 123, "_elementor_css" );
'
wp litespeed-purge all
```

Do not use `str_replace` on a generic phrase that appears in multiple blocks without counting first.

## Reusable blocks and patterns

- Synced pattern (formerly reusable block) is a `wp_block` post referenced by `<!-- wp:block {"ref":123} /-->`. Editing the `wp_block` updates every page using it.
- Unsynchronised pattern is copied markup; editing one page does not affect others.
- Theme patterns live in `/patterns/*.php` and appear in the inserter.

List synced patterns:

```
wp post list --post_type=wp_block --fields=ID,post_title,post_status --format=json
```

Update a synced pattern's content with `wp post update <id> --post_content=...`; all references pick it up after cache purge.

## Menus

```
wp menu list --format=json
wp menu item list <menu-slug> --fields=db_id,title,url,menu_item_parent --format=json
wp menu item add-custom <menu-slug> "Size Guide" /size-guide
wp menu item add-post <menu-slug> 456
wp menu location list --format=json
```

Block themes use Navigation blocks instead of classic menus; check `wp_is_block_theme()` before editing menus.

## Taxonomies and terms

```
wp term list category --fields=term_id,name,slug,count --format=json
wp term create category "Kurtas" --slug=kurtas
wp term update category 34 --name="Cotton Kurtas"
wp post term set 123 category 34 35
```

Never delete a term that has content without reassigning: `wp term delete category 34 --reassign=35`.

## Scheduling and status

| Status | Visible to |
| --- | --- |
| `draft` | editors |
| `pending` | editors (submitted for review) |
| `publish` | everyone |
| `future` | everyone after `post_date` |
| `private` | editors only |
| `trash` | editors, restorable |

```
wp post update 123 --post_status=future --post_date="2026-11-01 09:00:00"
```

Timezone: `post_date` is site-local; `post_date_gmt` is UTC. A 5-hour offset usually means the site timezone is wrong (`wp option get timezone_string`).

## Bulk content operations

For many posts, loop in one `eval` rather than hundreds of REST calls:

```
wp eval '
$ids = get_posts( array( "post_type" => "product", "fields" => "ids", "posts_per_page" => -1, "meta_key" => "_example_flag", "meta_value" => "1" ) );
foreach ( $ids as $id ) {
    wp_update_post( array( "ID" => $id, "post_excerpt" => "Updated line" ) );
}
echo count( $ids );
'
```

Cap the batch (50–100), log the IDs, and verify a sample afterwards. For very large sets use pagination (`posts_per_page => 100, paged => n`).

## Search and replace inside content only

```
wp search-replace "old phrase" "new phrase" --post-type=page --dry-run
wp search-replace "old phrase" "new phrase" --post-type=page
```

Scoping by post type avoids touching options, widgets and serialized product data.

## Gotchas

- `wp post update` with content that is not block-valid still saves; the editor repairs on open, which can drop attributes.
- Autosaves and revisions are separate posts (`post_type=revision`); they do not update with the parent.
- Elementor pages ignore `post_content`; edit `_elementor_data` instead (see `elementor-editing`).
- Gutenberg stores image IDs in block attributes; changing an attachment ID does not update existing blocks.
- Deleting a page does not remove it from menus — orphaned menu items remain until removed.
- With plain permalinks, preview URLs use `?page_id=`; do not "fix" that unless you are migrating URLs deliberately.
