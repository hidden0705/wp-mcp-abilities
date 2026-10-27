---
title: WooCommerce Checkout & Cart
category: WooCommerce
summary: Work with the block cart/checkout and Store API — custom fields, fees, validation, classic fallback, and the caching rules that keep checkout correct.
updated: 2026-10-26
---

# WooCommerce Checkout & Cart

## Which checkout is live?

```
wp post get $(wp option get woocommerce_checkout_page_id) --field=post_content
```

- Contains `wp:woocommerce/checkout` → block checkout (default for stores since 8.3).
- Contains `[woocommerce_checkout]` → classic shortcode checkout.
- Block themes may override via the Checkout template.

Never mix block cart with classic checkout.

## Block checkout architecture

- Cart and checkout are React blocks backed by the **Store API** (`/wp-json/wc/store/v1/...`), not the legacy `/wc/v3` admin API.
- Store API routes require a cart token/nonce for session; this MCP server's admin auth is for `/wc/v3`, not for simulating a shopper's cart.
- Extensibility: Additional Checkout Fields API, `woocommerce_store_api_*` filters, and block-based inner blocks.
- WooCommerce 11.2 added a Store API filter for validating cart item quantities; use it for min/max rules instead of JS-only validation.

## Custom checkout fields

Block checkout (recommended):

```php
add_action( 'woocommerce_init', function () {
    if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) { return; }
    woocommerce_register_additional_checkout_field( array(
        'id'       => 'example/gstin',
        'label'    => 'GSTIN (optional)',
        'location' => 'address',
        'type'     => 'text',
        'required' => false,
    ) );
} );
```

Read it from the order:

```
wp eval '
$order = wc_get_order( 1234 );
echo $order->get_meta( "_wc_other/example/gstin" );
'
```

Classic checkout uses `woocommerce_checkout_fields` instead. Do not add both.

## Fees and discounts

```php
add_action( 'woocommerce_cart_calculate_fees', function ( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
    if ( $cart->get_subtotal() < 999 ) {
        $cart->add_fee( 'Handling', 20, true ); // true = taxable
    }
} );
```

Fees must be recalculated on every cart load; storing them in the session causes mismatches.

## Cart validation

```php
add_filter( 'woocommerce_check_cart_items', function () {
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( $item['quantity'] > 10 ) {
            wc_add_notice( 'Maximum 10 units per item.', 'error' );
        }
    }
} );
```

For block checkout, server-side validation must also exist in the Store API layer; client-side-only checks are bypassable.

## Cart and checkout caching

Never cache:

- `/cart/`, `/checkout/`, `/my-account/`
- any page with `[woocommerce_cart]` / Checkout block
- mini-cart fragments (AJAX)

LiteSpeed normally excludes these. Verify:

```
curl -sI https://example.com/checkout/ | grep -i cache-control
```

Expect `no-cache, private` or `no-store`.

## Cart fragments

`wc-cart-fragments` runs on every page to keep the mini-cart in sync. Dequeue where there is no cart widget:

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_cart() && ! is_checkout() ) {
        wp_dequeue_script( 'wc-cart-fragments' );
    }
}, 99 );
```

Test the header cart count after this change.

## Common checkout failures

| Symptom | Cause | Check |
| --- | --- | --- |
| "No shipping options" | no zone match | zones + locations |
| "Invalid payment method" | gateway disabled for the country | gateway settings |
| Spinner forever | Store API blocked by WAF/cache | network tab, `/wc/store/v1/cart` |
| Nonce invalid | page cached | exclude checkout from cache |
| Total mismatch | fee/tax hook recalculated late | cart totals in Store API response |
| Fields missing | classic filter on block checkout | use Additional Fields API |
| Order created but no payment | gateway return URL/webhook | gateway logs |

## Testing a checkout safely

1. Enable a sandbox/test gateway (or COD with a test product).
2. Add product → cart → checkout as guest.
3. Apply a coupon.
4. Complete the order.
5. Verify order status, stock reduction, emails, analytics.
6. Refund and cancel.
7. Delete the test order (`force=true` only for test data).

## Conversion details that matter

- Guest checkout on; account optional after purchase.
- Address autocomplete where available.
- Inline validation, not on submit only.
- Show shipping cost before the payment step.
- Trust signals near the pay button (returns, secure payment).
- One-column layout on mobile.

## Gotchas

- The Checkout block ignores many classic hooks; a snippet that works on `[woocommerce_checkout]` may do nothing.
- Conditional fields added by plugins can become required and block checkout after an update.
- Caching plugins that ignore `no-cache` headers cause nonce failures under load.
- Store API requests are not authenticated by the admin application password; do not try to test a shopper session through `wpmcp/rest-request`.
- Deleting the checkout page and recreating it loses custom block attributes.
