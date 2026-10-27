---
title: Email Deliverability
category: Operations
summary: Get WordPress and WooCommerce email into inboxes — SMTP, SPF/DKIM/DMARC, From addresses, transactional vs marketing separation, and bounce diagnosis.
updated: 2026-10-26
---

# Email Deliverability

WordPress sends mail with PHP `mail()` by default, which lands in spam or nowhere. A store must send through an authenticated SMTP/API provider on its own domain.

## Current requirements (2026)

- **All senders**: SPF or DKIM.
- **Bulk senders** (≈5,000+/day to Gmail): SPF **and** DKIM **and** DMARC, plus one-click unsubscribe and spam-rate below 0.3%.
- DKIM key ≥1024-bit; 2048 recommended.
- The `From:` domain must align with the authenticating domain for DMARC to pass.
- Yahoo/Google both enforce these; a store sending order confirmations from a `gmail.com` address fails alignment.

## Check what the site uses

```
wp plugin list --status=active --fields=name --format=json
wp option get woocommerce_email_from_address
wp option get woocommerce_email_from_name
wp option get admin_email
```

Look for an SMTP plugin (WP Mail SMTP, FluentSMTP, Post SMTP) or a host-level mailer. If none, the site is on `mail()`.

## Send a test

```
wp eval '
$ok = wp_mail( "test@example.com", "QM test " . date( "H:i" ), "If you got this, wp_mail works." );
echo $ok ? "queued" : "failed";
'
```

`true` only means PHP handed it off — not that it was delivered. Check the recipient inbox and spam, then the provider's activity log.

## DNS records

| Record | Example | Purpose |
| --- | --- | --- |
| SPF | `v=spf1 include:_spf.provider.com ~all` | authorises sending IPs |
| DKIM | `selector._domainkey` TXT | cryptographic signature |
| DMARC | `v=DMARC1; p=quarantine; rua=mailto:dmarc@domain.com; pct=100` | policy + reports |
| MX | provider | inbound mail |

Rules:
- One SPF record per domain. Multiple SPF records = automatic fail.
- Start DMARC at `p=none` with reporting, move to `quarantine`, then `reject`.
- Subdomains used for marketing should have their own DMARC alignment or be included in the policy (`sp=`).

Verify from the outside:

```
dig +short TXT example.com | grep -i spf
dig +short TXT default._domainkey.example.com
dig +short TXT _dmarc.example.com
```

## WordPress configuration

```php
define( 'WPMS_ON', true ); // if the SMTP plugin supports constants
```

Typical SMTP constants (provider-specific):

```php
define( 'WPMS_SMTP_HOST', 'smtp.provider.com' );
define( 'WPMS_SMTP_PORT', 587 );
define( 'WPMS_SSL', 'tls' );
define( 'WPMS_SMTP_AUTH', true );
define( 'WPMS_SMTP_USER', 'user@example.com' );
define( 'WPMS_SMTP_PASS', '...' ); // store in wp-config, not in a plugin UI
```

Set `From` to a domain address: `orders@example.com`, `no-reply@example.com`. Never `wordpress@localhost` or a free mailbox.

## Transactional vs marketing

- Order, shipping, password reset, account emails: transactional provider, own subdomain (`mail.` or `orders.`), no marketing content.
- Newsletters: separate provider/subdomain (`news.`), unsubscribe link, List-Unsubscribe header.
- Mixing them damages the transactional reputation when a campaign gets marked as spam.

## WooCommerce email settings

```
wp option get woocommerce_email_from_address
wp option get woocommerce_email_header_image
wp option get woocommerce_email_footer_text
wp option get woocommerce_custom_orders_table_enabled
```

Order emails are triggered by status transitions. A missing "processing" email is usually:

1. Status never changed to `processing` (payment gateway webhook failed).
2. Email disabled in WooCommerce → Settings → Emails.
3. SMTP failure (check the provider log).
4. Customer email empty or invalid.

```
wp eval '
$order = wc_get_order( 1234 );
if ( ! $order ) { echo "no order"; exit; }
echo $order->get_status() . " | " . $order->get_billing_email();
'
```

Resend manually:

```
wp eval '
$order = wc_get_order( 1234 );
WC()->mailer()->get_emails()["WC_Email_Customer_Processing_Order"]->trigger( $order->get_id(), $order );
echo "triggered";
'
```

## Bounce and spam diagnosis

| Signal | Meaning | Fix |
| --- | --- | --- |
| Accepted, not in inbox | spam filtering or silent drop | check DMARC, content, reputation |
| `550 5.7.1` | policy rejection | SPF/DKIM/DMARC alignment |
| `421` / `451` | temporary throttling | rate limit, retry |
| High bounce rate | stale list | clean list, double opt-in |
| Gmail "unauthenticated" | missing alignment | authenticate the From domain |

## Gotchas

- Changing the `From` address does not change the envelope sender; SPF checks the envelope.
- A staging site sending with production credentials can get the domain throttled — disable email on staging.
- Password reset emails from a `no-reply` address generate support tickets; use a monitored mailbox.
- HTML email with a single huge image and no text triggers spam filters.
- DMARC `p=reject` before all legitimate senders are aligned will silently kill order email.
