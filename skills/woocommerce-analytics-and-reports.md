---
title: WooCommerce Analytics & Reports
category: WooCommerce
summary: Read revenue, orders and product performance correctly — report endpoints, HPOS lookup tables, date boundaries, refunds, and why dashboards disagree with exports.
updated: 2026-10-26
---

# WooCommerce Analytics & Reports

## Legacy reports (REST)

| Route | Returns |
| --- | --- |
| `/wc/v3/reports/sales` | totals, net sales, tax, shipping, discounts |
| `/wc/v3/reports/orders/totals` | order counts by status |
| `/wc/v3/reports/top_sellers` | quantity/revenue by product |
| `/wc/v3/reports/customers/totals` | registered vs guest |
| `/wc/v3/reports/products/totals` | counts by type |
| `/wc/v3/reports/coupons/totals` | counts by discount type |
| `/wc/v3/reports/stock` | low/out of stock |
| `/wc/v3/reports/reviews/totals` | review counts |

Parameters: `date_min`, `date_max` (`YYYY-MM-DD`), `period` (`week`, `month`, `year`).

## WooCommerce Analytics (admin)

Backed by `wc_order_stats`, `wc_order_product_lookup`, `wc_order_coupon_lookup`, `wc_order_tax_lookup`, `wc_product_meta_lookup`. It is faster than scanning orders but is a **derived** table.

- New or imported orders may take minutes to appear.
- `wp wc tool run regenerate_product_lookup_tables` rebuilds product lookup data.
- Deleting orders from the DB without the CRUD API leaves stale stats.

## Exact numbers: query the source

For an exact revenue figure:

```
wp db query "SELECT COUNT(*) AS orders, SUM(net_total) AS net FROM wp_wc_orders WHERE status IN ('wc-processing','wc-completed') AND date_created_gmt BETWEEN '2026-10-01 00:00:00' AND '2026-10-31 23:59:59'" --format=json
```

| Column | Meaning |
| --- | --- |
| `net_total` | order total minus refunds |
| `total_amount` | order total before refunds |
| `discount_total` | coupon discounts |
| `shipping_total` | shipping charged |
| `tax_total` | tax |

With legacy storage, query `wp_posts` + `wp_postmeta` (`_order_total`, `_order_tax`, `_order_shipping`), which is slower and error-prone — another reason to be on HPOS.

## Date boundaries

- Reports use the site timezone for display and GMT for storage.
- An order at 00:30 IST on Nov 1 is Oct 31 19:00 UTC; a GMT filter misses it.
- Always state which timezone a number uses.
- "Today" in an admin dashboard can differ from "today" in GA4 (different timezones).

## Refunds

- Net revenue subtracts refunds; gross does not.
- Partial refunds reduce `net_total` but the order status may stay `completed`.
- Refunded orders are usually excluded from "orders" counts but included in net if partial.
- Report both gross and net to avoid confusion.

## Product performance

```
GET /wc/v3/reports/top_sellers?date_min=2026-10-01&date_max=2026-10-31
GET /wc/v3/reports/products/totals
```

For per-variation performance, query `wc_order_product_lookup` grouped by `variation_id`.

## Cohorts and retention

Core has no cohort report. Approximate with:

```
wp db query "SELECT customer_id, COUNT(*) AS orders, MIN(date_created_gmt) AS first, MAX(date_created_gmt) AS last FROM wp_wc_orders WHERE status IN ('wc-processing','wc-completed') GROUP BY customer_id HAVING orders > 1 ORDER BY orders DESC LIMIT 20" --format=json
```

Guest orders have `customer_id = 0`; email-based cohorts need a join on the address table.

## Traffic vs revenue

WooCommerce reports do not know traffic. Pair with GA4:

- conversion rate = orders / sessions
- AOV = revenue / orders
- revenue per session = revenue / sessions

A revenue drop with flat sessions is a conversion problem; with falling sessions it is traffic.

## Common discrepancies

| Symptom | Cause |
| --- | --- |
| Dashboard > export | analytics cache not rebuilt |
| Orders count differs | status filter (pending/failed excluded) |
| Revenue differs | refunds, tax display, shipping inclusion |
| Product report missing SKUs | lookup table stale |
| Currency mix | multi-currency orders summed without conversion |
| Duplicate orders | payment webhook retry without idempotency |

## Scheduled reports

WooCommerce Analytics can email summaries. Verify the recipient and that the cron/Action Scheduler queue is running; a missed report is usually a stuck queue (see `cron-and-background-jobs`).

## Gotchas

- `per_page` max 100 on report endpoints.
- Reports are cached for minutes; do not poll in a loop.
- HPOS and legacy data can coexist during migration; totals may double-count until sync completes.
- Test orders and staff orders inflate numbers unless excluded by a customer filter.
- Timezone changes retroactively shift daily buckets.
- Exporting to CSV uses the site locale for decimal separators; spreadsheets may misparse.
