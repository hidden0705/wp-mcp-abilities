---
title: WooCommerce Products
category: WooCommerce
summary: Create and maintain simple, variable and digital products — attributes, variations, inventory, categories, galleries, and bulk operations that do not corrupt data.
updated: 2026-10-26
---

# WooCommerce Products

Products are the `product` post type plus WooCommerce meta and lookup tables. Create them through the REST API, never by inserting rows.

## Endpoints

- `GET|POST /wc/v3/products`, `GET|PUT|DELETE /wc/v3/products/{id}`
- `/wc/v3/products/{id}/variations`
- `/wc/v3/products/categories`, `/tags`, `/attributes`
- `/wc/v3/products/reviews`
- `POST /wc/v3/products/batch` (max 100)
- Filters: `status`, `type`, `sku`, `category`, `stock_status`, `featured`, `on_sale`, `min_price`, `max_price`, `orderby`, `per_page`

## Simple product

```
POST /wc/v3/products
{
  "name": "Cotton Kurta - Navy",
  "type": "simple",
  "status": "draft",
  "regular_price": "1499",
  "sale_price": "1199",
  "description": "<p>Handloom cotton.</p>",
  "short_description": "Breathable handloom cotton.",
  "sku": "QMK-NAVY-M",
  "manage_stock": true,
  "stock_quantity": 25,
  "stock_status": "instock",
  "categories": [ { "id": 34 } ],
  "images": [ { "src": "https://example.com/kurta.jpg", "alt": "Navy cotton kurta" } ],
  "attributes": [ { "name": "Fabric", "options": ["Cotton"], "visible": true, "variation": false } ]
}
```

Prices are strings, no symbol. `images[].src` downloads into the media library.

## Variable product

Parent:

```
{
  "name": "Cotton Kurta",
  "type": "variable",
  "attributes": [
    { "name": "Size", "position": 0, "visible": true, "variation": true, "options": ["S","M","L","XL"] },
    { "name": "Color", "position": 1, "visible": true, "variation": true, "options": ["Navy","White"] }
  ]
}
```

Variation:

```
POST /wc/v3/products/{parent}/variations
{
  "regular_price": "1499",
  "sku": "QMK-NAVY-M",
  "manage_stock": true,
  "stock_quantity": 10,
  "attributes": [ { "name": "Size", "option": "M" }, { "name": "Color", "option": "Navy" } ]
}
```

Attribute `name`/`option` must match the parent exactly, including case. Variation SKUs must be unique across the store.

## Inventory

| Field | Meaning |
| --- | --- |
| `manage_stock` | track quantity |
| `stock_quantity` | units on hand |
| `stock_status` | `instock`, `outofstock`, `onbackorder` |
| `backorders` | `no`, `notify`, `yes` |
| `low_stock_amount` | threshold for the low-stock notice |
| `sold_individually` | one per order |

After direct DB edits (don't), regenerate lookup tables:

```
wp wc tool run regenerate_product_lookup_tables --user=1
```

## Categories and attributes

```
GET  /wc/v3/products/categories
POST /wc/v3/products/categories { "name": "Kurtas", "parent": 0 }
```

Hierarchy matters for breadcrumbs and canonical URLs. Keep one primary category per product where possible.

Global attributes (`/wc/v3/products/attributes`) are reusable; local attributes exist only on one product. Prefer global for sizes/colors.

## Galleries and images

- `images[0]` is the featured image.
- Additional images form the gallery.
- Alt text is attachment meta; set it at import time.
- WooCommerce 11.x has core color swatches and variation galleries; themes may not support them yet — verify on the front end.

## Product types

| Type | Use |
| --- | --- |
| `simple` | one SKU |
| `variable` | size/color matrix |
| `grouped` | a set of existing simple products |
| `external` | affiliate/out-of-store |
| `virtual` / `downloadable` | no shipping / files |

Do not model variants as separate simple products: inventory, filters and reports break.

## Bulk operations

```
POST /wc/v3/products/batch
{
  "update": [ { "id": 101, "regular_price": "1599" }, { "id": 102, "regular_price": "1599" } ],
  "delete": [ 103 ]
}
```

Check each returned element for `id`; a failed item has an `error` and no `id`.

## Finding products

```
GET /wc/v3/products?sku=QMK-NAVY-M
GET /wc/v3/products?search=kurta&per_page=50
GET /wc/v3/products?category=34&stock_status=outofstock
```

Search is title/content based; SKU lookup is exact and cheaper.

## Product meta and custom fields

```
PUT /wc/v3/products/{id}
{ "meta_data": [ { "key": "_qadri_fabric", "value": "Handloom" } ] }
```

`meta_data` replaces the whole list on update — send existing keys you want to keep. Prefer a registered custom field with REST exposure for integrations.

## Content quality per product

- Unique 150+ word description; not the manufacturer's copy.
- 3–5 images, including a scale/detail shot.
- Size chart for apparel.
- Shipping and return info in a tab or section.
- Structured attributes (fabric, fit, occasion) for filters.
- SKU, GTIN/barcode if available.

## Gotchas

- `per_page` max 100.
- Deleting with `force=true` skips trash.
- A variable product with no priced variation cannot be added to cart.
- Sale price must be lower than regular; equal values are ignored.
- Changing a product type after orders exist can orphan variation data.
- Category slugs changing breaks filter URLs; redirect.
- Product object caching is on by default for new stores in WooCommerce 11+; purge after imports.
