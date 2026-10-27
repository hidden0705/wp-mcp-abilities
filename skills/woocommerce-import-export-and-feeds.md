---
title: WooCommerce Import, Export & Feeds
category: WooCommerce
summary: Move product data in and out safely — CSV importer columns, GTIN matching, batch limits, and product feeds for Google Merchant Center and Meta.
updated: 2026-10-26
---

# WooCommerce Import, Export & Feeds

## CSV import (admin)

WooCommerce → Products → Import. The importer maps columns to fields and supports:

- `ID` or `SKU` for updates
- variations with `Parent` + attribute columns
- categories, tags, images (`Images` = comma-separated URLs)
- custom meta via `Meta: key`
- `GTIN`, `MPN`, `Brand` in WooCommerce 11.2+ (CSV matches by GTIN)

Rules:
- Import a 5-row sample first.
- Use `SKU` as the identifier for updates; without it, you create duplicates.
- Images import one by one; 500 products × 4 images can time out. Split the file.
- Set `Published`/`Draft` explicitly.
- Back up before a large import.

## WP-CLI import

```
wp wc product create --user=1 --name="Test" --type=simple --regular_price=999 --sku=TEST-1
wp wc product list --user=1 --format=json --per_page=5
```

For bulk, `wp import` (WordPress importer) is for WXR, not products. Product CSV via CLI is not a core command; use the admin importer or a plugin.

## REST batch import

```
POST /wc/v3/products/batch
{ "create": [ { ... }, { ... } ] }
```

Max 100 per request. Loop with a small delay and log failures. Batch is faster than 100 single POSTs.

## Export

- Admin: Products → Export, choose columns, limit to a filter.
- REST: page through `/wc/v3/products?per_page=100` and write JSON.
- Always include `id`, `sku`, `name`, `regular_price`, `sale_price`, `stock_quantity`, `categories`, `images`, `status`.

Backup export before any bulk edit:

```
wp eval '
$page = 1; $all = array();
do {
  $res = wc_get_products( array( "limit" => 100, "page" => $page, "return" => "objects" ) );
  foreach ( $res as $p ) { $all[] = array( "id" => $p->get_id(), "sku" => $p->get_sku(), "price" => $p->get_price(), "stock" => $p->get_stock_quantity() ); }
  $page++;
} while ( count( $res ) === 100 );
file_put_contents( WP_CONTENT_DIR . "/uploads/products-export.json", wp_json_encode( $all ) );
echo count( $all );
'
```

## Google Merchant Center feed

Requirements per product:

| Field | Source |
| --- | --- |
| `id` | product ID or SKU (stable) |
| `title` | product name, ≤150 chars |
| `description` | 150–5000 chars, plain text |
| `link` | canonical product URL |
| `image_link` | main image, ≥500×500, accessible |
| `availability` | in stock / out of stock |
| `price` | regular price + currency |
| `sale_price` | if on sale |
| `brand` | required for apparel |
| `gtin` | if available |
| `condition` | new |
| `shipping` | configured in Merchant Center or feed |

Common disapprovals: missing GTIN, price mismatch with the landing page, image with a watermark, unavailable product still in the feed, adult/medical policy flags.

Feed options: a plugin (Product Feed PRO, CTX Feed) or a scheduled script that writes XML to a URL. Verify the feed URL after every catalog change.

## Meta / Instagram catalog

- Same core fields plus `item_group_id` for variants.
- Refresh schedule at least daily.
- Price and availability must match the site within the review window.

## Multi-currency and feeds

- Feed currency must match the landing page currency for that market.
- A conversion plugin can serve a different price than the feed; verify the rendered price.

## Order export

```
GET /wc/v3/orders?status=completed&after=2026-10-01T00:00:00&per_page=100
```

For accounting, export line items with tax, shipping and discount per order. CSV exports from plugins often omit HPOS fields; validate against one known order.

## Gotchas

- CSV encoding: UTF-8 without BOM; Excel mangles ₹ and diacritics.
- Decimal separator must be `.` in the CSV regardless of site locale.
- `Images` URLs must be publicly reachable; localhost or signed URLs fail.
- Updating by SKU when SKUs changed creates duplicates.
- Variation CSV needs `Parent` = parent SKU and one attribute column per variation attribute.
- Feeds cache; a catalog update may take hours to propagate.
- Deleting a product removes it from the feed on the next fetch; Merchant Center may keep disapproving for a day.
