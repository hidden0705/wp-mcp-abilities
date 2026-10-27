---
title: WooCommerce Orders & Fulfilment
category: WooCommerce
summary: Manage orders under HPOS — statuses, line items, notes, refunds, fulfilment, and the exact places where direct DB edits silently fail.
updated: 2026-10-26
---

# WooCommerce Orders & Fulfilment

## Storage first

```
wp option get woocommerce_custom_orders_table_enabled
wp option get woocommerce_custom_orders_table_data_sync_enabled
```

- HPOS on: orders in `wc_orders`, `wc_orders_meta`, `wc_order_addresses`, `wc_order_operational_data`.
- HPOS off: legacy `posts` + `postmeta`.
- Compatibility mode syncs HPOS → posts one way.
- Sync-on-read disabled since 10.7: a write to `posts` does not update HPOS.

Never use `wp_update_post`, `update_post_meta`, or SQL on an order. Use CRUD or REST.

## Statuses

| Slug | Meaning |
| --- | --- |
| `pending` | awaiting payment |
| `processing` | paid, needs fulfilment |
| `on-hold` | awaiting action |
| `completed` | fulfilled |
| `cancelled` | cancelled |
| `refunded` | fully refunded |
| `failed` | payment failed |
| `checkout-draft` | abandoned block checkout |

Custom statuses appear without the `wc-` prefix via REST.

## Read orders

```
GET /wc/v3/orders?status=processing&per_page=20&orderby=date&order=desc
GET /wc/v3/orders/1234
GET /wc/v3/orders?after=2026-10-01T00:00:00&before=2026-10-31T23:59:59&status=completed
```

Key fields: `id`, `number`, `status`, `currency`, `total`, `billing`, `shipping`, `line_items[]`, `date_created`, `payment_method_title`, `customer_note`.

## Update status

```
PUT /wc/v3/orders/1234 { "status": "completed" }
```

Fires the normal hooks: emails, stock, integrations. Add a note:

```
POST /wc/v3/orders/1234/notes { "note": "Shipped via Delhivery AWB 12345", "customer_note": false }
```

`customer_note: true` emails the customer.

## Edit line items

```
PUT /wc/v3/orders/1234
{ "line_items": [ { "id": 55, "quantity": 2 }, { "id": 56, "quantity": 0 } ] }
```

- Omitted items are kept; `quantity: 0` removes.
- Adding an item: send `product_id` (+ `variation_id`) without `id`.
- Totals recalculate; do not send `total` unless deliberately overriding.
- WooCommerce 11.1 changed order-item deletion internals; use the API, not direct row deletes.

## Refunds

```
POST /wc/v3/orders/1234/refunds
{ "amount": "500.00", "reason": "Partial return", "api_refund": true }
```

- `api_refund: true` calls the gateway; if unsupported it fails without recording.
- Fallback: `api_refund: false`, refund in the gateway dashboard, then record.
- List: `GET /wc/v3/orders/1234/refunds`.
- Partial refunds do not change the order status automatically.

## Create an order

```
POST /wc/v3/orders
{
  "payment_method": "cod",
  "payment_method_title": "Cash on delivery",
  "set_paid": false,
  "billing": { "first_name":"Ali","last_name":"Khan","address_1":"12 MG Road","city":"Mumbai","postcode":"400001","country":"IN","email":"a@example.com","phone":"+919999999999" },
  "shipping": { "first_name":"Ali","last_name":"Khan","address_1":"12 MG Road","city":"Mumbai","postcode":"400001","country":"IN" },
  "line_items": [ { "product_id": 101, "quantity": 2 } ],
  "shipping_lines": [ { "method_id":"flat_rate","method_title":"Flat rate","total":"50" } ]
}
```

`set_paid: true` only when money was collected; it reduces stock and marks paid.

## Fulfilment workflow

1. Filter `processing`.
2. Verify address and phone (fraud check for high-value orders).
3. Create the shipment with the carrier.
4. Add a customer note with tracking.
5. Set `completed` when delivered, or leave `processing` until delivery if that matches policy.
6. Refund/return path documented.

Bulk status change:

```
POST /wc/v3/orders/batch
{ "update": [ { "id": 1, "status": "completed" }, { "id": 2, "status": "completed" } ] }
```

Batch does **not** send all emails in some versions; verify one order after a batch.

## Reports vs orders

- Counts: `GET /wc/v3/reports/orders/totals`
- Revenue: `GET /wc/v3/reports/sales?date_min=...&date_max=...`
- Top sellers: `GET /wc/v3/reports/top_sellers`
- Exact arithmetic: page orders by date/status and sum `total` yourself; reports are aggregated and cached.

## Customer communication

- Order notes are internal by default; set `customer_note: true` for messages.
- Refund reasons appear in the email only if included.
- Do not put personal data in internal notes beyond what operations need.

## Gotchas

- Order `id` ≠ `number` when a numbering plugin is active.
- `force=true` delete bypasses trash and can orphan refunds.
- Cancelling does not restock unless "restock cancelled orders" is enabled.
- `date_created` must be ISO 8601; timezone mistakes shift orders by hours.
- HPOS + incompatible plugin can drop meta silently.
- Status change to `completed` triggers the review request email only if the store enables it.
- Editing an order after a refund can desync totals from the gateway; re-check before capturing more.
