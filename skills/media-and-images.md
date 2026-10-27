---
title: Media & Images
category: Growth
summary: Import, size, describe and replace images correctly — attachment metadata, alt text, srcset, WebP/AVIF, regeneration, and cleanup without breaking references.
updated: 2026-10-26
---

# Media & Images

## Import from a URL

`wpmcp/media-import` downloads a remote file and creates an attachment:

```
{ "url": "https://example.com/product.jpg", "title": "Cotton Kurta Navy", "alt": "Navy cotton kurta, front view" }
```

Returns `id`, `url`, `mime`. WordPress generates registered sizes. Max ~25 MB, http(s) only, URL should end in a real filename.

## Upload a local file

Write it under `wp-content/uploads/` with `wpmcp/files`, then register:

```
wp eval '
require_once ABSPATH . "wp-admin/includes/media.php";
require_once ABSPATH . "wp-admin/includes/image.php";
$file = WP_CONTENT_DIR . "/uploads/kurta.jpg";
$id = wp_insert_attachment( array( "post_mime_type" => "image/jpeg", "post_title" => "Cotton Kurta Navy", "post_status" => "inherit" ), $file );
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
echo $id;
'
```

`wp_generate_attachment_metadata` is what creates the resized variants; without it `srcset` is broken.

## Alt text and metadata

```
wp eval 'update_post_meta( 456, "_wp_attachment_image_alt", "Navy cotton kurta, front view" );'
```

Alt text is post meta, not a post field. It is required for accessibility and read by SEO plugins.

Title/caption/description:

```
wp post update 456 --post_title="Cotton Kurta Navy" --post_excerpt="Caption" --post_content="Description"
```

## Find images missing alt text

```
wp eval '
global $wpdb;
$rows = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = \"_wp_attachment_image_alt\" WHERE p.post_type = \"attachment\" AND p.post_mime_type LIKE \"image/%\" AND ( m.meta_value IS NULL OR m.meta_value = \"\" ) LIMIT 100" );
echo json_encode( $rows );
'
```

## Sizes and srcset

```
wp eval 'echo json_encode( get_intermediate_image_sizes() );'
```

Core: `thumbnail` 150, `medium` 300, `medium_large` 768, `large` 1024. WooCommerce: `woocommerce_thumbnail` 300×300 crop, `woocommerce_single` 600, `woocommerce_gallery_thumbnail` 100.

`add_image_size()` only affects new uploads. Regenerate:

```
wp media regenerate --yes
wp media regenerate 456 --yes
```

Run in batches on large libraries; regeneration is CPU-heavy.

## Modern formats

- WebP/AVIF is produced by LiteSpeed's image optimizer or a dedicated plugin.
- After bulk imports, purge and trigger optimization; check that the served URL actually returns `image/webp`.
- Keep JPEG fallbacks; not every browser/crawler accepts AVIF.
- Do not convert PNG logos with transparency to lossy WebP without checking edges.

## Replacing an image while keeping the URL

```
wp eval '
$file = get_attached_file( 456 );
copy( WP_CONTENT_DIR . "/uploads/new.jpg", $file );
wp_update_attachment_metadata( 456, wp_generate_attachment_metadata( 456, $file ) );
echo "replaced";
'
wp litespeed-purge all
```

Use when emails, ads or external links point at a fixed URL. The attachment ID stays stable, so Elementor/WooCommerce references keep working.

## Finding where an image is used

```
wp eval '
global $wpdb;
$url = wp_get_attachment_url( 456 );
echo $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = \"publish\"", "%" . $wpdb->esc_like( $url ) . "%" ) );
'
```

Also check `_thumbnail_id` meta and Elementor `_elementor_data` (search the attachment ID or URL).

## Deleting media

`wp media delete 456` trashes; `--force` is permanent. Before deleting:

1. Search content for the URL.
2. Check featured images (`_thumbnail_id`).
3. Check Elementor JSON.
4. Check product galleries (`_product_image_gallery`).

## Serving sizes / performance

- Upload at ~2× the largest displayed size, not 4000px.
- Product images: 1000–1600px is enough for zoom.
- Hero: 1600–2000px wide, compressed.
- Do not lazy-load the LCP image.
- WordPress 7.1's new media editor adds crop/rotate/flip/metadata in one modal; edits create a new file version, so purge caches after.

## Gotchas

- `wp-content/uploads` must be writable by the web user; otherwise imports fail with a generic error.
- Changing a theme's image sizes does not resize old files.
- Deleting an attachment does not remove references; pages show broken images.
- CDN/LiteSpeed may serve old image bytes after replacement — purge both.
- EXIF data can leak location; strip it for public uploads.
- SVG uploads are disabled by default for good reason — sanitize before enabling.
