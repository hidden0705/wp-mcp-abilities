---
title: WooCommerce Emails & Notifications
category: WooCommerce
summary: Make order emails arrive and look right — status triggers, templates, From address, SMTP, multilingual, and resending a missing email.
updated: 2026-10-26
---

# WooCommerce Emails & Notifications

## Email types

| Email | Trigger |
| --- | --- |
| New order | admin, on order creation |
| Processing order | customer, status → processing |
| Completed order | customer, status → completed |
| On-hold | customer, status → on-hold |
| Cancelled / Failed / Refunded | customer, status change |
| Customer invoice | manual or auto |
| Customer note | when a customer note is added |
| Reset password / New account | WordPress |

Enable/disable per email under WooCommerce → Settings → Emails.

## Inspect settings

```
wp option get woocommerce_email_from_name
wp option get woocommerce_email_from_address
wp option get woocommerce_email_header_image
wp option get woocommerce_email_footer_text
wp option get woocommerce_email_base_color
wp option get woocommerce_email_background_color
```

`From` must be a domain address (`orders@example.com`). A Gmail/Hotmail From address fails DMARC alignment and lands in spam.

## Templates

WooCommerce templates live in `plugins/woocommerce/templates/emails/`. Override in the child theme:

```
wp-content/themes/oceanwp-child-theme-master/woocommerce/emails/customer-processing-order.php
```

When WooCommerce updates, compare overrides against the plugin's current templates (WooCommerce → Status → Templates flags outdated ones).

Never edit the plugin copies.

## Send a test

```
wp eval '
$ok = wp_mail( "test@example.com", "Woo test", "Body" );
echo $ok ? "queued" : "failed";
'
```

If this fails, the problem is WordPress/SMTP, not WooCommerce. See `email-deliverability`.

## Resend an order email

```
wp eval '
$order = wc_get_order( 1234 );
if ( ! $order ) { echo "no order"; exit; }
$mails = WC()->mailer()->get_emails();
if ( isset( $mails["WC_Email_Customer_Processing_Order"] ) ) {
    $mails["WC_Email_Customer_Processing_Order"]->trigger( $order->get_id(), $order );
    echo "triggered";
}
'
```

Class names: `WC_Email_New_Order`, `WC_Email_Customer_Processing_Order`, `WC_Email_Customer_Completed_Order`, `WC_Email_Customer_On_Hold_Order`, `WC_Email_Customer_Refunded_Order`, `WC_Email_Customer_Invoice`, `WC_Email_Customer_Note`.

## Why an email did not arrive

1. Was the status actually changed? (A pending order sends no processing email.)
2. Is the email enabled?
3. Is the customer email valid?
4. Did `wp_mail` succeed? (SMTP log)
5. Is it in spam? (authentication)
6. Is a plugin intercepting `woocommerce_email_*` hooks?

```
wp eval '
$order = wc_get_order( 1234 );
echo $order ? $order->get_status() . " | " . $order->get_billing_email() : "missing";
'
```

## Multilingual stores

- Each email must respect the order's locale, not the admin's.
- WPML/Polylang translate templates and subject lines.
- A customer who ordered in Hindi should not receive English.
- Test by placing orders in each language.

## Transactional vs marketing

- Order emails are transactional; they must not contain unsubscribe links or campaign content.
- Newsletters go through a separate provider and subdomain.
- Mixing them damages deliverability for order confirmations.

## Content that reduces spam score

- Plain-text alternative (WooCommerce sends multipart by default).
- Real order information, not "click here".
- A monitored reply-to address.
- No shortened links or image-only emails.
- Consistent From name/address across all transactional mail.

## Design and accessibility

- Logo ≤ 200px wide, hosted on the site domain.
- Contrast-safe text; no text baked into images.
- Order table readable at 320px width.
- Buttons ≥ 44px tall with descriptive labels.
- Footer with store address and contact.

## Gotchas

- Changing the base color or header image does not regenerate already-sent emails.
- A staging site with production SMTP can email real customers; disable sending on staging.
- "New order" admin emails go to `woocommerce_new_order_email_recipient`; changing the admin email does not automatically change this.
- Some gateways mark an order paid without triggering the processing email if the status was already processing.
- HPOS does not change email behaviour, but plugins reading `$order->post` do break.
