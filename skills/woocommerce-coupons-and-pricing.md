---
title: WooCommerce Coupons & Pricing
category: WooCommerce
summary: Run discounts without losing margin — coupon types, restrictions, usage limits, sale schedules, and how pricing interacts with tax, shipping and reports.
updated: 2026-10-26
---

# WooCommerce Coupons & Pricing

## Coupon fields that actually control behaviour

| Field | Effect |
| --- | --- |
| `discount_type` | `percent`, `fixed_cart`, `fixed_product` |
| `amount` | discount value |
| `individual_use` | cannot combine with other coupons |
| `exclude_sale_items` | ignores already-discounted products |
| `product_ids` / `excluded_product_ids` | scope |
| `product_categories` / `excluded_product_categories` | scope |
| `usage_limit` | total uses |
| `usage_limit_per_user` | per customer |
| `limit_usage_to_x_items` | caps discounted quantity |
| `minimum_amount` / `maximum_amount` | cart subtotal gate |
| `email_restrictions` | only these emails |
| `date_expires` | expiry (ISO 8601) |
| `free_shipping` | grants free shipping |

## Create a coupon

```
POST /wc/v3/coupons
{
  "code": "FESTIVE10",
  "discount_type": "percent",
  "amount": "10",
  "individual_use": true,
  "exclude_sale_items": true,
  "minimum_amount": "999",
  "usage_limit": 500,
  "usage_limit_per_user": 1,
  "date_expires": "2026-11-15T23:59:59",
  "product_categories": [ { "id": 34 } ]
}
```

Codes are case-insensitive in practice; avoid look-alike characters (`0/O`, `1/I`).

## Sale prices

```
PUT /wc/v3/products/101
{ "sale_price": "1199", "date_on_sale_from": "2026-10-26T00:00:00", "date_on_sale_to": "2026-11-05T23:59:59" }
```

- Sale price must be lower than regular price.
- Scheduled sales need cron/Action Scheduler to start and end on time.
- A sale price plus a coupon stacks unless the coupon excludes sale items.
- `price` in the response is the effective price; `regular_price` is the list price.

## Bulk pricing changes

```
POST /wc/v3/products/batch
{ "update": [ { "id": 101, "regular_price": "1599" }, { "id": 102, "regular_price": "1599" } ] }
```

Always export prices before a bulk change; there is no undo beyond a backup.

## Margin math

```
discount = price * pct/100
net = price - discount
margin = (net - cost) / net
```

- A 20% discount on a 40% margin leaves 25% margin, not 20%.
- Free shipping is a discount equal to the shipping cost; include it in the effective discount.
- COD/RTO costs should be priced into categories with high return rates.

## Dynamic pricing

Plugins can add tiered, role-based, or BOGO pricing. Rules to keep:

- One pricing engine, not two (plugin + custom code) — they conflict.
- Test the cart, not just the product page.
- Exclude staff/test accounts from role-based discounts.
- Ensure the order stores the price actually charged, not the recalculated one.

## Coupon abuse

- Card testing and coupon enumeration are common; rate-limit coupon attempts.
- `usage_limit_per_user` requires logged-in customers or email matching; guest checkout can bypass email restrictions with aliases.
- Do not publish a universal 50% code on a public page unless the margin supports it.
- Expire campaign codes; a forgotten code becomes a permanent discount.
- Monitor `usage_count` daily during a campaign.

## Reports and coupons

Coupons appear in order totals, not as a separate line in all reports. To measure a campaign:

```
wp db query "SELECT COUNT(*) AS orders, SUM(discount_total) AS discount FROM wp_wc_orders WHERE date_created_gmt BETWEEN '2026-10-01' AND '2026-10-31' AND status IN ('wc-processing','wc-completed')" --format=json
```

(HPOS table; with legacy storage query `wp_posts` + meta instead.)

## Gotchas

- `amount` for `percent` is a number, not `"10%"`.
- Coupons apply before tax; tax recalculates on the discounted total.
- A coupon restricted to a category does not apply if the product is only in a child category, depending on settings.
- Deleting a coupon does not remove it from historical orders (the discount stays recorded).
- Free-shipping coupons override zone costs but not local pickup restrictions.
- Sale price scheduling depends on cron; a missed cron leaves the sale live.
