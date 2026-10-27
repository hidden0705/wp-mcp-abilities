---
title: Child Themes & Templates
category: Core
summary: Extend OceanWP safely — child theme structure, template overrides, enqueue order, hooks the theme exposes, and how to change markup without losing it on update.
updated: 2026-10-26
---

# Child Themes & Templates

This site runs **OceanWP Child**. All presentation changes belong there; the parent theme and plugins stay untouched.

## Verify the setup

```
wp theme list --format=json
wp eval 'echo get_stylesheet_directory();'
wp eval 'echo get_template_directory();'
```

`get_stylesheet_directory()` = child, `get_template_directory()` = OceanWP parent. If they are identical, there is no child theme and an update will wipe customisations.

## Minimum child theme

```
oceanwp-child-theme-master/
  style.css
  functions.php
  screenshot.png
```

```css
/*
Theme Name: OceanWP Child
Template: oceanwp
Version: 2.0.0
*/
```

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'oceanwp-child',
        get_stylesheet_uri(),
        array( 'oceanwp-style' ),
        wp_get_theme()->get( 'Version' )
    );
}, 20 );
```

Do not enqueue the parent stylesheet again; OceanWP already enqueues it. Enqueue your child style **after** it (priority 20) so overrides win.

## Template override hierarchy

WordPress looks in the child first, then the parent:

1. `child/woocommerce/single-product.php`
2. `parent/woocommerce/single-product.php`
3. `plugin/woocommerce/templates/single-product.php`

Copy the file from the plugin's `templates/` folder into `child/woocommerce/`, keeping the same relative path. Never edit the plugin copy.

WooCommerce warns when an override is outdated:

```
wp wc tool run install_pages --user=1
```

(That command is for pages; for template status check WooCommerce → Status → Templates.)

## OceanWP hooks worth knowing

| Hook | Position |
| --- | --- |
| `ocean_before_header` | before header markup |
| `ocean_after_header` | after header |
| `ocean_before_content` | before main content |
| `ocean_after_content` | after main content |
| `ocean_before_footer` | before footer |
| `ocean_after_footer` | after footer |
| `ocean_sidebar` | sidebar output |
| `ocean_post_title` | single post title area |
| `ocean_woo_before_shop_loop` | shop loop top |

```php
add_action( 'ocean_after_header', function () {
    if ( is_front_page() ) {
        echo '<div class="example-announce">Free shipping over $99</div>';
    }
} );
```

Escape dynamic values with `esc_html()`; static markup is fine.

## Changing markup: filters > template copies

Copying a template freezes it. Prefer filters when one exists:

```php
// Move the product title.
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 15 );

// Change the number of products per row.
add_filter( 'loop_shop_columns', function () { return 4; } );

// Change products per page.
add_filter( 'loop_shop_per_page', function () { return 24; } );
```

## CSS strategy

- Prefer the Customizer/Additional CSS for tiny tweaks (stored in DB, survives theme updates).
- Prefer the child `style.css` for anything versioned.
- Avoid `!important` unless overriding an inline style; it makes later fixes harder.
- Use the theme's CSS variables where available; check `wp-content/themes/oceanwp/assets/css/`.

After CSS changes, purge LiteSpeed (`cssjs` and `all`) or you will debug a cached file.

## functions.php vs plugin code

| Belongs in child theme | Belongs in mu-plugin |
| --- | --- |
| enqueue styles/scripts | business logic |
| template hooks and layout | integrations, cron, REST routes |
| small display filters | anything that must survive a theme switch |

If the behaviour must remain when the theme changes, it is plugin code.

## Adding a page template

```php
<?php
/* Template Name: Landing (no header) */
get_header();
?>
<main id="example-landing">
    <?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
</main>
<?php get_footer();
```

Save as `child/page-landing.php`; it appears in the page's Template dropdown (classic editor) or under Template → Template in the block editor.

## Debugging a template that will not override

1. Confirm the file path matches the hierarchy exactly (case-sensitive on Linux).
2. Confirm the child theme is active.
3. Check for a caching plugin serving the old page.
4. Check the template is not being bypassed by Elementor (Elementor Canvas/Full Width overrides theme templates).
5. `wp eval 'echo get_page_template();'` inside a request context is not available; use the body class or `is_page_template()` in a temporary hook.

## Gotchas

- A child theme does not inherit `functions.php` automatically; parent functions still load, but your file must `require` anything you expect from your own folder.
- Copying an outdated WooCommerce template is the #1 cause of broken layouts after a Woo update. Prefer hooks; if you must copy, re-diff on every WooCommerce update.
- `style.css` header `Template:` must match the parent folder slug, not the theme display name.
- OceanWP's own customiser settings live in options, not files; switching to a new theme loses them.
