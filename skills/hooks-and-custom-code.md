---
title: Hooks & Custom Code
category: Core
summary: Put custom PHP in the right place — mu-plugins, child themes, hooks, conditional logic — with the standards and guardrails that keep production alive.
updated: 2026-10-26
---

# Hooks & Custom Code

Custom PHP belongs in exactly two places on this site: an **mu-plugin** (site-wide behaviour) or the **child theme** (presentation). Never core, never the parent theme, never a third-party plugin file.

## Where code goes

| Need | Location |
| --- | --- |
| Business logic, integrations, filters | `wp-content/mu-plugins/` |
| Templates, styles, enqueue | `wp-content/themes/oceanwp-child-theme-master/` |
| One-off experiment | `wp-content/mu-plugins/` then remove or promote |
| Plugin-like features with admin screens | a real plugin folder |

mu-plugins load automatically, cannot be deactivated by accident, and are perfect for `add_filter` work. Keep them small and named: `mu-plugins/10-example-core.php`.

## Loader stub for mu-plugins

```php
<?php
// wp-content/mu-plugins/10-example-core.php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/example/loader.php';
```

Group related files under `mu-plugins/example/` and require them from one entry point — easier to audit and to disable as a unit.

## The hook model

```php
// Filter: change a value and return it.
add_filter( 'the_title', function ( $title, $id ) {
    return $title;
}, 10, 2 );

// Action: do something at a point in time.
add_action( 'wp_footer', function () {
    echo '<!-- built by example -->';
} );

// Remove a default behaviour.
remove_action( 'wp_head', 'wp_generator' );
```

Priority: lower runs earlier (default 10). Accepted args must match what you use.

## How to find the right hook

- Look at the core/plugin source for `do_action( 'name'` and `apply_filters( 'name'`.
- `wpmcp/files` search: `pattern: "do_action( 'woocommerce_"` inside `plugins/woocommerce/`.
- Ask what data you have at that point; if the hook gives no ID, use a later hook.

## Snippets that are actually useful

**Disable the block editor for one post type**

```php
add_filter( 'use_block_editor_for_post_type', function ( $use, $type ) {
    return 'page' === $type ? false : $use;
}, 10, 2 );
```

**Add a body class**

```php
add_filter( 'body_class', function ( $classes ) {
    if ( is_product() ) { $classes[] = 'example-product'; }
    return $classes;
} );
```

**Change the add-to-cart text**

```php
add_filter( 'woocommerce_product_single_add_to_cart_text', function () {
    return __( 'Add to bag', 'example' );
} );
```

**Log slow queries during an incident**

```php
add_filter( 'query', function ( $query ) {
    $start = microtime( true );
    // do not log here; measure via wpdb filter + shutdown instead
    return $query;
} );
```

(For real query timing use Query Monitor, not a hand-rolled filter.)

## Writing safe code

- Escape output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Sanitize input: `sanitize_text_field()`, `absint()`, `sanitize_email()`.
- Check capability before writing: `current_user_can( 'manage_options' )`.
- Verify nonces for form posts: `wp_verify_nonce( $_POST['_wpnonce'], 'example_action' )`.
- Prefix everything: functions `example_`, options `example_`, hooks `example/`.
- No `extract()`, no `eval()`, no `@` suppression in new code.

## Fatal-error containment

A syntax error in an mu-plugin takes the whole site down with no recovery mode. Before writing:

1. Back up the file (or the whole `mu-plugins` folder).
2. Run `php -l` locally if possible. On the server, `wpmcp/wpcli` can run:
   `eval 'echo "ok";'` — but it cannot lint a file directly. Use `shell_exec` through `eval` only if the host allows it, or write to a temp filename and test with `wp eval-file`.
3. Keep the previous version at `mu-plugins/example/loader.php.bak` (not executable).
4. After writing, fetch the homepage; if it is 500, restore the `.bak` immediately.

## Child theme essentials

```php
// functions.php
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'oceanwp-child', get_stylesheet_uri(), array(), '2.0.0' );
} );
```

Do not enqueue the parent style twice — OceanWP already does. Add only your overrides.

## Debugging custom code

```
wp eval 'var_export( has_filter("the_content") );'
wp eval 'global $wp_filter; echo json_encode( array_keys( $wp_filter["init"]->callbacks ?? array() ) );'
```

`has_filter` returning a priority tells you whether your hook registered at all. If it returns false, the file is not loading (wrong path, early return, typo in `require`).

## Gotchas

- `functions.php` of a child theme runs for front end and admin; guard expensive work with `is_admin()`.
- Hooks added after `init` may miss `init`-time consumers; use `plugins_loaded` for integrations.
- `the_content` fires in feeds and REST too — check `is_feed()` / `REST_REQUEST` when output should be visual only.
- Elementor renders through its own hooks; `the_content` filters often do not apply inside Elementor widgets.
- Deleting an mu-plugin file is immediate and cannot be undone from wp-admin.
