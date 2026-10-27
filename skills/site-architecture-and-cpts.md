---
title: Site Architecture & CPTs
category: Core
summary: Design the content model — custom post types, taxonomies, roles, options, and URL structure — before content piles up and migrations get expensive.
updated: 2026-10-26
---

# Site Architecture & CPTs

Most "WordPress problems" are modelling problems: the wrong content type, a taxonomy used as a field, or 40 plugins each adding options.

## Decide the content model

| Content | Use |
| --- | --- |
| Blog article | built-in `post` |
| Static page | built-in `page` |
| Sellable item | WooCommerce `product` |
| Repeating structured item with its own URL | CPT |
| Grouping of content | taxonomy |
| Single value attached to a post | post meta |
| Site-wide value | option |
| Reusable layout | synced pattern or template part |

Rule: if it needs an archive, a single template, and its own admin list, it is a CPT. If it only groups, it is a taxonomy.

## Registering a CPT (mu-plugin)

```php
add_action( 'init', function () {
    register_post_type( 'lookbook', array(
        'labels' => array(
            'name'          => 'Lookbooks',
            'singular_name' => 'Lookbook',
        ),
        'public'        => true,
        'has_archive'   => true,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-images-alt2',
        'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
        'rewrite'       => array( 'slug' => 'lookbook', 'with_front' => false ),
        'capability_type' => 'post',
        'map_meta_cap'  => true,
    ) );
} );
```

`show_in_rest => true` is mandatory for the block editor and REST access. `has_archive` gives you `/lookbook/`.

## Taxonomies

```php
register_taxonomy( 'season', array( 'lookbook', 'product' ), array(
    'labels'            => array( 'name' => 'Seasons' ),
    'hierarchical'      => true,
    'public'            => true,
    'show_in_rest'      => true,
    'rewrite'           => array( 'slug' => 'season' ),
) );
```

Prefer shared taxonomies over duplicate ones when the same vocabulary applies to products and editorial content.

## Flush rewrites once, not on every request

After registering a CPT or changing a slug:

```
wp rewrite flush --hard
```

Never call `flush_rewrite_rules()` on `init` — it writes the option on every page load and murders performance.

## URL design

| Pattern | Example |
| --- | --- |
| Product | `/product/cotton-kurta/` |
| Product category | `/product-category/kurtas/` |
| Blog post | `/blog/post-name/` or `/%postname%/` |
| CPT archive | `/lookbook/` |
| CPT single | `/lookbook/summer-2026/` |

Changing permalink structure on a live site requires 301s from the old URLs. Plan the redirect map before the switch (see `technical-seo`).

## Roles and capabilities

Built-in roles: administrator, editor, author, contributor, subscriber, plus WooCommerce's shop_manager, customer.

```
wp role list --fields=name,role --format=json
wp cap list shop_manager --format=json
```

Use `shop_manager` for store staff, `editor` for content staff, `author` for writers. Do not give `administrator` to anyone who only needs content or orders.

Custom capability for a CPT:

```php
'capability_type' => array( 'lookbook', 'lookbooks' ),
'map_meta_cap'    => true,
```

Then grant it deliberately:

```
wp cap add editor read_private_lookbooks
```

## Options hygiene

Every plugin writes options. Audit after installing:

```
wp option list --autoload=on --fields=option_name,size --format=json
```

Naming convention for custom options: `example_<feature>_<setting>`, autoload `no` unless needed on every request.

## Multisite decision

Use multisite only when sites genuinely share users/plugins/themes and need central management. It complicates:

- per-site plugin activation
- domain mapping and SSL
- backups (per-site tables + shared users)
- migrations away from multisite

For two or three unrelated stores, separate installs are cheaper to operate.

## Content modelling anti-patterns

- 200 pages that should be one CPT + taxonomy.
- A taxonomy with one term per post (that is a field).
- Product variations modelled as separate simple products (inventory and reporting break).
- Reusable content stored in a page builder template instead of a synced pattern.
- Custom fields with no REST exposure, then needing an integration.

## Migration path if you got it wrong

1. Create the new CPT/taxonomy.
2. Copy content with a script (keep old IDs in meta: `_legacy_post_id`).
3. Set up 301s from old URLs.
4. Verify templates, menus, sitemaps, internal links.
5. Only then delete the old content (after a backup).

```
wp eval '
$ids = get_posts( array( "post_type" => "post", "fields" => "ids", "posts_per_page" => -1, "category_name" => "lookbook" ) );
foreach ( $ids as $id ) {
    $p = get_post( $id );
    $new = wp_insert_post( array( "post_type" => "lookbook", "post_title" => $p->post_title, "post_content" => $p->post_content, "post_status" => $p->post_status ) );
    if ( $new && ! is_wp_error( $new ) ) { update_post_meta( $new, "_legacy_post_id", $id ); }
}
echo count( $ids );
'
```

## Gotchas

- CPT slugs must not collide with pages, product categories or reserved names (`attachment`, `revision`, `nav_menu_item`).
- Changing `rewrite` slug without flushing leaves 404s.
- `show_in_rest` off hides the CPT from the block editor and from this MCP server's REST proxy.
- Deleting a CPT registration orphans its content in the DB; it does not delete posts, but they become inaccessible in admin.
- Role changes are not versioned; document who has administrator.
