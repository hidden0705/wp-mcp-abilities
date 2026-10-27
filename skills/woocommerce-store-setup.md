---
title: WooCommerce Store Setup
category: WooCommerce
summary: Configure a WooCommerce 11.x store correctly — pages, currency, selling locations, guest checkout, HPOS, accounts, and the settings that quietly break checkout.
updated: 2026-10-26
---

# WooCommerce Store Setup

This store runs **WooCommerce 11.2.1**. HPOS is the order storage for new installs; block cart/checkout are the default experience.

## Baseline audit

```
wp plugin list --status=active --format=json --fields=name,version
wp option get woocommerce_currency
wp option get woocommerce_default_country
wp option get woocommerce_calc_taxes
wp option get woocommerce_custom_orders_table_enabled
wp option get woocommerce_enable_guest_checkout
wp option get woocommerce_enable_checkout_login_reminder
wp option get woocommerce_cart_page_id
wp option get woocommerce_checkout_page_id
wp option get woocommerce_shop_page_id
wp option get woocommerce_myaccount_page_id
```

Missing page IDs = broken cart/checkout. Recreate with:

```
wp wc tool run install_pages --user=1
```

## Core settings that matter

| Setting | Key | Recommendation |
| --- | --- | --- |
| Currency | `woocommerce_currency` | INR, no symbol change after orders exist |
| Selling locations | `woocommerce_specific_allowed_countries` | restrict to countries you can ship to |
| Shipping locations | `woocommerce_ship_to_countries` | match zones |
| Guest checkout | `woocommerce_enable_guest_checkout` | yes for low-friction stores |
| Account creation | `woocommerce_enable_signup_and_login_from_checkout` | optional, after purchase |
| Taxes | `woocommerce_calc_taxes` | yes if GST applies |
| Coupons | `woocommerce_enable_coupons` | yes |
| Reviews | `woocommerce_enable_reviews` | yes, moderated |
| Stock display | `woocommerce_stock_format` | "low stock" only if true |
| Terms page | `woocommerce_terms_page_id` | required for India/EU |

## Currency

- Change currency only before real orders exist; historical orders keep their currency but reports mix.
- Multi-currency plugins store a conversion rate; verify order totals and refunds in the original currency.
- Currency position and decimals are under `woocommerce_currency_pos` and `woocommerce_price_num_decimals`.

## HPOS

```
wp option get woocommerce_custom_orders_table_enabled
wp option get woocommerce_custom_orders_table_data_sync_enabled
```

- HPOS on = orders in `wc_orders*` tables.
- Compatibility mode syncs HPOS → posts (one-way).
- Sync-on-read is **disabled by default since 10.7**: writes to `posts` no longer flow back to HPOS. Use the CRUD API.
- Migrate with `wp wc cot sync`, not by copying SQL rows.

## Pages

| Page | Shortcode/Block |
| --- | --- |
| Shop | archive, no content required |
| Cart | `[woocommerce_cart]` or Cart block |
| Checkout | `[woocommerce_checkout]` or Checkout block |
| My account | `[woocommerce_my_account]` |
| Terms | static page |

On a block theme the cart/checkout are templates; on a classic theme they are shortcodes in pages.

## Accounts and privacy

- Guest checkout on; account optional after purchase.
- Require a password set link, not a generated password in email.
- Enable privacy policy on checkout (`woocommerce_checkout_privacy_policy_text`).
- Erase/export tools include orders in current versions; HPOS-aware.
- Disable unused payment methods rather than hiding them with CSS.

## Emails and store address

```
wp option get woocommerce_email_from_address
wp option get woocommerce_store_address
wp option get woocommerce_store_city
wp option get woocommerce_default_country
```

Store address affects tax calculation and shipping labels. Set it before enabling taxes.

## Shipping zones

```
wp wc shipping_zone list --user=1 --format=json
wp wc shipping_zone_location list <zone_id> --user=1 --format=json
```

Every destination must match a zone, or the customer sees "no shipping options". Add a "Rest of the world" fallback or restrict selling locations.

## Tax basics

- Prices entered inclusive or exclusive (`woocommerce_prices_include_tax`).
- Standard rates at `wp wc tax list`.
- India: GST rates by product class; HSN codes are not core, use a plugin or meta.
- EU: VAT by destination, OSS thresholds; do not guess rates — use a tax service.

## Blocks vs classic

| Area | Block (default for new stores) | Classic |
| --- | --- | --- |
| Cart | Cart block, Store API | `[woocommerce_cart]` |
| Checkout | Checkout block, Store API | `[woocommerce_checkout]` |
| Custom fields | Additional Checkout Fields API | `woocommerce_checkout_fields` filter |
| Compatibility | fewer plugin hooks | widest plugin support |

Switching is per page via the block's Transform option. Do not mix block cart with classic checkout.

## Verification after setup

1. Add a simple product to cart.
2. Apply a coupon.
3. Checkout as guest with a real address.
4. Confirm tax and shipping lines.
5. Complete a test payment (sandbox or COD).
6. Check the order in admin and the confirmation email.
7. Refund the order.
8. Check reports include it.

## Gotchas

- A missing cart/checkout page ID shows a blank page, not an error.
- Restricting selling locations without a matching shipping zone blocks checkout.
- Changing the store address after orders exist does not recalculate historical tax.
- Enabling "redirect to cart after add" breaks quick-add UX on category pages.
- HPOS + an incompatible plugin can silently drop order meta; check WooCommerce → Status → HPOS compatibility.
- `wp wc tool run install_pages` is safe but does not overwrite existing pages.
