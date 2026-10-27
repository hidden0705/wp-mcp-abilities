---
title: WooCommerce Performance & Scaling
category: WooCommerce
summary: Make a WooCommerce 11 store fast under load — HPOS, object cache, cache exclusions, cart fragments, Store API, and the queries that grow with order count.
updated: 2026-10-26
---

# WooCommerce Performance & Scaling

## The big levers

1. **HPOS** — orders in dedicated tables, fewer joins, better indexes.
2. **Object cache** — Redis/Memcached for options, products, queries.
3. **Page cache** — HTML for guests, with cart/checkout excluded.
4. **Product lookup tables** — `wc_product_meta_lookup` for price/filter queries.
5. **Asset discipline** — WooCommerce scripts only where needed.
6. **Action Scheduler health** — a backed-up queue slows everything.

## Check the environment

```
wp option get woocommerce_custom_orders_table_enabled
wp cache type
wp plugin list --status=active --fields=name,version --format=json
wp db query "SELECT COUNT(*) FROM wp_wc_orders" --format=json
wp db query "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE status='pending'" --format=json
```

## HPOS

- Enabled by default for new installs since 8.2.
- Sync-on-read disabled since 10.7; use CRUD.
- Full-text order search is experimental; leave off unless needed.
- Verify all plugins declare HPOS compatibility (WooCommerce → Status).

## Object cache

```
wp cache flush
wp eval 'echo wp_using_ext_object_cache() ? "yes" : "no";'
```

- Flush after imports and direct SQL.
- Do not flush during peak checkout.
- A persistent object cache can serve stale product prices after a bulk update — purge product caches (`wc_delete_product_transients()` or the plugin's cache tool).

## Page cache rules

Never cache: cart, checkout, my-account, order-received, login, REST, admin-ajax.

Cache: home, shop, category, product, blog, static pages.

Verify headers:

```
curl -sI https://example.com/product/cotton-kurta/ | grep -i -E 'x-litespeed|cache-control'
```

## Cart fragments

`wc-cart-fragments` is an AJAX call on every page for guests in some themes. Dequeue when there is no mini-cart:

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_cart() && ! is_checkout() ) {
        wp_dequeue_script( 'wc-cart-fragments' );
    }
}, 99 );
```

## WooCommerce scripts per page

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
        wp_dequeue_style( 'woocommerce-general' );
        wp_dequeue_style( 'woocommerce-layout' );
        wp_dequeue_style( 'woocommerce-smallscreen' );
    }
}, 99 );
```

Test product pages and cart after any dequeue; WooCommerce blocks may still need their styles.

## Queries that grow with orders

- "Customers who bought X" — use lookup tables, not meta queries.
- Order list filters by meta — slow under legacy storage, fast under HPOS.
- Reports — use `wc_order_stats`, not `posts` joins.
- Scheduled actions backlog — cap and clean.

```
wp db query "SELECT status, COUNT(*) AS n FROM wp_actionscheduler_actions GROUP BY status" --format=json
wp action-scheduler clean --status=complete --before="30 days ago"
```

## Product catalog scale

- Keep `per_page` sane on archives; 24–48 is typical.
- Avoid `orderby=rand()`.
- Use lookup tables for price filters.
- Limit variations per product (hundreds, not thousands).
- Use a search plugin for large catalogs instead of `LIKE '%term%'`.

## Checkout performance

- Block checkout loads Store API requests; each must be fast.
- Do not run a remote API call synchronously in `woocommerce_checkout_create_order`.
- Shipping rate calculation with many zones/methods is a common slow point; cache rates per cart hash.
- Enable HPOS and object cache before scaling traffic, not after.

## Load testing

- Test on staging with production-like data.
- Simulate add-to-cart and checkout, not just homepage.
- Watch DB CPU, PHP workers, and TTFB under concurrency.
- A page cache test alone hides checkout bottlenecks.

## Gotchas

- Caching a page with a cart count in the header shows the wrong count to guests.
- Excluding `/checkout/` by URL misses custom checkout slugs; exclude by page ID or shortcode/block.
- HPOS migration should run during low traffic; it writes many rows.
- Product object caching (default in WooCommerce 11 for new stores) can serve stale stock — purge after inventory changes.
- Too many variations on one product slow the admin and the Store API.
- A CDN caching `/wp-json/wc/store/v1/cart` breaks carts; always bypass.
