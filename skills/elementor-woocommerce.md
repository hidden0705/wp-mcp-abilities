---
title: Elementor + WooCommerce
category: Elementor
summary: Build product and shop templates in Elementor without breaking WooCommerce — widgets, conditions, cart/checkout, and the conflicts to avoid.
updated: 2026-10-26
---

# Elementor + WooCommerce

## What Elementor Pro adds

- **Product Archive** template (shop, categories).
- **Single Product** template.
- WooCommerce widgets: product title, images, price, add-to-cart, rating, meta, tabs, related, upsell, breadcrumb, menu cart, cart, checkout, my account.
- Dynamic product tags (price, rating, stock, SKU).

Without Pro (or PRO Elements), use WooCommerce's own templates and style with CSS.

## Template types

| Type | Applies to |
| --- | --- |
| `product-archive` | `/shop/`, product categories |
| `product` | single product |
| `single-product` | specific product template |
| `loop-item` | product grid item |

Conditions live in `_elementor_conditions` (see `elementor-theme-builder`). A product template with `include/general` overrides the theme for every product.

## Single product structure that converts

1. Breadcrumb.
2. Gallery (left) + summary (right) on desktop; stacked on mobile.
3. Title, rating, price, short description.
4. Variation swatches/dropdowns, quantity, add to cart.
5. Trust cues: delivery, returns, secure payment.
6. Tabs: description, additional information, reviews.
7. Related products.
8. Sticky add-to-cart on mobile.

Use WooCommerce widgets, not static text, so price and stock stay live.

## Product archive

- Use the WooCommerce Product Archive template, not a generic Loop Grid, to keep sorting, filters and pagination.
- Keep the result count and sorting controls.
- 3 columns desktop / 2 tablet / 1–2 mobile.
- Show price, rating and a clear add-to-cart or "view" action.
- Do not lazy-load the first row of product images.

## Cart, checkout, my account

Elementor Pro widgets exist, but:

- The Checkout block is the WooCommerce standard for new stores; an Elementor checkout widget is a different implementation.
- Do not mix Elementor checkout with block cart.
- Caching must exclude these pages regardless of which one is used.
- Test payment gateways after switching.

If the site already uses block cart/checkout, do not replace it with Elementor widgets just for styling; style via CSS or block patterns.

## Product data shown by widgets

| Widget | Source |
| --- | --- |
| Price | `$product->get_price()` / variation range |
| Add to cart | variation form, stock status |
| Rating | comments with `rating` meta |
| Stock | `stock_status`, `stock_quantity` |
| Gallery | featured + `_product_image_gallery` |
| SKU | `_sku` |

If a widget shows empty: the product lacks that data, or the template is rendering outside the product context.

## Common conflicts

- Two price blocks (theme + Elementor) → duplicate price.
- Elementor's product gallery and WooCommerce's gallery both enqueue → double slider.
- Add-to-cart widget ignores WooCommerce's ajax settings.
- Variation swatches plugin + Elementor widget → swatches not styled.
- Product archive template hides WooCommerce notices (out of stock, coupon messages).
- Cart fragments dequeue breaks the Elementor menu cart count.

## Styling without breaking WooCommerce

- Use WooCommerce's classes (`.price`, `.woocommerce-Price-amount`, `.single_add_to_cart_button`) for CSS overrides.
- Do not hide `.woocommerce-notices-wrapper`; it carries errors.
- Keep the variation form's `data-product_variations` intact; do not rebuild it.
- Style swatches with the plugin's classes, not custom JS.

## Verification checklist

1. Shop archive: sorting, pagination, sale badges.
2. Product: variable selection updates price and image.
3. Add to cart from archive and single.
4. Cart: quantity update, coupon, shipping calculator.
5. Checkout: fields, payment, order creation.
6. My account: orders, addresses.
7. Mobile: sticky add-to-cart does not cover the footer.
8. Cache: `/cart/`, `/checkout/` are not cached.

## Performance

- Product templates add DOM; keep the gallery to 5 images.
- Related products query is extra; limit to 4.
- Do not use a Loop Grid for the shop archive.
- Disable Elementor animations on product images.
- Check INP on variation selection — it is a common interaction bottleneck.

## Gotchas

- Changing `_elementor_template_type` on a product template can make it apply to pages.
- Deleting a product template reverts to the theme, which may look completely different.
- WooCommerce updates can change widget markup; re-test after updates.
- HPOS does not affect templates, but order-related widgets may read order data differently.
- Elementor's "Product Archive" does not automatically include WooCommerce's filter widgets from other plugins.
