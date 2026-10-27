---
title: WooCommerce Payments, Shipping & Tax
category: WooCommerce
summary: Configure gateways, shipping zones and tax correctly — including India GST and EU VAT basics — and diagnose the three errors that block checkout most often.
updated: 2026-10-26
---

# WooCommerce Payments, Shipping & Tax

## Payment gateways

```
GET /wc/v3/payment_gateways
PUT /wc/v3/payment_gateways/razorpay
{ "enabled": true, "settings": { "title": "UPI / Cards", "description": "Pay securely" } }
```

Common gateways: Razorpay, PayU, Cashfree, Stripe, PayPal, COD.

Rules:
- Test mode for setup, live mode after verification. Never leave test mode on production.
- One primary method above the fold; alternatives below.
- COD only where you can absorb RTO risk.
- Enable webhooks so order status updates when the customer never returns to the site.
- Keep gateway plugins updated; they are a common breach vector.

## Webhooks and return URLs

A payment is confirmed by webhook, not by the browser redirect.

- Verify the signature header (gateway-specific).
- Make the handler idempotent: check `transaction_id` before changing status.
- Log the raw payload (redact card data) for disputes.
- If webhooks fail, orders stay `pending` while money is captured — reconcile daily.

## Shipping zones

```
GET /wc/v3/shipping/zones
GET /wc/v3/shipping/zones/{id}/locations
GET /wc/v3/shipping/zones/{id}/methods
POST /wc/v3/shipping/zones/{id}/methods
{ "method_id": "flat_rate", "settings": { "title": "Standard", "cost": "50" } }
```

| Method | Use |
| --- | --- |
| `flat_rate` | fixed cost per zone |
| `free_shipping` | threshold or coupon |
| `local_pickup` | store pickup |
| `distance_rate`/plugin | courier integrations |

Every sellable destination needs a matching zone. "No shipping options available" is a zone/location mismatch 90% of the time.

## Shipping classes

Use classes for bulky/heavy items, then set per-class costs in each zone. A product without a class uses the zone default.

## Tax configuration

```
wp option get woocommerce_calc_taxes
wp option get woocommerce_prices_include_tax
wp option get woocommerce_tax_based_on
wp wc tax list --user=1
wp wc tax class list --user=1
```

Decisions:
- Prices inclusive or exclusive of tax. Changing this after orders exist confuses customers and reports.
- Calculate based on shipping address, billing address, or store address.
- Round at subtotal or per line (`woocommerce_tax_round_at_subtotal`).
- Display prices including or excluding tax in cart/checkout.

## India (GST) specifics

- Intra-state: CGST + SGST; inter-state: IGST. WooCommerce core has no automatic state split — use a GST plugin or configure rates per state.
- HSN codes are not core fields; store them in product meta and print on invoices.
- Prices are usually GST-inclusive for consumers; set `woocommerce_prices_include_tax=yes`.
- B2B orders may need GSTIN; capture it as an additional checkout field (see `woocommerce-checkout-and-cart`).
- Reverse charge, e-invoicing and TCS depend on turnover; those are accounting integrations, not core settings.

## EU VAT basics

- Charge VAT based on the customer's country (destination), not the store's.
- B2B reverse charge requires a validated VAT ID.
- OSS registration for cross-border B2C above the threshold.
- Digital goods: VAT at the customer's location from the first sale.
- Use a tax service for rates; hard-coded rates go stale.

## Coupons and tax

- A coupon reduces the taxable base; a fee may add to it.
- "Free shipping" coupons interact with zone thresholds — test both.
- Tax-exempt customers (B2B) need a customer-level exemption, not a 0% coupon.

## Reconciliation

Daily:
1. Compare gateway settlements with WooCommerce orders for the previous day.
2. Any captured payment without a `processing`/`completed` order → webhook failure.
3. Any paid order with no shipment after 48h → operations issue.
4. Refunds recorded in WooCommerce vs gateway dashboard.

## Diagnosis table

| Error | Cause | Fix |
| --- | --- | --- |
| No shipping options | zone/location mismatch | add zone or restrict countries |
| Payment method not showing | disabled for country/currency | gateway settings |
| Tax not applied | `calc_taxes` off or no rate | enable + rate for the address |
| Wrong total at gateway | fee/tax hook after totals | recalculate before payment |
| Order stuck pending | webhook not received | check gateway log + endpoint |
| Double charge | retry without idempotency | use transaction ID guard |

## Gotchas

- Changing the store address recalculates future tax, not historical orders.
- Free shipping thresholds compare against subtotal including or excluding tax depending on settings.
- COD + no stock reservation = overselling; consider holding stock for pending orders.
- A gateway plugin update can change webhook paths; re-verify after updates.
- Shipping label plugins may require the store address and phone to be complete.
- Tax classes must be assigned per product; a missing class falls back to standard.
