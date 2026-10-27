---
title: Analytics, Consent & Privacy
category: Growth
summary: Implement GA4/GTM and marketing pixels lawfully — consent mode v2, cookie banners, data minimization, GDPR/DPDP requests, and verification that events actually fire.
updated: 2026-10-26
---

# Analytics, Consent & Privacy

Two goals that must both hold: you can measure the funnel, and you only collect data you are allowed to collect.

## Consent mode v2 (EEA/UK)

Google requires two additional signals for ad measurement:

| Parameter | Controls |
| --- | --- |
| `ad_user_data` | sending user data to Google for ads |
| `ad_personalization` | personalised advertising / remarketing |

Plus the existing `analytics_storage`, `ad_storage`, `functionality_storage`, `personalization_storage`, `security_storage`.

- Use a Google-certified CMP; it updates consent mode automatically.
- A hand-rolled banner must call `gtag('consent','update', {...})` on choice.
- Do not simply block Google tags until consent: Google then cannot model consent, and you lose conversion data.
- Default state must be set **before** the tag loads (`consent default`), usually `denied` for EEA.

## What to install

| Layer | Tool |
| --- | --- |
| Tag management | Google Tag Manager (preferred) |
| Analytics | GA4 |
| Ads | Google Ads, Meta Pixel/CAPI |
| Consent | CMP (CookieYes, Complianz, Borlabs, OneTrust) |
| Server-side | GTM server container (optional) |

WordPress plugins that inject tags directly (Site Kit, MonsterInsights, pixel plugins) stack duplicate tags. Audit before adding another.

## Verify tags

```
wpmcp/fetch-page { "url": "https://qadrimarket.com/" }
```

Look for `googletagmanager.com/gtag/js`, `GTM-XXXX`, `G-XXXX`, duplicate `fbq('init'`.

Better: use GTM Preview / GA4 DebugView with the site open. Check:

1. `page_view` fires once, with the correct `page_location`.
2. `purchase` fires once on the thank-you page, with `transaction_id`, `value`, `currency`, `items[]`.
3. No `purchase` on refresh (dedupe by `transaction_id`).
4. Consent state matches the banner choice.

## WooCommerce purchase event

```
wp eval '
$order = wc_get_order( 1234 );
echo json_encode( array(
  "id" => $order->get_id(),
  "total" => $order->get_total(),
  "currency" => $order->get_currency(),
  "items" => count( $order->get_items() ),
  "status" => $order->get_status(),
) );
'
```

The `purchase` event belongs on the order-received page, not on checkout submit (which can fire before payment succeeds). For redirect gateways, use the thank-you page and server-side confirmation.

## Privacy: what the site must have

- Privacy policy page (WordPress → Settings → Privacy).
- Cookie banner with categories: necessary, analytics, marketing, preferences.
- Consent logged with timestamp + version (CMP handles it).
- Data retention limits for orders, logs, analytics.
- DPA with processors (host, payment gateway, email provider, analytics).
- Contact route for access/deletion requests.

India's DPDP Act applies to this store's customers; the EU GDPR applies to EU visitors. The practical baseline is the same: notice, consent, minimization, deletion route.

## WordPress privacy tools

```
wp option get wp_page_for_privacy_policy
wp eval 'echo wp_privacy_anonymize_ip( "203.0.113.7" );'
```

- Settings → Privacy defines the policy page.
- Tools → Export Personal Data / Erase Personal Data handle WordPress user data.
- WooCommerce adds order data to both tools; HPOS orders are included in recent versions.

## Data minimization

- Do not send email/phone to GA4 unless required; if you do, hash it (`sha256`) for enhanced conversions.
- Exclude `/wp-admin`, `/cart`, `/checkout`, `/my-account` from analytics pageviews or mark them.
- Set GA4 data retention to 14 months (max for the free tier).
- Disable IP logging where the CMP allows.

## Consent banner checklist

- [ ] Appears before non-essential scripts run
- [ ] Reject is as easy as accept (no dark patterns)
- [ ] Categories are granular
- [ ] Choice persists and can be changed later
- [ ] No cookies set before consent (except strictly necessary)
- [ ] Google tags receive `consent update`
- [ ] Banner does not cause CLS
- [ ] Works with LiteSpeed cache (banner JS not deferred past tag load)

## Verification commands

```
wpmcp/fetch-page { "url": "https://qadrimarket.com/" }
```

Check for pre-consent requests to `google-analytics.com`, `googletagmanager.com`, `facebook.net`. A request before consent is a compliance defect, not a performance one.

## Gotchas

- LiteSpeed "delay JS" can delay the CMP itself, causing tags to fire before consent.
- A CMP that reloads the page after choice double-counts pageviews unless configured.
- GA4 `purchase` duplicated by both the plugin and GTM is the most common ecommerce tracking bug.
- Consent mode does not exempt you from needing a banner; it only models behavior.
- Ad blockers reduce analytics numbers; do not "fix" the gap with fabricated events.
- Caching a personalised banner per user defeats page cache — serve it client-side.
