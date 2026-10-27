---
title: WooCommerce Fraud & Disputes
category: WooCommerce
summary: Reduce card testing, fake orders and chargebacks — layered rules, manual review thresholds, evidence collection, and what to do when a dispute arrives.
updated: 2026-10-26
---

# WooCommerce Fraud & Disputes

## Threat model for an Indian store

- **Card testing**: thousands of small failed payments to validate stolen cards. Shows up as failed-order spikes and gateway fees.
- **COD abuse / RTO**: fake orders, refused deliveries, high return-to-origin cost.
- **Triangulation**: stolen card + real address, reshipping.
- **Coupon/account abuse**: multiple accounts for first-order discounts.
- **Friendly fraud**: genuine purchase, later chargeback.

## Layered controls

1. **Gateway-side**: 3D Secure/OTP, AVS/CVV where supported, risk rules.
2. **Store-side**: velocity limits, minimum order value, block disposable emails.
3. **Manual review**: high-value, high-risk country, mismatched address.
4. **Fulfilment**: verify phone/address for COD above a threshold.
5. **Monitoring**: failed payment rate, refund rate, dispute rate.

## Velocity rules

```php
add_action( 'woocommerce_checkout_process', function () {
    $email = sanitize_email( $_POST['billing_email'] ?? '' );
    if ( ! $email ) { return; }
    $key   = 'qadri_attempts_' . md5( $email . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $count = (int) get_transient( $key );
    if ( $count >= 5 ) {
        wc_add_notice( 'Too many attempts. Please try again later or contact us.', 'error' );
    }
    set_transient( $key, $count + 1, HOUR_IN_SECONDS );
} );
```

Rate limits must not block legitimate retries after a declined card — 5/hour is a reasonable ceiling.

## Manual review triggers

Hold an order (`on-hold`) when any of:

- Total > a threshold (e.g. ₹10,000).
- Billing country differs from shipping and IP country.
- Email domain is disposable.
- More than 2 orders from the same IP in an hour.
- COD order with an unverifiable phone.

Do not auto-cancel on a single signal; queue for review.

## Card testing response

Symptoms: hundreds of `failed` orders, small amounts, sequential emails, one IP range.

1. Enable CAPTCHA on checkout.
2. Raise the minimum order value temporarily.
3. Block the IP range at the WAF.
4. Ask the gateway to enable their fraud rules.
5. Delete the junk orders (`force=true`) so reports stay clean.

## COD risk

- Verify phone via OTP for orders above a threshold.
- Restrict COD by pincode/state where RTO is high.
- Require prepaid for first-time customers above a value.
- Track RTO rate per courier and per pincode.

## Chargeback / dispute playbook

When a dispute arrives:

1. **Respond within the network's deadline** (often 7–20 days).
2. Provide: order details, IP, device, delivery proof, tracking with signature, communication, refund policy, terms accepted at checkout.
3. Match the evidence to the reason code (fraud vs product not received vs not as described).
4. Do not refund outside the dispute process if you intend to fight it — that can complicate the case.
5. Record the outcome and the customer for future risk rules.

## Evidence to keep per order

- IP address and user agent at checkout.
- Billing/shipping addresses and phone.
- Gateway transaction ID and 3DS result.
- Delivery tracking and signature.
- All customer emails/notes.
- Terms and refund policy version accepted.

WooCommerce stores some of this; a fraud plugin or gateway dashboard stores the rest. Do not rely on memory.

## Refund policy vs fraud

- A clear, visible return policy reduces "not as described" disputes.
- Respond to pre-dispute customer messages fast; most chargebacks start as an ignored email.
- Refund small legitimate complaints quickly; fighting a ₹500 dispute costs more.

## Metrics to watch

| Metric | Healthy |
| --- | --- |
| Failed payment rate | < 5% |
| Refund rate | < 5% |
| Dispute rate | < 0.5% |
| COD RTO | < 15% |
| Manual review queue age | < 24h |

## Gotchas

- Blocking a country entirely can kill legitimate sales; restrict by risk signal instead.
- CAPTCHA on checkout reduces conversion; use invisible/Turnstile.
- Deleting fraudulent orders removes the evidence you may need for a dispute.
- A "fraud plugin" that auto-cancels on IP alone produces false positives on mobile networks.
- 3DS shifts liability but does not eliminate friendly fraud.
